<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 📊 (05/10/26) excel-reports spec §2.2 — addons reshape (ticket 10)
 *
 *    เดิม breakfast/extra_bed เก็บ int เดียวทั้ง stay — reshape ให้รองรับ:
 *    - breakfast แยก 2 ชุด: breakfast_set_100 + breakfast_set_200 (คิดเงิน × คืน)
 *    - extra_bed เป็นรายคืน: extra_beds_by_night JSON เช่น {"2026-09-11": 1, "2026-09-12": 2}
 *      · คีย์ต้องเป็นคืนใน [check_in, check_out) (validate ฝั่ง BookingController)
 *      · qty ต่อคืน ≤ room_types.max_extra_beds
 *
 *    ⚠️ คำนวณยอดเงินจาก canonical fields เสมอ — snapshot *_price เขียนโดย
 *    DiscountService::reprice() (chokepoint เดียว) อย่าอ่านมาคิดใหม่
 */
class Addon extends Model
{
    use HasFactory, HasUuids;

    /**
     * ✅ #27 Fixed: เปลี่ยนจาก $guarded = [] เป็น $fillable
     * ป้องกัน mass assignment vulnerability
     */
    protected $fillable = [
        'booking_room_id',
        'breakfast_set_100',
        'breakfast_set_200',
        'extra_beds_by_night',
        'early_checkIn_price',
        'early_hours',
        'late_checkOut_price',
        'late_hours',
        'extra_bed_price',
        'breakfast_price',
    ];

    protected $casts = [
        'breakfast_set_100' => 'integer',
        'breakfast_set_200' => 'integer',
        'extra_beds_by_night' => 'array',
        'early_checkIn_price' => 'integer',
        'early_hours' => 'integer',
        'late_checkOut_price' => 'integer',
        'late_hours' => 'integer',
        'extra_bed_price' => 'integer',
        'breakfast_price' => 'integer',
    ];

    // 📊 derived ที่ response/รายงานอ่าน (input format = output format — แทน column extra_bed เดิม)
    protected $appends = ['extra_beds_max', 'extra_beds_total'];

    public function bookingRoom(): BelongsTo
    {
        return $this->belongsTo(BookingRoom::class);
    }

    // ----------------------------------------------------------------------
    // Derived helpers (รายงาน + RoomAllocator อ่าน — ไม่มี column)
    // ----------------------------------------------------------------------

    /**
     * 🛏️ จำนวนเตียงเสริม "สูงสุดต่อคืน" — ใช้จับคู่ห้องที่มี builtin เตียงพอ
     * (RoomAllocator / assign ordering — แทน column extra_bed int เดิม)
     */
    public function getExtraBedsMaxAttribute(): int
    {
        $byNight = $this->extra_beds_by_night ?? [];

        return is_array($byNight) && $byNight !== [] ? (int) max($byNight) : 0;
    }

    /**
     * 🛏️ จำนวนเตียงเสริมรวมทั้ง stay (สำหรับรายงานที่ต้องการยอดรวม)
     */
    public function getExtraBedsTotalAttribute(): int
    {
        $byNight = $this->extra_beds_by_night ?? [];

        return is_array($byNight) ? (int) array_sum($byNight) : 0;
    }
}
