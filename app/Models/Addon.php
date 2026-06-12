<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; 
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Addon extends Model
{
    use HasFactory, HasUuids;

    /**
     * ✅ #27 Fixed: เปลี่ยนจาก $guarded = [] เป็น $fillable
     * ป้องกัน mass assignment vulnerability
     */
    protected $fillable = [
        'booking_room_id',
        'extra_bed',
        'breakfast',
        'early_checkIn_price',
        'late_checkOut_price',
        'extra_bed_price',
        'breakfast_price',
    ];

    protected $casts = [
        'extra_bed' => 'integer',
        'breakfast' => 'integer',
        'early_checkIn_price' => 'integer',
        'late_checkOut_price' => 'integer',
        'extra_bed_price' => 'integer',
        'breakfast_price' => 'integer',
    ];

    public function bookingRoom(): BelongsTo
    {
        return $this->belongsTo(BookingRoom::class);
    }
}