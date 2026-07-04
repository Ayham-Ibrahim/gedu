<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            // Bachelor | Master | PhD | Diploma | Certificate
            $table->string('degree');
            // Online | Onsite | Hybrid
            $table->string('mode')->default('Onsite');
            $table->string('duration')->nullable();       // e.g. "18 Months", "2 Years"
            $table->string('fees')->nullable();           // e.g. "GBP 6,500 / Year"
            $table->string('language')->nullable();       // e.g. "English", "Arabic"
            $table->string('start_date')->nullable();     // e.g. "September 2025"
            $table->text('description')->nullable();
            $table->text('admission_requirements')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['university_id', 'degree']);
            $table->index('mode');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
