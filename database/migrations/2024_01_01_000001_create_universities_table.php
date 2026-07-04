<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ─────────────────────────────────────────────────────────────────────────────
// Migration 1: universities
// ─────────────────────────────────────────────────────────────────────────────
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('universities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('country');
            $table->string('city')->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->text('admission_requirements')->nullable();
            $table->unsignedSmallInteger('established_year')->nullable();
            $table->string('accreditation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('country');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universities');
    }
};
