<?php

namespace App\Services;

use App\Models\University;
use App\Models\Program;
use App\Models\Course;
use App\Models\SupportMember;
use Illuminate\Support\Facades\Log;

/**
 * KnowledgeBuilderService
 *
 * This is the heart of the RAG system.
 *
 * It reads structured data from MySQL tables and converts each record into
 * a human-readable text "chunk", embeds it, and stores it in Qdrant.
 *
 * Why text chunks instead of raw JSON?
 *   Embedding models work best on natural language. Converting
 *   { "name": "Oxford", "mode": "Online" } into
 *   "University: Oxford International. Program: MBA. Study Mode: Online."
 *   produces far better semantic matches.
 *
 * Chunk ID strategy:
 *   We use deterministic IDs based on source type + record ID so we can
 *   upsert (update) specific records without rebuilding everything.
 *   Format: {type}_{id}  e.g. "university_5", "program_12"
 *   Since Qdrant needs integer IDs, we hash these to integers.
 */
class KnowledgeBuilderService
{
    public function __construct(
        private readonly EmbeddingService $embedder,
        private readonly QdrantService    $qdrant,
    ) {}

    // ─── Full Rebuild ──────────────────────────────────────────────────────────

    /**
     * Rebuild the entire knowledge base from scratch.
     * Called by: php artisan knowledge:build
     */
    public function rebuildAll(): array
    {
        Log::info('[Knowledge] Starting full rebuild...');

        // Ensure collection exists with correct dimensions
        $this->qdrant->recreateCollection($this->embedder->getDimension());

        $stats = [
            'universities'   => 0,
            'programs'       => 0,
            'courses'        => 0,
            'support_members'=> 0,
            'errors'         => 0,
        ];

        // Process each data source
        $stats['universities']    = $this->indexUniversities();
        $stats['programs']        = $this->indexPrograms();
        $stats['courses']         = $this->indexCourses();
        $stats['support_members'] = $this->indexSupportTeam();

        Log::info('[Knowledge] Full rebuild complete.', $stats);
        return $stats;
    }

    // ─── Per-Record Sync (called from Model observers) ─────────────────────────

