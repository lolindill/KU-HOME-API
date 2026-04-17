<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    use HasFactory, HasUuids;

    // ปลดล็อกให้เซฟข้อมูลได้ทุกฟิลด์
    protected $guarded = []; 

    // เพิ่ม Casts ให้ข้อมูลพวกวันที่และสถานะพร้อมใช้เสมอ
    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'is_ku_member' => 'boolean',
        'total_amount' => 'integer',
        'is_paid' => 'boolean',
        'payment_deadline' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addon(): HasOne
    {
        return $this->hasOne(Addon::class);
    }
    
    // 🌟 เปลี่ยนเป็น HasMany และใช้ชื่อ bookingRooms ให้ตรงกับใน Controller ค่ะ
    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }
}