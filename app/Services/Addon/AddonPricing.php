<?php

namespace App\Services\Addon;

use App\Models\Addon;
use Carbon\Carbon;
use Exception;

/**
 * 🛏️🍳 (05/10/26) excel-reports spec §2.2/§3.4 — helper กลางของ addon (ticket 10)
 *
 *    1) Normalize wire input → canonical storage (ลำดับ resolve แบบเดียวกับ
 *       resolveEarlyLate เดิม: canonical → legacy alias → คงค่าเดิมจากแถว addon):
 *       - breakfast: canonical `breakfast_sets: {set_100, set_200}` · legacy `breakfast` (int) → set_200
 *       - extra-bed: canonical `extra_beds_by_night` (map รายคืน) · legacy `extra_bed`/`extra_beds`
 *         (int) → server normalize เป็น flat map ทุกคืนของ stay [check_in, check_out)
 *    2) สูตรเงินกลางที่ DiscountService::reprice() (chokepoint เดียว) + write paths ใช้ร่วมกัน:
 *       - breakfast_total = (set_100 × เรท breakfast_100 + set_200 × เรท breakfast_200) × nights
 *       - extra_bed_total = Σ qty รายคืน × เรท extra_bed (คิดรายคืน จำนวนต่อคืนไม่เท่ากันได้)
 *
 *    ⚠️ class นี้ query DB เฉพาะผ่านพารามิเตอร์ — ห้ามมี business query เอง
 */
class AddonPricing
{
    /**
     * 🍳 resolve ชุดอาหารเช้า → [set_100, set_200]
     *
     * @return array{set_100: int, set_200: int}
     */
    public static function resolveBreakfastSets(?array $addonInput, ?Addon $existing = null): array
    {
        if (is_array($addonInput)) {
            $sets = $addonInput['breakfast_sets'] ?? null;

            if (is_array($sets)) {
                return [
                    'set_100' => (int) ($sets['set_100'] ?? ($existing?->breakfast_set_100 ?? 0)),
                    'set_200' => (int) ($sets['set_200'] ?? ($existing?->breakfast_set_200 ?? 0)),
                ];
            }

            // legacy — `breakfast` (int) เดิมเป็นเรท 200 → normalize เป็น set_200
            if (array_key_exists('breakfast', $addonInput)) {
                return [
                    'set_100' => ($existing?->breakfast_set_100 ?? 0),
                    'set_200' => (int) $addonInput['breakfast'],
                ];
            }

            // ส่ง addons มาแต่ไม่แตะ breakfast → คงค่าเดิม
            return [
                'set_100' => ($existing?->breakfast_set_100 ?? 0),
                'set_200' => ($existing?->breakfast_set_200 ?? 0),
            ];
        }

        // ไม่ส่ง addons เลย = คงค่าเดิมทั้งชุด
        return [
            'set_100' => ($existing?->breakfast_set_100 ?? 0),
            'set_200' => ($existing?->breakfast_set_200 ?? 0),
        ];
    }

    /**
     * 🕐 resolve ชั่วโมง early check-in / late check-out (ย้ายจาก BookingController — logic คงเดิม)
     *    canonical: addons.early_checkin / addons.late_checkout (int ชั่วโมง 0-7)
     *    alias: early_hours / late_hours (frontend echo ชื่อ column กลับมา)
     *    ลำดับ resolve ของแต่ละ key: canonical → alias → คงค่าเดิมจากแถว addon
     *
     * @return array{0: int, 1: int} [earlyHours, lateHours]
     */
    public static function resolveEarlyLate(?array $addonInput, ?Addon $existing = null): array
    {
        if (is_array($addonInput)) {
            $earlyHours = (int) ($addonInput['early_checkin'] ?? $addonInput['early_hours']
                ?? ($existing?->early_hours ?? 0));
            $lateHours = (int) ($addonInput['late_checkout'] ?? $addonInput['late_hours']
                ?? ($existing?->late_hours ?? 0));

            return [$earlyHours, $lateHours];
        }

        $earlyHours = ! empty($existing?->early_checkIn_price) ? ($existing?->early_hours ?? 0) : 0;
        $lateHours = ! empty($existing?->late_checkOut_price) ? ($existing?->late_hours ?? 0) : 0;

        return [$earlyHours, $lateHours];
    }