    /**
     * Sync a single university record to Qdrant.
     * Called automatically when a university is saved/updated.
     */
    public function syncUniversity(University $university): bool
    {
        try {
            $chunk = $this->buildUniversityChunk($university);
            $vector = $this->embedder->embed($chunk['text']);
            return $this->qdrant->upsertPoint($chunk['id'], $vector, $chunk['payload']);
        } catch (\Throwable $e) {
            Log::error('[Knowledge] syncUniversity failed', ['id' => $university->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function syncProgram(Program $program): bool
    {
        try {
            $chunk = $this->buildProgramChunk($program);
            $vector = $this->embedder->embed($chunk['text']);
            return $this->qdrant->upsertPoint($chunk['id'], $vector, $chunk['payload']);
        } catch (\Throwable $e) {
            Log::error('[Knowledge] syncProgram failed', ['id' => $program->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function syncCourse(Course $course): bool
    {
        try {
            $chunk = $this->buildCourseChunk($course);
            $vector = $this->embedder->embed($chunk['text']);
            return $this->qdrant->upsertPoint($chunk['id'], $vector, $chunk['payload']);
        } catch (\Throwable $e) {
            Log::error('[Knowledge] syncCourse failed', ['id' => $course->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function syncSupportMember(SupportMember $member): bool
    {
        try {
            $chunk = $this->buildSupportChunk($member);
            $vector = $this->embedder->embed($chunk['text']);
            return $this->qdrant->upsertPoint($chunk['id'], $vector, $chunk['payload']);
        } catch (\Throwable $e) {
            Log::error('[Knowledge] syncSupportMember failed', ['id' => $member->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function deleteUniversity(int $id): void
    {
        $this->qdrant->deletePoint($this->chunkId('university', $id));
    }

    public function deleteProgram(int $id): void
    {
        $this->qdrant->deletePoint($this->chunkId('program', $id));
    }

    public function deleteCourse(int $id): void
    {
        $this->qdrant->deletePoint($this->chunkId('course', $id));
    }

    public function deleteSupportMember(int $id): void
    {
        $this->qdrant->deletePoint($this->chunkId('support', $id));
    }

    // ─── Bulk Indexing ─────────────────────────────────────────────────────────

    private function indexUniversities(): int
    {
        $count = 0;
        University::with('programs')->chunk(50, function ($universities) use (&$count) {
            $chunks = $universities->map(fn($u) => $this->buildUniversityChunk($u))->toArray();
            $texts  = array_column($chunks, 'text');
            $vectors = $this->embedder->embedBatch($texts);

            $points = array_map(fn($chunk, $vector) => [
                'id'      => $chunk['id'],
                'vector'  => $vector,
                'payload' => $chunk['payload'],
            ], $chunks, $vectors);

            $this->qdrant->upsertPoints($points);
            $count += count($points);
        });
        Log::info("[Knowledge] Indexed {$count} universities.");
        return $count;
    }

    private function indexPrograms(): int
    {
        $count = 0;
        Program::with('university')->chunk(100, function ($programs) use (&$count) {
            $chunks  = $programs->map(fn($p) => $this->buildProgramChunk($p))->toArray();
            $texts   = array_column($chunks, 'text');
            $vectors = $this->embedder->embedBatch($texts);

            $points = array_map(fn($chunk, $vector) => [
                'id'      => $chunk['id'],
                'vector'  => $vector,
                'payload' => $chunk['payload'],
            ], $chunks, $vectors);

            $this->qdrant->upsertPoints($points);
            $count += count($points);
        });
        Log::info("[Knowledge] Indexed {$count} programs.");
        return $count;
    }

    private function indexCourses(): int
    {
        $count = 0;
        Course::chunk(100, function ($courses) use (&$count) {
            $chunks  = $courses->map(fn($c) => $this->buildCourseChunk($c))->toArray();
            $texts   = array_column($chunks, 'text');
            $vectors = $this->embedder->embedBatch($texts);

            $points = array_map(fn($chunk, $vector) => [
                'id'      => $chunk['id'],
                'vector'  => $vector,
                'payload' => $chunk['payload'],
            ], $chunks, $vectors);

            $this->qdrant->upsertPoints($points);
            $count += count($points);
        });
        Log::info("[Knowledge] Indexed {$count} courses.");
        return $count;
    }

    private function indexSupportTeam(): int
    {
        $count = 0;
        SupportMember::chunk(50, function ($members) use (&$count) {
            $chunks  = $members->map(fn($m) => $this->buildSupportChunk($m))->toArray();
            $texts   = array_column($chunks, 'text');
            $vectors = $this->embedder->embedBatch($texts);

            $points = array_map(fn($chunk, $vector) => [
                'id'      => $chunk['id'],
                'vector'  => $vector,
                'payload' => $chunk['payload'],
            ], $chunks, $vectors);

            $this->qdrant->upsertPoints($points);
            $count += count($points);
        });
        Log::info("[Knowledge] Indexed {$count} support members.");
        return $count;
    }

    // ─── Chunk Builders ────────────────────────────────────────────────────────
    // Each builder returns: ['id' => int, 'text' => string, 'payload' => array]

    private function buildUniversityChunk(University $university): array
    {
        $programs = $university->programs ?? collect();
        $programNames = $programs->pluck('name')->implode(', ');

        $text = implode("\n", array_filter([
            "University: {$university->name}",
            $university->name_ar ? "University (Arabic): {$university->name_ar}" : null,
            "Country: {$university->country}",
            $university->city ? "City: {$university->city}" : null,
            $university->website ? "Website: {$university->website}" : null,
            $university->description ? "About: {$university->description}" : null,
            $programNames ? "Available Programs: {$programNames}" : null,
            $university->admission_requirements ? "Admission Requirements: {$university->admission_requirements}" : null,
            $university->established_year ? "Established: {$university->established_year}" : null,
            $university->accreditation ? "Accreditation: {$university->accreditation}" : null,
        ]));

        return [
            'id'      => $this->chunkId('university', $university->id),
            'text'    => $text,
            'payload' => [
                'source_type'    => 'university',
                'source_id'      => $university->id,
                'text'           => $text,
                'university_name'=> $university->name,
                'country'        => $university->country,
                'updated_at'     => $university->updated_at?->toISOString(),
            ],
        ];
    }

    private function buildProgramChunk(Program $program): array
    {
        $universityName = $program->university?->name ?? 'Unknown University';

        $text = implode("\n", array_filter([
            "University: {$universityName}",
            "Program: {$program->name}",
            $program->name_ar ? "Program (Arabic): {$program->name_ar}" : null,
            "Degree: {$program->degree}",
            "Study Mode: {$program->mode}",     // Online / Onsite / Hybrid
            $program->duration ? "Duration: {$program->duration}" : null,
            $program->fees ? "Fees: {$program->fees}" : null,
            $program->description ? "Description: {$program->description}" : null,
            $program->language ? "Language: {$program->language}" : null,
            $program->start_date ? "Start Date: {$program->start_date}" : null,
            $program->admission_requirements ? "Admission Requirements: {$program->admission_requirements}" : null,
        ]));

        return [
            'id'      => $this->chunkId('program', $program->id),
            'text'    => $text,
            'payload' => [
                'source_type'      => 'program',
                'source_id'        => $program->id,
                'text'             => $text,
                'university_name'  => $universityName,
                'university_id'    => $program->university_id,
                'program_name'     => $program->name,
                'degree'           => $program->degree,
                'mode'             => $program->mode,
                'updated_at'       => $program->updated_at?->toISOString(),
            ],
        ];
    }

    private function buildCourseChunk(Course $course): array
    {
        $text = implode("\n", array_filter([
            "Course: {$course->name}",
            $course->name_ar ? "Course (Arabic): {$course->name_ar}" : null,
            "Category: {$course->category}",
            "Format: " . ($course->online ? 'Online' : 'Onsite'),
            $course->city ? "City: {$course->city}" : null,
            $course->price ? "Price: {$course->price}" : null,
            $course->description ? "Description: {$course->description}" : null,
            $course->duration ? "Duration: {$course->duration}" : null,
            $course->schedule ? "Schedule: {$course->schedule}" : null,
            $course->provider ? "Provider: {$course->provider}" : null,
        ]));

        return [
            'id'      => $this->chunkId('course', $course->id),
            'text'    => $text,
            'payload' => [
                'source_type' => 'course',
                'source_id'   => $course->id,
                'text'        => $text,
                'course_name' => $course->name,
                'category'    => $course->category,
                'online'      => (bool) $course->online,
                'updated_at'  => $course->updated_at?->toISOString(),
            ],
        ];
    }

    private function buildSupportChunk(SupportMember $member): array
    {
        $text = implode("\n", array_filter([
            "Support Team Member: {$member->name}",
            $member->name_ar ? "Name (Arabic): {$member->name_ar}" : null,
            "Department: {$member->department}",
            $member->phone ? "Phone: {$member->phone}" : null,
            $member->email ? "Email: {$member->email}" : null,
            $member->whatsapp ? "WhatsApp: {$member->whatsapp}" : null,
            $member->specialty ? "Specialty: {$member->specialty}" : null,
            $member->availability ? "Available: {$member->availability}" : null,
        ]));

        return [
            'id'      => $this->chunkId('support', $member->id),
            'text'    => $text,
            'payload' => [
                'source_type' => 'support_team',
                'source_id'   => $member->id,
                'text'        => $text,
                'name'        => $member->name,
                'department'  => $member->department,
                'phone'       => $member->phone,
                'updated_at'  => $member->updated_at?->toISOString(),
            ],
        ];
    }

    // ─── ID Helper ─────────────────────────────────────────────────────────────

    /**
     * Generate a stable integer ID for Qdrant from a type + DB id.
     * Qdrant requires positive integers or UUIDs for point IDs.
     *
     * We use a simple namespace multiplier so IDs never collide across types:
     *   university_1 → 1_000_001
     *   program_1    → 2_000_001
     *   course_1     → 3_000_001
     *   support_1    → 4_000_001
     */
    private function chunkId(string $type, int $recordId): int
    {
        $namespace = match ($type) {
            'university' => 1,
            'program'    => 2,
            'course'     => 3,
            'support'    => 4,
            default      => 9,
        };
        return ($namespace * 1_000_000) + $recordId;
    }
}
