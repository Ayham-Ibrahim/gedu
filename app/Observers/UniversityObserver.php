<?php

namespace App\Observers;

use App\Models\University;
use App\Services\KnowledgeBuilderService;
use Illuminate\Support\Facades\Log;

/**
 * UniversityObserver
 *
 * Automatically keeps Qdrant in sync when universities are
 * created, updated, or deleted via the admin panel.
 *
 * No manual PDF upload needed — just save in the admin panel.
 */
class UniversityObserver
{
    public function __construct(private readonly KnowledgeBuilderService $builder) {}

    /** Called after INSERT or UPDATE */
    public function saved(University $university): void
    {
        Log::info("[Observer] University #{$university->id} saved → syncing to Qdrant.");
        // Load programs for a complete chunk
        $university->load('programs');
        $this->builder->syncUniversity($university);
    }

    /** Called after DELETE */
    public function deleted(University $university): void
    {
        Log::info("[Observer] University #{$university->id} deleted → removing from Qdrant.");
        $this->builder->deleteUniversity($university->id);
    }
}
