<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support importing knowledge straight from the website export
 * (php artisan knowledge:import):
 *   - external_id: stable id from the frontend data (e.g. "study:eg1"),
 *     so re-imports update records instead of duplicating them.
 *   - universities.tuition: the tuition range shown on the website.
 *   - site_pages: website text (FAQ, services, visa, living costs, contact…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('id');
            $table->string('tuition')->nullable()->after('city');
        });

        Schema::table('support_members', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('id');
        });

        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // e.g. "faq:1", "country:egypt"
            $table->string('section');                // faq | services | country | contact | about …
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->longText('content_ar')->nullable();
            $table->longText('content_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('section');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_pages');

        Schema::table('support_members', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['external_id', 'tuition']);
        });
    }
};