    /**
     * 🛏️ resolve เตียงเสริมรายคืน → map [YYYY-MM-DD => qty]
     *
     * ลำดับ: canonical extra_beds_by_night → legacy extra_bed (addons) → legacy extra_beds
     * (หัวห้อง) → คงค่าเดิมจากแถว addon
     *
     * @param  array  $roomRequest  หนึ่ง entry ของ booking_rooms[] (มี check_in/check_out effective)
     * @param  int|null  $maxPerNight  room_types.max_extra_beds (null = ไม่จำกัด)
     * @return array<string,int>  map คืน (YYYY-MM-DD) → qty — [] = ไม่มีเตียงเสริม
     *
     * @throws Exception 422 — คีย์นอกช่วง [check_in, check_out) / qty เกิน max / qty ติดลบ
     */
    public static function resolveExtraBedsByNight(
        array $roomRequest,
        ?array $addonInput,
        ?Addon $existing = null,
        ?int $maxPerNight = null
    ): array {
        // ช่วงคืนของ stay — effective dates ที่ caller ส่งมาแล้ว (half-open เหมือน availability)
        $in = Carbon::parse($roomRequest['check_in'])->startOfDay();
        $out = Carbon::parse($roomRequest['check_out'])->startOfDay();
        $validNights = [];
        for ($d = $in->copy(); $d->lt($out); $d->addDay()) {
            $validNights[$d->toDateString()] = true;
        }

        $resolve = function ($raw) use ($validNights, $maxPerNight, $in, $out): array {
            if (! is_array($raw)) {
                // legacy int → flat map ทุกคืนของ stay (คงยอดเงินเดิม qty × เรท × คืน)
                $qty = (int) $raw;
                if ($qty <= 0) {
                    return [];
                }
                $map = [];
                foreach (array_keys($validNights) as $night) {
                    $map[$night] = $qty;
                }

                return $map;
            }

            // canonical map — validate คีย์/ค่าตาม spec §2.2
            $map = [];
            foreach ($raw as $night => $qty) {
                if (! isset($validNights[(string) $night])) {
                    throw new Exception(
                        "วันที่เตียงเสริม '{$night}' อยู่นอกช่วงคืนพัก ({$in->toDateString()} ถึง {$out->toDateString()} — วันเช็คเอาท์ไม่นับ) ค่ะ 🛏️",
                        422
                    );
                }
                $qty = (int) $qty;
                if ($qty < 0) {
                    throw new Exception("จำนวนเตียงเสริมคืน {$night} ต้องไม่ติดลบค่ะ 🛏️", 422);
                }
                if ($qty === 0) {
                    continue;
                }
                if ($maxPerNight !== null && $qty > $maxPerNight) {
                    throw new Exception("จำนวนเตียงเสริมคืน {$night} เกินขีดสุดของประเภทห้อง (สูงสุด {$maxPerNight} ต่อคืน) ค่ะ 🛏️", 422);
                }
                $map[(string) $night] = $qty;
            }

            return $map;
        };

        if (is_array($addonInput)) {
            if (array_key_exists('extra_beds_by_night', $addonInput)) {
                return $resolve($addonInput['extra_beds_by_night']);
            }

            if (array_key_exists('extra_bed', $addonInput)) {
                return $resolve($addonInput['extra_bed']);
            }

            if (array_key_exists('extra_beds', $roomRequest)) {
                return $resolve($roomRequest['extra_beds']);
            }

            // ส่ง addons มาแต่ไม่แตะ extra-bed → คง map เดิม
            return $existing?->extra_beds_by_night ?? [];
        }

        // ไม่ส่ง addons เลย — ยังรับ legacy extra_beds หัวห้องได้ (pattern resolveExtraBed เดิม)
        if (array_key_exists('extra_beds', $roomRequest)) {
            return $resolve($roomRequest['extra_beds']);
        }

        return $existing?->extra_beds_by_night ?? [];
    }

    /**
     * 🍳 breakfast_total = (set_100 × เรท 100 + set_200 × เรท 200) × nights
     * — คิด × คืน (แก้ undercharge เดิมคิดครั้งเดียวต่อ stay ทั้งที่ร้านเตรียมทุกคืน)
     *
     * @param  array{set_100: int, set_200: int}  $sets
     * @param  array<string,int>  $rates  GlobalRate::getPrices(...) — มี key breakfast_100/breakfast_200
     */
    public static function breakfastTotal(array $sets, array $rates, int $nights): int
    {
        $perNight = ($sets['set_100'] * ($rates['breakfast_100'] ?? 0))
            + ($sets['set_200'] * ($rates['breakfast_200'] ?? 0));

        return $perNight * $nights;
    }

    /**
     * 🛏️ extra_bed_total = Σ qty รายคืน × เรท — คิดรายคืน
     *
     * @param  array<string,int>  $byNight
     */
    public static function extraBedTotal(array $byNight, int $rate): int
    {
        return (int) array_sum($byNight) * $rate;
    }
}
