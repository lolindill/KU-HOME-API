<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * 🏨 Helper กลางของ flag `include_reserved` (wayfinder/reserved-room-pool ticket 01)
 *
 *    ห้องสำรอง = rooms.is_reserved (pool membership — ดู ticket 90) — flag นี้ขยาย
 *    pool ให้รวมห้องสำรองได้ แต่**ไม่เคย**รวม maintenance (กฎเหล็ก absolute)
 *
 *    - มีผลเฉพาะผู้ใช้ล็อกอิน role 'admin' และส่ง include_reserved=true (query หรือ body)
 *    - non-admin / anonymous ส่งมา → เมยายีเงียบ ๆ (silently ignored — ห้าม 403)
 *      ประพฤติเหมือนไม่ส่ง flag: response ต้องเหมือนเดิมทุกไบต์
 *    - ค่าที่ไม่ใช่ boolean ถือว่าไม่ได้ส่ง (จึงไม่ validate ที่ endpoint — กัน 422 ละเมิดสัญญา silent)
 *
 *    Single source of truth — tickets 02–05 (calendar/booking/allocator/walk-in) ต้อง reuse helper นี้
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
