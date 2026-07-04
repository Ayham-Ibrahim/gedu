<?php

namespace App\Observers;

use App\Models\SupportMember;
use App\Services\KnowledgeBuilderService;
use Illuminate\Support\Facades\Log;

class SupportMemberObserver
{
    public function __construct(private readonly KnowledgeBuilderService $builder) {}

    public function saved(SupportMember $member): void
    {
        Log::info("[Observer] SupportMember #{$member->id} saved → syncing.");
        $this->builder->syncSupportMember($member);
    }

    public function deleted(SupportMember $member): void
    {
        Log::info("[Observer] SupportMember #{$member->id} deleted → removing.");
        $this->builder->deleteSupportMember($member->id);
    }
}
