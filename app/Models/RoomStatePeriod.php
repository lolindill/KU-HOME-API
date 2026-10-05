<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🗓️ RoomStatePeriod — "ห้องสำรอง" / "ซ่อมแซม" เป็นช่วงเวลาแบบ booking
 *
 *    (wayfinder/room-state-periods 2026-09-24 — แทน rooms.is_reserved flag และสถานะ maintenance
 *     ที่ถูกถอดออกจาก machine · single source of truth = ตารางนี้ · derived ตอน query ไม่มี sweep)
 *
 *    - kind: reserved | maintenance
 *    - start_date ย้อนอดีตได้ · end_date nullable (เปิดปลาย — ทั้งสอง kind) และเป็น
 *      **exclusive** เหมือน check_out ("ถึงวันที่ 30" = คืน 29 คืนสุดท้ายที่ห้องหาย)
 *    - นิยาม active:  start_date <= today AND (end_date IS NULL OR end_date > today)
 *    - นิยาม overlap กับช่วง [from, to): start_date < to AND (end_date IS NULL OR end_date > from)
 *      (half-open เดียวกับ booking_rooms — scope ชุดเดียวด้านล่าง ห้ามเขียนเงื่อนไขเอง
 *       เหมือนธรรมเนียม BookingRoom::scopeHoldingSlot())
 *    - same-kind overlap ถูก auto-merge ที่ service — invariant: ใน DB ไม่มี same-kind ทับกันเอง
 *
 *    Audit: ทุกเหตุการณ์ (สร้าง/merge/ขยาย/หด/ลบ) เขียน status_change_logs
 *    entity_type 'room_state_period' — from/to เก็บช่วงวันที่ string "YYYY-MM-DD..YYYY-MM-DD|NULL"
 */
class RoomStatePeriod extends Model
{
    use HasUuids;

    public const KIND_RESERVED = 'reserved';

    public const KIND_MAINTENANCE = 'maintenance';

    // 📊 (05/10/26) excel-reports spec §2.5 — repair log (ใช้เฉพาะ kind=maintenance · ตามชีต truth)
    public const WORK_TYPE_ELECTRICAL = 'ไฟฟ้า';

    public const WORK_TYPE_PLUMBING = 'ประปา';

    public const WORK_TYPE_SYSTEM = 'งานระบบ';

    public const WORK_TYPES = [self::WORK_TYPE_ELECTRICAL, self::WORK_TYPE_PLUMBING, self::WORK_TYPE_SYSTEM];

    protected $fillable = [
        'room_id',
        'kind',
        'start_date',
        'end_date',
        'created_by',
        // 📊 (05/10/26) repair log — วันแจ้งซ่อม = start_date · เสร็จ = end_date · duration derive
        'work_type',
        'repair_detail',
    ];

    // 'date:Y-m-d' — wire format ของ period เป็น YYYY-MM-DD ล้วน (ต่างจาก ISO datetime ของ
    // check_in/check_out ที่เป็น datetime column — วันที่ของ period ไม่มีเวลาเป็นองค์ประกอบ)
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * ช่วงวันที่ของ period เป็น string — ใช้เป็น from_status/to_status ใน audit log
     * เปิดปลาย = "YYYY-MM-DD..NULL"
     */
    public function windowString(): string
    {
        return $this->start_date->toDateString().'..'.($this->end_date?->toDateString() ?? 'NULL');
    }

    /**
     * Period ที่ overlap ช่วง [from, to) — half-open เดียวกับ booking_rooms
     *
     * $to = null หมายถึงช่วงปลายเปิด (เช่น period ใหม่เปิดปลาย — overlap กับ row ไหนก็ได้
     * ที่ยังไม่จบก่อน $from)
     */
    public function scopeOverlapping($query, $from, $to, ?string $kind = null)
    {
        return $query
            ->when($to !== null, fn ($q) => $q->where('start_date', '<', $to))
            ->where(function ($q) use ($from) {
                $q->whereNull('end_date')->orWhere('end_date', '>', $from);
            })
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind));
    }

    /**
     * Period ที่ active ณ วันที่กำหนด (default วันนี้) — สำหรับ display/badge
     */
    public function scopeActiveOn($query, $date = null)
    {
        $day = $date !== null ? Carbon::parse($date)->startOfDay() : Carbon::today();

        return $query->where('start_date', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('end_date')->orWhere('end_date', '>', $day);
            });
    }
}
