<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HousekeepingTask extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    // 🌟 Fix L1 (03/07/26): missing casts — checked_out_at / completed_at ใช้เป็น Carbon
    protected $casts = [
        'checked_out_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // 🌟 Fix L2 (03/07/26): inverse relationships ที่หายไป
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(HousekeepingPhoto::class, 'task_id');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(HousekeepingInventory::class, 'task_id');
    }
}
