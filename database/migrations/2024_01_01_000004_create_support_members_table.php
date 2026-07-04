<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('department');          // Admissions | Finance | Visa | General
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('specialty')->nullable(); // What they handle specifically
            $table->string('availability')->nullable(); // e.g. "Sun-Thu 9am-5pm"
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('department');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_members');
    }
};
