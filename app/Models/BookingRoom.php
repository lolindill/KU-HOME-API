<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BookingRoom extends Model
{
    use HasFactory, HasUuids;

    // ปลดล็อกให้เซฟข้อมูลได้ทุกฟิลด์
    protected $guarded = [];

    // 🌟 1. เชื่อมกลับไปหาข้อมูล Booking หลัก
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    // 🌟 2. เชื่อมไปหาประเภทห้องพักที่ลูกค้าเลือกตอนจอง
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    // 🌟 3. เชื่อมไปหาห้องพักจริงๆ (เป็น Nullable เพราะรอ Assign เลขห้องตอนที่ลูกค้ามาเช็คอิน)
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function addon(): HasOne
    {
        return $this->hasOne(Addon::class);
    }
}