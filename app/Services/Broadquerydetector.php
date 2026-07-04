<?php

namespace App\Services;

use App\Models\University;
use App\Models\Program;

/**
 * BroadQueryDetector
 *
 * Detects "list everything" style questions (e.g. "what countries can I study in?",
 * "give me all universities") that a top-K vector search cannot answer completely,
 * since it only retrieves a handful of chunks.
 *
 * For these questions, we bypass Qdrant entirely and build the context directly
 * from the database — guaranteeing a complete, accurate list every time.
 */
class BroadQueryDetector
{
    /**
     * Keywords (Arabic + English) that signal the user wants an exhaustive list
     * rather than a specific, narrow answer.
     */
    private const BROAD_PATTERNS = [
        // Arabic
        'جميع الجامعات', 'كل الجامعات', 'كل الدول', 'جميع الدول',
        'كل البرامج', 'جميع البرامج', 'كل التخصصات', 'جميع التخصصات',
        'ما هي الدول', 'ما هي الجامعات', 'اي دول', 'اي جامعات',
        'كل الدول المتاحة', 'الدول المتاحة', 'الجامعات المتاحة',
        'اعطني جميع', 'اعطني كل', 'أعطني جميع', 'أعطني كل',
        'قائمة بكل', 'قائمة بجميع', 'كافة الجامعات', 'كافة الدول',

        // English
        'all universities', 'all countries', 'all programs', 'all majors',
        'every university', 'every country', 'every program',
        'list of universities', 'list of countries', 'list all',
        'which countries', 'what countries', 'which universities',
        'what universities', 'available countries', 'available universities',
        'full list',
    ];

    /**
     * Returns true if the user's message looks like a broad/exhaustive list request.
     */
    public function isBroadQuery(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        foreach (self::BROAD_PATTERNS as $pattern) {
            if (mb_strpos($normalized, mb_strtolower($pattern)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a complete context block directly from the database.
     * Returns an array shaped like Qdrant search results so it can be passed
     * straight into the chat service's generateAnswer() the same way.
     *
     * @return array<int, array{text: string, source_type: string, _score: float}>
     */
    public function buildFullContext(): array
    {
        $universities = University::with(['programs' => function ($q) {
            $q->where('is_active', true);
        }])
            ->where('is_active', true)
            ->orderBy('country')
            ->orderBy('name')
            ->get();

        if ($universities->isEmpty()) {
            return [];
        }

        // Group by country for a clean, complete summary
        $byCountry = $universities->groupBy('country');

        $chunks = [];

        foreach ($byCountry as $country => $unis) {
            $text = "Country: {$country}\n";

            foreach ($unis as $uni) {
                $text .= "- University: {$uni->name}";
                if ($uni->city) {
                    $text .= " (City: {$uni->city})";
                }
                $text .= "\n";

                $programNames = $uni->programs->map(function ($p) {
                    return $p->name . ($p->degree ? " [{$p->degree}]" : '');
                })->implode(', ');

                if ($programNames) {
                    $text .= "  Programs: {$programNames}\n";
                }
            }

            $chunks[] = [
                'text'        => $text,
                'source_type' => 'full_directory',
                '_score'      => 1.0, // Treat as maximally relevant — it IS the full answer
            ];
        }

        return $chunks;
    }
}