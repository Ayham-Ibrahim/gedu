<?php

namespace App\Models;

use App\Observers\SupportMemberObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SupportMember Model
 *
 * Maps to: support_members table
 * The human support team — users can ask "who do I contact for X?"
 *
 * @property int    $id
 * @property string $name
 * @property string $name_ar
 * @property string $department  e.g. "Admissions" | "Finance" | "Visa"
 * @property string $phone
 * @property string $email
 * @property string $whatsapp
 * @property string $specialty
 * @property string $availability e.g. "Sun–Thu 9am–5pm"
 */
#[ObservedBy([SupportMemberObserver::class])]
class SupportMember extends Model
{
    use HasFactory;

    protected $table = 'support_members';

    protected $fillable = [
        'name',
        'name_ar',
        'department',
        'phone',
        'email',
        'whatsapp',
        'specialty',
        'availability',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
