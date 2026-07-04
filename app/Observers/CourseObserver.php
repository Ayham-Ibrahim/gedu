<?php

namespace App\Observers;

use App\Models\Course;
use App\Services\KnowledgeBuilderService;
use Illuminate\Support\Facades\Log;

class CourseObserver
{
    public function __construct(private readonly KnowledgeBuilderService $builder) {}

    public function saved(Course $course): void
    {
        Log::info("[Observer] Course #{$course->id} saved → syncing.");
        $this->builder->syncCourse($course);
    }

    public function deleted(Course $course): void
    {
        Log::info("[Observer] Course #{$course->id} deleted → removing.");
        $this->builder->deleteCourse($course->id);
    }
}
