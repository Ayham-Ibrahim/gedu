<?php

namespace App\Observers;

use App\Models\Program;
use App\Services\KnowledgeBuilderService;
use Illuminate\Support\Facades\Log;

class ProgramObserver
{
    public function __construct(private readonly KnowledgeBuilderService $builder) {}

    public function saved(Program $program): void
    {
        Log::info("[Observer] Program #{$program->id} saved → syncing.");
        $program->load('university');
        $this->builder->syncProgram($program);
    }

    public function deleted(Program $program): void
    {
        Log::info("[Observer] Program #{$program->id} deleted → removing.");
        $this->builder->deleteProgram($program->id);
    }
}
