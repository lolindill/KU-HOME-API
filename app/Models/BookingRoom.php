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

    // =========================================================
    // 🌟 New Logic: ฟังก์ชันสำหรับหาห้องว่างและ Assign ให้ตัวเอง
    // =========================================================
    public function assignAvailableRoom(): bool
    {
        // ถ้ามีห้องอยู่แล้ว ไม่ต้องหาใหม่ค่ะ ถือว่าสำเร็จเลย
        if ($this->room_id !== null) {
            return true; 
        }

        // 🌟 ดึง check_in และ check_out จาก Booking หลักผ่าน Relation ได้เลยค่ะ!
        $checkIn = $this->booking->check_in;
        $checkOut = $this->booking->check_out;

        // ดึงจำนวนเตียงเสริมที่ลูกค้าขอ
        $requestedExtraBeds = $this->addon ? $this->addon->extra_bed : 0;

        // ค้นหาห้องว่าง
        $availableRoom = Room::where('room_type_id', $this->room_type_id)
            ->whereDoesntHave('bookingRooms', function ($query) use ($checkIn, $checkOut) {
                $query->whereHas('booking', function ($bQuery) use ($checkIn, $checkOut) {
                    $bQuery->whereIn('status', ['paid', 'confirmed', 'checked_in'])
                           ->where('check_in', '<', $checkOut)
                           ->where('check_out', '>', $checkIn);
                });
            })
            ->whereNotIn('status', ['maintenance', 'reserved_closed'])
            ->orderByRaw('builtin_extra_beds >= ? DESC', [$requestedExtraBeds])
            ->orderBy('builtin_extra_beds', 'ASC')
            ->lockForUpdate() // ล็อก row ป้องกันคนจองชนกันวินาทีเดียวกัน
            ->first();

        // ถ้าเจอห้องว่าง ให้อัปเดตตัวเอง
        if ($availableRoom) {
            $this->update(['room_id' => $availableRoom->id]);
            return true; // แจ้งว่า Assign สำเร็จ
        }

        return false; // แจ้งว่าหาห้องไม่ได้ค่ะ 😭
    }
}