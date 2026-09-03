<?php

namespace App\Services\Discount;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\GlobalRate;
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
     */
    public function computeForRoom(Discount $d, int $rate, int $nights): int
    {
        $roomBase = $rate * $nights;

        if ($d->type === 'percent') {
            $amt = intdiv($roomBase * $d->value, 100);

            return min($amt, $roomBase);
        }

        if ($d->type === 'fixed') {
            return min($d->value, $roomBase);
        }

        if ($d->type === 'set_room_price') {
            $diff = $rate - min($d->value, $rate);
            $amt = $diff * $nights;

            return max($amt, 0);
        }

        return 0;
    }

    /**
     * คำนวณยอดเงินใหม่ทั้งใบของการจอง (room_amount, discount_amount, amount, total_amount)
     * 🧾 (03/09/26) chokepoint เดียวที่เขียน `amount` (net ต่อห้อง) — walk-in ก็เรียก method นี้
     */
    public function reprice(Booking $booking): Booking
    {
        $discount = $booking->discount_code ? Discount::where('code', $booking->discount_code)->first() : null;
        $holds = DiscountRedemption::whereIn('booking_room_id', $booking->bookingRooms()->pluck('id'))->get();
        $total = 0;

        foreach ($booking->bookingRooms()->with(['addon', 'roomType'])->get() as $br) {
            $nights = Carbon::parse($br->check_in)->diffInDays(Carbon::parse($br->check_out)) ?: 1;
            $rate = GlobalRate::getRoomRate($br->roomType, 'daily');
            $roomAmount = $rate * $nights;

            $isHeld = $holds->contains('booking_room_id', $br->id);
            $discountAmount = ($discount && $isHeld && $this->isEligible($discount, $br))
                ? $this->computeForRoom($discount, $rate, $nights)
                : 0;

            // 🧾 (03/09/26) ยอดสุทธิต่อห้อง — สูตรอยู่จุดเดียว (chokepoint เดียวของ booking money):
            //    amount = room_amount − discount_amount + addon 4 รายการ
            //    invariant Σ booking_rooms.amount == bookings.total_amount
            $amount = $roomAmount - $discountAmount
                + ($br->addon?->extra_bed_price ?? 0)
                + ($br->addon?->breakfast_price ?? 0)
                + ($br->addon?->early_checkIn_price ?? 0)
                + ($br->addon?->late_checkOut_price ?? 0);

            $br->update([
                'room_amount' => $roomAmount,
                'discount_amount' => $discountAmount,
                'amount' => $amount,
            ]);

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
