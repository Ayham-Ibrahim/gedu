<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Program;
use App\Models\SupportMember;
use App\Models\University;
use App\Services\KnowledgeBuilderService;
use App\Services\QdrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * KnowledgeController
 *
 * Admin API endpoints for managing the RAG knowledge base.
 * All routes require the admin token (see AdminTokenMiddleware).
 *
 * Routes (all under /api/admin/):
 *   POST /api/admin/knowledge/rebuild          – Full rebuild from DB
 *   POST /api/admin/knowledge/sync/university/{id}
 *   POST /api/admin/knowledge/sync/program/{id}
 *   POST /api/admin/knowledge/sync/course/{id}
 *   POST /api/admin/knowledge/sync/support/{id}
 *   GET  /api/admin/knowledge/stats            – Collection stats
 */
class KnowledgeController extends Controller
{
    public function __construct(
        private readonly KnowledgeBuilderService $builder,
        private readonly QdrantService           $qdrant,
    ) {}

    /**
     * POST /api/admin/knowledge/rebuild
     * Full rebuild: re-embeds everything from MySQL into Qdrant.
     * Can take a few minutes depending on record count and API limits.
     */
    public function rebuild(): JsonResponse
    {
        Log::info('[Admin] Knowledge rebuild triggered via API.');

        try {
            $stats = $this->builder->rebuildAll();
            return response()->json([
                'success' => true,
                'message' => 'Knowledge base rebuilt successfully.',
                'stats'   => $stats,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Admin] Rebuild failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/knowledge/sync/university/{id}
     * Manually sync a single university (useful for testing).
     * Normally this is called automatically by the model observer.
     */
    public function syncUniversity(int $id): JsonResponse
    {
        $university = University::with('programs')->findOrFail($id);
        $success = $this->builder->syncUniversity($university);

        return response()->json([
            'success' => $success,
            'message' => $success ? "University #{$id} synced to Qdrant." : "Sync failed.",
        ]);
    }

    public function syncProgram(int $id): JsonResponse
    {
        $program = Program::with('university')->findOrFail($id);
        $success = $this->builder->syncProgram($program);

        return response()->json([
            'success' => $success,
            'message' => $success ? "Program #{$id} synced to Qdrant." : "Sync failed.",
        ]);
    }

    public function syncCourse(int $id): JsonResponse
    {
        $course = Course::findOrFail($id);
        $success = $this->builder->syncCourse($course);

        return response()->json([
            'success' => $success,
            'message' => $success ? "Course #{$id} synced to Qdrant." : "Sync failed.",
        ]);
    }

    public function syncSupport(int $id): JsonResponse
    {
        $member = SupportMember::findOrFail($id);
        $success = $this->builder->syncSupportMember($member);

        return response()->json([
            'success' => $success,
            'message' => $success ? "Support member #{$id} synced to Qdrant." : "Sync failed.",
        ]);
    }

    /**
     * GET /api/admin/knowledge/stats
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'qdrant_points'    => $this->qdrant->getPointCount(),
            'universities_db'  => University::count(),
            'programs_db'      => Program::count(),
            'courses_db'       => Course::count(),
            'support_members_db' => SupportMember::count(),
            'qdrant_health'    => $this->qdrant->healthCheck(),
        ]);
    }
}
