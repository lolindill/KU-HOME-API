<?php

namespace App\Services\Discount;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\GlobalRate;
use App\Services\Addon\AddonPricing;
use App\Support\BookingRule;
use App\Support\RoundToTen;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class DiscountService
{
    /**
     * 🎟️ ใส่ / สลับโค้ดส่วนลดให้กับการจองสถานะ draft
     *
     * @throws Exception
     */
    public function applyToDraft(Booking $booking, string $code): Booking
    {
        if ($booking->status !== 'draft') {
            throw new Exception('ใส่หรือลบโค้ดได้เฉพาะการจองสถานะ draft เท่านั้นค่ะ 📝', 422);
        }

        return DB::transaction(function () use ($booking, $code) {
            // 1) 🤝 กุม lock แถว discount ก่อนอ่านอะไร (ทุก apply ต่อคิว — กัน oversell)
            //    SQLite ไม่รองรับ FOR UPDATE → skip เหมือน pattern RoomAllocator
            $query = Discount::where('code', strtoupper(trim($code)));
            if (config('database.default') !== 'sqlite' && DB::transactionLevel() > 0) {
                $query->lockForUpdate();
            }

            $discount = $query->first();
            if (! $discount) {
                throw new Exception("ไม่พบโค้ดส่วนลด {$code} ในระบบค่ะ 🔎", 422);
            }

            if (! $discount->is_active) {
                throw new Exception("โค้ด {$discount->code} ถูกปิดใช้งานแล้วค่ะ 🙅♀️", 422);
            }

            $now = Carbon::now();
            if ($discount->usable_from && $now->lt($discount->usable_from)) {
                throw new Exception("โค้ด {$discount->code} ยังไม่ถึงช่วงเวลาใช้งานค่ะ (ใช้ได้ตั้งแต่ {$discount->usable_from->format('Y-m-d H:i')})", 422);
            }

            if ($discount->usable_until && $now->gt($discount->usable_until)) {
                throw new Exception("โค้ด {$discount->code} หมดเขตใช้งานแล้วค่ะ (ใช้ได้ถึง {$discount->usable_until->format('Y-m-d H:i')})", 422);
            }

            // 2) 🎯 หาห้อง eligible ของ booking นี้
            $rooms = $booking->bookingRooms()->with('roomType')->get();
            $eligible = $rooms->filter(fn ($br) => $this->isEligible($discount, $br));

            if ($eligible->isEmpty()) {
                throw new Exception("ไม่มีห้องในการจองนี้ที่ใช้โค้ด {$discount->code} ได้ค่ะ 🏨", 422);
            }

            $k = $eligible->count();

            // 3) 🔄 ปล่อยของเดิมก่อน (swap) — ทำใน tx เดียวกัน count จึงเห็นของตัวเองคืนแล้ว
            DiscountRedemption::whereIn('booking_room_id', $rooms->pluck('id'))->delete();

            // 4) 🧮 นับ quota (held + used รวมกัน — ทั้งสองแบบกิน pool)
            $globalUsed = DiscountRedemption::where('discount_id', $discount->id)->count();
            $userUsed = DiscountRedemption::where('discount_id', $discount->id)
                ->where('user_id', $booking->user_id)
                ->count();

            if ($discount->max_uses !== null) {
                if ($globalUsed >= $discount->max_uses) {
                    throw new Exception("โค้ด {$discount->code} ถูกใช้ครบโควตาแล้วค่ะ", 422);
                }
                if ($globalUsed + $k > $discount->max_uses) {
                    $remaining = $discount->max_uses - $globalUsed;
                    throw new Exception("โค้ด {$discount->code} เหลืออีก {$remaining} slot เท่านั้น (ต้องการ {$k} slot) ค่ะ", 422);
                }
            }

            if ($discount->max_uses_per_user !== null && $userUsed + $k > $discount->max_uses_per_user) {
                throw new Exception("นายท่านใช้โค้ด {$discount->code} ครบโควตาต่อคนแล้วค่ะ ({$discount->max_uses_per_user} ห้อง)", 422);
            }

            // 5) 📝 จับ slot ใหม่ K แถว (user_id = เจ้าของ booking ไม่ใช่คนกดปุ่ม)
            foreach ($eligible as $br) {
                DiscountRedemption::create([
                    'discount_id' => $discount->id,
                    'booking_room_id' => $br->id,
                    'user_id' => $booking->user_id,
                    'status' => 'held',
                ]);
            }

            // 6) ✅ Belt-and-suspenders: re-count หลัง insert ใน tx เดียวกัน — เกิน max = throw = rollback
            if ($discount->max_uses !== null &&
                DiscountRedemption::where('discount_id', $discount->id)->count() > $discount->max_uses) {
                throw new Exception('โควตาโค้ดส่วนลดเกินกำหนด — ยกเลิกการใช้โค้ดค่ะ', 422);
            }

            // 7) 💾 snapshot ชื่อโค้ด + reprice ทั้ง booking
            $booking->discount_code = $discount->code;
            $booking->save();

            return $this->reprice($booking);
        });
    }

    /**
     * ตรวจสอบว่าห้องนี้เข้าเกณฑ์ส่วนลดหรือไม่ (room type targeting + stay date window)
     */
    public function isEligible(Discount $d, BookingRoom $br): bool
    {
        $targetingOk = $d->room_type_ids === null || in_array($br->room_type_id, $d->room_type_ids);

        $checkIn = Carbon::parse($br->check_in);
        $checkOut = Carbon::parse($br->check_out);

        $stayOk = ($d->stay_from === null && $d->stay_until === null)
            || ($d->stay_from !== null && $d->stay_until !== null
                && $checkIn->gte($d->stay_from) && $checkOut->lte($d->stay_until));

        return $targetingOk && $stayOk;
    }

    /**
     * คำนวณส่วนลดสำหรับ 1 ห้อง (เฉพาะค่าห้อง ไม่รวม addon)
     *
     * $rate = เรทต่อคืนอ้างอิง · $roomBase = ยอดค่าห้องจริงของห้องนี้ (🌙 เขตรายเดือน
     * คิดแบบ prorate แล้ว — intdiv(x × month, 30)) · ไม่ส่ง $roomBase = $rate × $nights ตามเดิม
     *
     * 🌙 (22/10/26, srs-v2-gap-closure ticket 08 edge 2): set_room_price ชนะเด็ดขาดข้ามสูตร
     *    รายเดือน — net = set_price × x ตรง ๆ (แต่ไม่ขยับราคาขึ้นเกินยอดค่าห้องเดิม)
     */
    public function computeForRoom(Discount $d, int $rate, int $nights, ?int $roomBase = null): int
    {
        $roomBase ??= $rate * $nights;

        if ($d->type === 'percent') {
            $amt = intdiv($roomBase * $d->value, 100);

            return min($amt, $roomBase);
        }

        if ($d->type === 'fixed') {
            return min($d->value, $roomBase);
        }

        if ($d->type === 'set_room_price') {
            return max($roomBase - min($d->value * $nights, $roomBase), 0);
        }

        return 0;
    }

    /**
     * คำนวณยอดเงินใหม่ทั้งใบของการจอง (room_amount, discount_amount, amount, total_amount)
     * 🧾 (03/09/26) chokepoint เดียวที่เขียน `amount` (net ต่อห้อง) — walk-in ก็เรียก method นี้
     * 📊 (05/10/26, excel-reports ticket 10) addon คิดจาก canonical fields ผ่าน AddonPricing —
     *    breakfast × คืน (แก้ undercharge เดิม) · extra-bed รายคืน · snapshot ทุกตัวเขียนที่นี่
     *
     * 🌙 (22/10/26, srs-v2-gap-closure ticket 09 — มติ owner 2026-10-22) pricing 2 เส้นทาง:
     *    ตัดสินจาก **role ผู้ล็อกอิน (sanctum)** ของ write ที่เรียก method นี้ (ตรงกับ guard
     *    422 ใน Form Requests — ห้ามใช้ field 'source'):
     *    - non-admin: daily เส้นตรงเฉพาะ x < block_min_nights (20) — ห้อง x ≥ 20 โดน 422 ที่
     *      request layer ก่อนถึงจุดนี้ (ku_member → daily_ku ตามเรท effective)
     *    - admin: ชั้นจำนวนห้อง (นับ booking_rooms ทั้งบิล — ≥10 → group min_10 · ≥5 → min_5
     *      · ต่ำกว่า → เรทรายวัน effective ของเจ้าของบิล) ใช้กับบิลสั้น (< 20 คืน) และเป็นเรท
     *      fallback เมื่อไม่มีเรท month · ชั้นจำนวนคืน: ห้อง x ≥ 20 → คิดแบบรายเดือน
     *      `intdiv(x × month_rate, 30)` = เดือนเต็ม (30 วัน) + เศษคิดรายวันแบบเดือน (month÷30)
     *      ตามเอกสารหลักเกณฑ์ของโรงแรม (เช่น Deluxe month 18,000 → 45 คืน = 18,000 + 15×600
     *      = 27,000) · สูตรนี้ monotonic ทุกคืน — ไม่มี cliff (supersede กฎ b=floor(y/20)
     *      ของ amendment 2026-10-20)
     */
    public function reprice(Booking $booking): Booking
    {
        $discount = $booking->discount_code ? Discount::where('code', $booking->discount_code)->first() : null;
        $holds = DiscountRedemption::whereIn('booking_room_id', $booking->bookingRooms()->pluck('id'))->get();
        $user = $booking->user;
        // 📊 เรท addon อ่านครั้งเดียวต่อ booking — pricing source = global_rates เท่านั้น
        $rates = GlobalRate::getPrices(['breakfast_100', 'breakfast_200', 'extra_bed', 'early_checkin', 'late_checkout']);
        // 🌙 pricing 2 เส้นทาง — เขตรายเดือน/group เป็น pricing ของเส้นทาง admin:
        //    actor เป็น admin หรือ **บิลที่เกิดจากเส้นทาง admin** (source='admin' หรือ userless/org)
        //    ใช้เส้นทาง admin เสมอ — กันเจ้าของ non-admin แก้ addon ทีหลังแล้วราคาเด้งขึ้นเงียบ ๆ
        //    (spoof 'source'='admin' ไม่มีประโยชน์: 422 guard คืน/จำนวนห้องตัดสินจาก sanctum role
        //    เสมอ — ส่วนลด daily_ku หาย = โดนแพงขึ้นเอง)
        $isAdminPath = BookingRule::isAdmin()
            || $booking->source === 'admin'
            || $booking->user_id === null;
        $monthlyGate = (int) config('booking.block_min_nights', 20);
        $billRoomCount = $booking->bookingRooms()->count();
        $total = 0;

        foreach ($booking->bookingRooms()->with(['addon', 'roomType'])->get() as $br) {
            // 🕒 (25/09/26) REQ-015/016 — normalize เป็นคืนเต็มเสมอ: startOfDay() ทั้งสองก่อน diffInDays()
            //    (Carbon 3 คืน float เมื่อ input เป็น datetime เช่น 1.5 คืน — จองครึ่งคืนไม่มีในโดเมนนี้)
            $nights = (int) Carbon::parse($br->check_in)->startOfDay()
                ->diffInDays(Carbon::parse($br->check_out)->startOfDay()) ?: 1;

            // 🌙 เขตรายเดือน (admin + x ≥ gate + มีเรท month) — ไม่เข้าเงื่อนไข = เรทรายวัน
            //    (ไม่มีเรท month → fallback daily เส้นตรงตามมติ owner 2026-10-22)
            $monthRate = GlobalRate::getRoomRate($br->roomType, 'month');
            $isMonthly = $isAdminPath && $nights >= $monthlyGate && $monthRate > 0;

            if ($isMonthly) {
                // เดือนเต็ม + เศษคิดรายวันแบบเดือน (month÷30) — intdiv(x × month, 30) คิดตรง
                // กันพอดี (a×month + intdiv(c×month, 30)) โดยไม่เกิดเศษเพนนี
                $roomAmount = intdiv($nights * $monthRate, 30);
                $rate = intdiv($roomAmount, $nights); // เรทเฉลี่ย/คืน — อ้างอิงฐานส่วนลด
            } else {
                $rate = GlobalRate::getEffectiveDailyRate($br->roomType, $user);

                // ชั้นจำนวนห้อง (admin เท่านั้น) — group ทับ daily/daily_ku ทั้งบิลสั้นและ
                // เคส fallback รายเดือนที่ไม่มีเรท month · org booking (userless) → daily
                if ($isAdminPath) {
                    $groupCode = $billRoomCount >= 10 ? 'min_10_rooms' : ($billRoomCount >= 5 ? 'min_5_rooms' : null);
                    if ($groupCode !== null) {
                        $groupRate = GlobalRate::getRoomRate($br->roomType, 'group', $groupCode);
                        if ($groupRate > 0) {
                            $rate = $groupRate;
                        }
                    }
                }

                $roomAmount = $rate * $nights;
            }

            $isHeld = $holds->contains('booking_room_id', $br->id);
            $discountAmount = ($discount && $isHeld && $this->isEligible($discount, $br))
                ? $this->computeForRoom($discount, $rate, $nights, $roomAmount)
                : 0;

            // 📊 addon totals จาก canonical fields (สูตรอยู่ที่ AddonPricing จุดเดียว)
            $addon = $br->addon;
            $extraBedTotal = AddonPricing::extraBedTotal(
                $addon?->extra_beds_by_night ?? [],
                (int) ($rates['extra_bed'] ?? 0)
            );
            $breakfastTotal = AddonPricing::breakfastTotal([
                'set_100' => $addon?->breakfast_set_100 ?? 0,
                'set_200' => $addon?->breakfast_set_200 ?? 0,
            ], $rates, $nights);
            $earlyTotal = ($addon?->early_hours ?? 0) * (int) ($rates['early_checkin'] ?? 0);
            $lateTotal = ($addon?->late_hours ?? 0) * (int) ($rates['late_checkout'] ?? 0);

            // 🧾 (03/09/26) ยอดสุทธิต่อห้อง — สูตรอยู่จุดเดียว (chokepoint เดียวของ booking money):
            //    amount = room_amount − discount_amount + extra_bed_total + breakfast_total + early + late
            //    invariant Σ booking_rooms.amount == bookings.total_amount
            $amount = $roomAmount - $discountAmount
                + $extraBedTotal
                + $breakfastTotal
                + $earlyTotal
                + $lateTotal;

            // 💵 (25/09/26) REQ-015/016 — ปัดเศษขึ้นหลักสิบท้ายสุดครั้งเดียว (ลดก่อน ปัดท้าย)
            //    ปัดที่ amount ต่อห้อง แล้ว total_amount ไหลตาม (invariant Σ คงอยู่)
            $amount = RoundToTen::round($amount);

            $br->update([
                'room_amount' => $roomAmount,
                'discount_amount' => $discountAmount,
                'amount' => $amount,
            ]);

            // 📊 snapshot ราคา addon — อ่านที่ response/รายงาน ให้ตรงสูตรเสมอ (เขียนที่นี่จุดเดียว)
            if ($addon && (
                (int) $addon->breakfast_price !== $breakfastTotal
                || (int) $addon->extra_bed_price !== $extraBedTotal
                || (int) $addon->early_checkIn_price !== $earlyTotal
                || (int) $addon->late_checkOut_price !== $lateTotal
            )) {
                $addon->update([
                    'breakfast_price' => $breakfastTotal,
                    'extra_bed_price' => $extraBedTotal,
                    'early_checkIn_price' => $earlyTotal,
                    'late_checkOut_price' => $lateTotal,
                ]);
            }

            $total += $amount;
        }

        $booking->update(['total_amount' => $total]);

        return $booking->fresh();
    }

    /**
     * ลบโค้ดส่วนลดออกจากการจองสถานะ draft
     *
     * @throws Exception
     */
    public function removeFromDraft(Booking $booking): Booking
    {
        if ($booking->status !== 'draft') {
            throw new Exception('ใส่หรือลบโค้ดได้เฉพาะการจองสถานะ draft เท่านั้นค่ะ 📝', 422);
        }

        return DB::transaction(function () use ($booking) {
            DiscountRedemption::whereIn('booking_room_id', $booking->bookingRooms()->pluck('id'))->delete();
            $booking->update(['discount_code' => null]);

            return $this->reprice($booking);
        });
    }
}
