<?php

namespace App\Models;

use App\Observers\ProgramObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Program Model
 *
 * Maps to: programs table
 * Belongs to a University.
 * Auto-syncs to Qdrant on save/delete.
 *
 * @property int    $id
 * @property int    $university_id
 * @property string $name           e.g. "MBA"
 * @property string $name_ar        e.g. "ماجستير إدارة الأعمال"
 * @property string $degree         e.g. "Master" | "Bachelor" | "PhD"
 * @property string $mode           e.g. "Online" | "Onsite" | "Hybrid"
 * @property string $duration       e.g. "18 Months"
 * @property string $fees           e.g. "GBP 6,500"
 * @property string $language       e.g. "English" | "Arabic" | "Bilingual"
 * @property string $start_date
 * @property string $description
 * @property string $admission_requirements
 */
#[ObservedBy([ProgramObserver::class])]
class Program extends Model
{
    use HasFactory;

    protected $fillable = [
        'university_id',
        'name',
        'name_ar',
        'degree',
        'mode',
        'duration',
        'fees',
        'language',
        'start_date',
        'description',
        'admission_requirements',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function university(): BelongsTo
    {
        return $this->belongsTo(University::class);
    }
}
