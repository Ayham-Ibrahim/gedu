<?php

namespace App\Models;

use App\Observers\CourseObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Course Model
 *
 * Maps to: courses table
 * Standalone short courses (not full degree programs).
 *
 * @property int    $id
 * @property string $name
 * @property string $name_ar
 * @property string $category     e.g. "Language" | "Tech" | "Business"
 * @property bool   $online
 * @property string $city         (if onsite)
 * @property string $price
 * @property string $description
 * @property string $duration
 * @property string $schedule
 * @property string $provider
 */
#[ObservedBy([CourseObserver::class])]
class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_ar',
        'category',
        'online',
        'city',
        'price',
        'description',
        'duration',
        'schedule',
        'provider',
        'is_active',
    ];

    protected $casts = [
        'online'    => 'boolean',
        'is_active' => 'boolean',
    ];
}
