<?php

namespace App\Support;

/**
 * 💵 REQ-015/016 — ปัดเศษขึ้นหลักสิบ (round up to nearest 10)
 *
 * กติกาเดียวทั้งระบบ (wayfinder/booking-payment-types ticket 07, owner 2026-09-25):
 * ยอดเงินลูกค้าไม่มีทศนิยม มีเศษให้ปัดขึ้นหลักสิบ
 *
 * ใช้ 2 จุดเท่านั้น:
 *   - DiscountService::reprice() — ปัดที่ booking_rooms.amount หลัง (เรท×คืน) − ส่วนลด + addon
 *     (ยอดรวม total_amount = Σ ยอดที่ปัดแล้ว — invariant Σ booking_rooms.amount == total_amount)
 *   - Booking::getDepositAmountAttribute() — มัดจำ default (total × deposit_percent) ปัดสิบต่อ
 *     (deposit_amount ที่ admin ตั้งเอง + ชั้น A confirmations.amount ไม่ force ปัด)
 */
class RoundToTen
{
    public static function round(int $baht): int
    {
        return (int) (ceil($baht / 10) * 10);
    }
}
