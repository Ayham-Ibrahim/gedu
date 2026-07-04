<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('category');               // e.g. Language, IT, Business
            $table->boolean('online')->default(false);
            $table->string('city')->nullable();        // if onsite
            $table->string('price')->nullable();
            $table->text('description')->nullable();
            $table->string('duration')->nullable();
            $table->string('schedule')->nullable();
            $table->string('provider')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category');
            $table->index('online');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
