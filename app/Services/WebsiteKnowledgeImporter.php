<?php

namespace App\Services;

use App\Models\Program;
use App\Models\SitePage;
use App\Models\SupportMember;
use App\Models\University;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * WebsiteKnowledgeImporter
 *
 * Replaces the chatbot's knowledge data with the website's own content, as
 * exported by the frontend (`npm run export:knowledge`).
 *
 * The website is the source of truth: records that are no longer in the
 * export are deleted, so the chatbot never answers from stale data.
 * Model observers are muted during the import — run a full Qdrant rebuild
 * afterwards (the knowledge:import command does this for you).
 */
class WebsiteKnowledgeImporter
{
    public const SUPPORTED_SCHEMA = 1;

    /**
     * @param  array  $data  Decoded gedu-knowledge.json
     * @return array{universities:int, programs:int, advisors:int, pages:int, removed:int}
     */
    public function import(array $data): array
    {
        $this->validate($data);

        return Model::withoutEvents(fn () => DB::transaction(fn () => $this->run($data)));
    }

    private function run(array $data): array
    {
        $stats = ['universities' => 0, 'programs' => 0, 'advisors' => 0, 'pages' => 0, 'removed' => 0];
        $countries = collect($data['countries'])->keyBy('key');

        // ── Universities + programs ──────────────────────────────────────────
        $keepUniversities = [];

        foreach ($data['countries'] as $country) {
            foreach ($country['universities'] as $u) {
                $isOnline = collect($u['tags'] ?? [])->contains(fn ($t) => str_contains($t, 'أونلاين'));

                $university = $this->upsertUniversity($u['external_id'], [
                    'name'        => $u['name'],
                    'name_ar'     => $u['name_ar'] ?? null,
                    'country'     => $this->countryLabel($country),
                    'city'        => $u['city'] ?? null,
                    'tuition'     => $u['tuition'] ?? null,
                    'description' => $this->universityDescription($u),
                ]);
                $stats['programs'] += $this->replacePrograms($university, $u['programs'] ?? [], $u['tuition'] ?? null, $isOnline ? 'Hybrid' : 'Onsite');
                $keepUniversities[] = $u['external_id'];
                $stats['universities']++;
            }
        }

        foreach ($data['featured_universities'] ?? [] as $u) {
            $country = $countries->get($u['country_key']);
            $isOnline = collect($u['tags'] ?? [])->contains(fn ($t) => str_contains($t, 'أونلاين'));

            $university = $this->upsertUniversity($u['external_id'], [
                'name'        => $u['name'],
                'name_ar'     => $u['name_ar'] ?? null,
                'country'     => $country ? $this->countryLabel($country) : $u['country_key'],
                'city'        => $u['city'] ?? null,
                'tuition'     => null,
                'description' => $this->universityDescription($u),
            ]);
            $stats['programs'] += $this->replacePrograms($university, $u['programs'] ?? [], null, $isOnline ? 'Hybrid' : 'Onsite');
            $keepUniversities[] = $u['external_id'];
            $stats['universities']++;
        }

        // Anything not on the website anymore (incl. old hand-seeded rows) goes.
        $stats['removed'] += University::where(fn ($q) => $q
            ->whereNull('external_id')
            ->orWhereNotIn('external_id', $keepUniversities))
            ->get()
            ->each(fn (University $u) => $u->delete())   // programs cascade
            ->count();

        // ── Advisors → support team ──────────────────────────────────────────
        $keepAdvisors = [];
        foreach ($data['advisors'] ?? [] as $a) {
            $externalId = "advisor:{$a['key']}";
            SupportMember::updateOrCreate(['external_id' => $externalId], [
                'name'         => $a['name']['en'] ?? $a['name']['ar'],
                'name_ar'      => $a['name']['ar'] ?? null,
                'department'   => 'Academic Advisors',
                'phone'        => $a['whatsapp'] ? '+' . ltrim($a['whatsapp'], '+') : null,
                'whatsapp'     => $a['whatsapp'] ? '+' . ltrim($a['whatsapp'], '+') : null,
                'specialty'    => trim(($a['title']['en'] ?? '') . ' — ' . ($a['description']['en'] ?? $a['description']['ar'] ?? ''), ' —'),
                'is_active'    => true,
            ]);
            $keepAdvisors[] = $externalId;
            $stats['advisors']++;
        }
        $stats['removed'] += SupportMember::where(fn ($q) => $q
            ->whereNull('external_id')
            ->orWhereNotIn('external_id', $keepAdvisors))
            ->delete();

        // ── Website pages + per-country visa/living-cost pages ───────────────
        $keepPages = [];
        foreach ($data['pages'] ?? [] as $p) {
            $this->upsertPage($p['key'], $p['section'], $p['title'] ?? [], $p['content'] ?? []);
            $keepPages[] = $p['key'];
            $stats['pages']++;
        }
        foreach ($data['countries'] as $country) {
            // Only countries whose export carries visa / living-cost details get a page.
            if (empty($country['visa']) && empty($country['living_cost'])) {
                continue;
            }
            $key = "country:{$country['key']}";
            $this->upsertPage($key, 'country', [
                'ar' => "الدراسة في {$country['name']['ar']}: التأشيرة وتكاليف المعيشة",
                'en' => "Studying in {$country['name']['en']}: visa & living costs",
            ], ['ar' => $this->countryText($country), 'en' => null]);
            $keepPages[] = $key;
            $stats['pages']++;
        }
        $stats['removed'] += SitePage::whereNotIn('key', $keepPages)->delete();

        return $stats;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function validate(array $data): void
    {
        if (($data['schema'] ?? null) !== self::SUPPORTED_SCHEMA) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported export schema "%s" (expected %d). Re-export with the current frontend script.',
                $data['schema'] ?? 'missing',
                self::SUPPORTED_SCHEMA
            ));
        }

        if (empty($data['countries']) || ! is_array($data['countries'])) {
            throw new \InvalidArgumentException('Export contains no countries — refusing to wipe the knowledge base.');
        }
    }

    private function upsertUniversity(string $externalId, array $attributes): University
    {
        return University::updateOrCreate(
            ['external_id' => $externalId],
            $attributes + ['is_active' => true],
        );
    }

    /** Programs are plain name strings on the website, so replace them wholesale. */
    private function replacePrograms(University $university, array $names, ?string $fees, string $mode): int
    {
        $university->programs()->delete();

        foreach (array_unique($names) as $name) {
            Program::create([
                'university_id' => $university->id,
                'name'          => $name,
                'degree'        => $this->inferDegree($name),
                'mode'          => $mode,
                'fees'          => $fees,
                'is_active'     => true,
            ]);
        }

        return count(array_unique($names));
    }

    private function upsertPage(string $key, string $section, array $title, array $content): void
    {
        SitePage::updateOrCreate(['key' => $key], [
            'section'    => $section,
            'title_ar'   => $title['ar'] ?? null,
            'title_en'   => $title['en'] ?? null,
            'content_ar' => $content['ar'] ?? null,
            'content_en' => $content['en'] ?? null,
            'is_active'  => true,
        ]);
    }

    private function universityDescription(array $u): ?string
    {
        $text = implode("\n", array_filter([
            'GEDULink partner university (listed on the website).',
            $u['tagline'] ?? null,
            $u['description'] ?? null,
            ! empty($u['highlights']) ? 'Highlights: ' . implode('، ', $u['highlights']) : null,
            ! empty($u['tags']) ? 'Tags: ' . implode('، ', $u['tags']) : null,
        ]));

        return $text !== '' ? $text : null;
    }

    private function countryLabel(array $country): string
    {
        return trim(($country['name']['en'] ?? $country['key']) . ' (' . ($country['name']['ar'] ?? '') . ')', ' ()');
    }

    private function countryText(array $country): string
    {
        $visa  = $country['visa'] ?? [];
        $lines = ["Country: {$this->countryLabel($country)}"];

        if (! empty($visa['type'])) {
            $lines[] = "Student visa: {$visa['type']}";
        }
        if (! empty($visa['application_form'])) {
            $lines[] = "Official visa application website: {$visa['application_form']}";
        }
        if (! empty($visa['requirements'])) {
            $lines[] = 'Visa requirements:';
            foreach ($visa['requirements'] as $req) {
                $lines[] = "- {$req}";
            }
        }
        foreach ($visa['embassies'] ?? [] as $e) {
            $lines[] = 'Embassy/consulate: ' . implode(' | ', array_filter([
                $e['name'] ?? null,
                isset($e['phone']) ? "Phone: {$e['phone']}" : null,
                isset($e['email']) ? "Email: {$e['email']}" : null,
                isset($e['address']) ? "Address: {$e['address']}" : null,
            ]));
        }
        if (! empty($country['living_cost'])) {
            $lines[] = 'Estimated monthly living costs:';
            foreach ($country['living_cost'] as $item => $cost) {
                $lines[] = '- ' . ucfirst(str_replace('_', ' ', $item)) . ": {$cost}";
            }
        }

        return implode("\n", $lines);
    }

    /** Website program names are free text, so the degree level is inferred. */
    private function inferDegree(string $programName): string
    {
        $name = mb_strtolower($programName);

        if (preg_match('/\b(phd|doctor|dds|dmd|dba|md)\b/', $name)) {
            return 'Doctorate';
        }
        if (preg_match('/\b(master|mba|emba|msc|m\.s\.)\b|ماجستير/u', $name)) {
            return 'Master';
        }

        return 'Bachelor';
    }
}
