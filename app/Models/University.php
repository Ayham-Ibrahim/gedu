<?php

namespace App\Models;

use App\Observers\UniversityObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * University Model
 *
 * Maps to: universities table
 * Auto-syncs to Qdrant via UniversityObserver on save/delete.
 *
 * @property int    $id
 * @property string $name
 * @property string $name_ar
 * @property string $country
 * @property string $city
 * @property string $website
 * @property string $description
 * @property string $admission_requirements
 * @property int    $established_year
 * @property string $accreditation
 */
#[ObservedBy([UniversityObserver::class])]
class University extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_ar',
        'country',
        'city',
        'website',
        'description',
        'admission_requirements',
        'established_year',
        'accreditation',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
