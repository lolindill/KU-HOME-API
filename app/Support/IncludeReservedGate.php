<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * 🏨 Helper กลางของ flag `include_reserved`
 *
 *    🗓️ (24/09/26) room-state-periods: "ห้องสำรอง" = ห้องที่มี **reserved period** (ตาราง
 *    room_state_periods) active/overlap ช่วงที่ขอ — เดิมอ้าง rooms.is_reserved ซึ่งถูก drop แล้ว
 *    (decision ticket 90 ของ map reserved-room-pool ถูก override) · flag นี้ขยาย pool ให้รวม
 *    ห้องติด reserved period ได้ แต่**ไม่เคย**รวม maintenance period (กฎเหล็ก absolute —
 *    กรองที่ call site ผ่าน scopeFreeOfPeriod(..., KIND_MAINTENANCE) เสมอ)
 *
 *    - มีผลเฉพาะผู้ใช้ล็อกอิน role 'admin' และส่ง include_reserved=true (query หรือ body)
 *    - non-admin / anonymous ส่งมา → เมยายีเงียบ ๆ (silently ignored — ห้าม 403)
 *      ประพฤติเหมือนไม่ส่ง flag: response ต้องเหมือนเดิมทุกไบต์
 *    - ค่าที่ไม่ใช่ boolean ถือว่าไม่ได้ส่ง (จึงไม่ validate ที่ endpoint — กัน 422 ละเมิดสัญญา silent)
 *
 *    Single source of truth — availability summary (+king) และ walk-in ใช้ helper นี้
 *    (calendar/createBooking/allocator ยังไม่รับ flag — การขยายเป็นของ map reserved-room-pool ตอน unfreeze)
 */
class IncludeReservedGate
{
    /**
     * ตัดสินว่า flag `include_reserved` มีผลใน request นี้หรือไม่
     */
    public static function enabled(Request $request): bool
    {
        if ($request->user('sanctum')?->role !== 'admin') {
            return false;
        }

        return $request->boolean('include_reserved');
    }
}
