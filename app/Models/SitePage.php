<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SitePage Model
 *
 * Website text imported by `php artisan knowledge:import` (FAQ, services,
 * visa requirements, living costs, contact details …). Indexed into Qdrant
 * by KnowledgeBuilderService alongside universities and programs.
 *
 * @property int    $id
 * @property string $key
 * @property string $section
 * @property string $title_ar
 * @property string $title_en
 * @property string $content_ar
 * @property string $content_en
 */
class SitePage extends Model
{
    protected $fillable = [
        'key',
        'section',
        'title_ar',
        'title_en',
        'content_ar',
        'content_en',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
