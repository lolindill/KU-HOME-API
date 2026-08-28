<?php

namespace App\Services\RoomAllocator;

use App\Models\Booking;
use App\Models\Room;
use App\Services\RoomAllocator\Dto\BookingRequestDto;

/**
 * 🥇 Booking Priority — เรียง Queue ก่อน Assign ห้อง
 *
 *    Port จาก playground computeBookingPriority():
 *      Priority: Suite → X09-free(ถ้ามี) → BedPref → Most rooms → Least checkout
 *
 *    เหตุผล: booking ที่ "จัดยาก" (มีข้อจำกัดมาก) ต้องได้สิทธิ์เลือกก่อน
 *    เหมือนกับจองที่นั่งเครื่องบิน
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §7
 */
final class BookingPriority
{
    /**
     * คำนวณ trait ของ booking สำหรับใช้ใน comparator
     *
     * @param  array<BookingRequestDto>  $brs  BR DTO ของ booking นี้ (loaded + eager)
     * @return array{hasSuite: bool, hasX09Free: bool, hasBedPref: bool, roomCount: int, checkOutTs: int}
     */
    public static function traits(Booking $booking, array $brs, Weights $w): array
    {
        $hasExtraBed = false;
        $hasBedPref = false;
        $hasSuite = false;

        foreach ($brs as $br) {
            if ($br->type === 'Suite') {
                $hasSuite = true;
            }
            if ($br->type === 'Deluxe' && $br->extraBeds > 0) {
                $hasExtraBed = true;
            }
            // 🏨 BR ที่ระบุ bed_preference (king_size = ชั้น 8) จัดยาก → ได้สิทธิ์เลือกก่อน
            if ($br->bedPreference !== null && $br->bedPreference !== 'any') {
                $hasBedPref = true;
            }
        }

        // ตรวจ X09 ว่ายังว่างจริง — ใช้ checkIn/Out ของ BR ที่ต้องการ extra bed
        $hasX09Free = false;
        if ($hasExtraBed) {
            foreach ($brs as $br) {
                if ($br->type === 'Deluxe' && $br->extraBeds > 0) {
                    if (self::hasX09Free($br, $w)) {
                        $hasX09Free = true;
                        break;
                    }
                }
            }
        }

        // checkOut ts ของ BR แรก (หรือคืนค่าต่ำสุด)
        $checkOutStr = null;
        foreach ($brs as $br) {
            if ($checkOutStr === null || $br->checkOut < $checkOutStr) {
                $checkOutStr = $br->checkOut;
            }
        }
        $checkOutTs = $checkOutStr ? strtotime($checkOutStr) : 0;

        return [
            'hasSuite' => $hasSuite,
            'hasX09Free' => $hasX09Free,
            'hasBedPref' => $hasBedPref,
            'roomCount' => count($brs),
            'checkOutTs' => $checkOutTs,
        ];
    }

    /**
     * ตรวจว่ามีห้อง X09 (Deluxe 3-bed builtin) ว่างสำหรับ BR นี้หรือไม่
     */
    private static function hasX09Free(BookingRequestDto $br, Weights $w): bool
    {
        return Room::whereHas('roomType', fn ($q) => $q->where('name_en', 'Deluxe'))
            ->where('builtin_extra_beds', '>=', $w->x09MinBuiltinBeds)
            ->whereNotIn('status', ['maintenance', 'reserved_closed'])
            ->whereDoesntHave('bookingRooms', function ($q) use ($br) {
                $q->whereIn('status', ['draft', 'confirmed', 'checked_in'])
                    ->where('check_in', '<', $br->checkOut)
                    ->where('check_out', '>', $br->checkIn);
            })
            ->exists();
    }

    /**
     * Comparator สำหรับ usort() — เรียงตาม priority 5 ขั้น
     *    1. hasSuite       (desc — Suite ก่อน)
     *    2. hasX09Free     (desc)
     *    3. hasBedPref     (desc)
     *    4. roomCount      (desc — จองเยอะกว่าก่อน)
     *    5. checkOutTs     (asc  — เช็คเอาท์เร็วกว่าก่อน)
     *
     * @param  array  $a  traits() ของ booking A
     * @param  array  $b  traits() ของ booking B
     */
    public static function compare(array $a, array $b): int
    {
        if ($a['hasSuite'] !== $b['hasSuite']) {
            return $b['hasSuite'] <=> $a['hasSuite'];
        }
        if ($a['hasX09Free'] !== $b['hasX09Free']) {
            return $b['hasX09Free'] <=> $a['hasX09Free'];
        }
        if ($a['hasBedPref'] !== $b['hasBedPref']) {
            return $b['hasBedPref'] <=> $a['hasBedPref'];
        }
        if ($a['roomCount'] !== $b['roomCount']) {
            return $b['roomCount'] <=> $a['roomCount'];
        }

        return $a['checkOutTs'] <=> $b['checkOutTs'];
    }
}
