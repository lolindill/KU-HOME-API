<?php

namespace App\Models;

use App\Casts\PgBoolean;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code',
        'type',
        'value',
        'room_type_ids',
        'usable_from',
        'usable_until',
        'stay_from',
        'stay_until',
        'max_uses',
        'max_uses_per_user',
        'is_active',
    ];

    protected $casts = [
        'value' => 'integer',
        'max_uses' => 'integer',
        'max_uses_per_user' => 'integer',
        'room_type_ids' => 'array',
        'usable_from' => 'datetime',
        'usable_until' => 'datetime',
        'stay_from' => 'date',
        'stay_until' => 'date',
        'is_active' => PgBoolean::class, // 🔒 PostgreSQL strict boolean
    ];

    /**
     * Normalize discount code to trimmed uppercase
     */
    public function setCodeAttribute($value): void
    {
        $this->attributes['code'] = strtoupper(trim((string) $value));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }
}
