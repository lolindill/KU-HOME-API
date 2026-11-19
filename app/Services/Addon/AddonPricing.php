<?php

namespace App\Services\Addon;

use App\Models\Addon;
use App\Models\BookingRoom;
use Carbon\Carbon;
use Exception;

/**
 * 🛏️🍳 (05/10/26) excel-reports spec §2.2/§3.4 — helper กลางของ addon (ticket 10)
 * 🍳 (19/11/26) breakfast reshape — toggle + เรทรายหัว:
 *
 *    1) Normalize wire input → canonical storage (ลำดับ resolve แบบเดียวกับ
 *       resolveEarlyLate เดิม: canonical → legacy alias → คงค่าเดิมจากแถว addon):
 *       - breakfast: canonical `breakfast_enabled` (bool) + `breakfast_code` (เรทจาก
 *         global_rates code ขึ้นต้น 'breakfast' — รองรับหลายเรท)
 *         · legacy `breakfast_sets: {set_100, set_200}` (ชุดไหน > 0 → enabled ตามชุดนั้น)
 *         · legacy `breakfast` (int > 0 → enabled + code breakfast_200 เรทเดิม)
 *       - extra-bed: canonical `extra_beds_by_night` (map รายคืน) · legacy `extra_bed`/`extra_beds`
 *         (int) → server normalize เป็น flat map ทุกคืนของ stay [check_in, check_out)
 *    2) สูตรเงินกลางที่ DiscountService::reprice() (chokepoint เดียว) + write paths ใช้ร่วมกัน:
 *       - breakfast_total = rate(breakfast_code) × nights × max(1, จำนวนผู้เข้าพัก)
 *         (guests ว่าง = นับ 1 คน — มติ owner 19/11/26)
 *       - extra_bed_total = Σ qty รายคืน × เรท extra_bed (คิดรายคืน จำนวนต่อคืนไม่เท่ากันได้)
 *
 *    ⚠️ class นี้ query DB เฉพาะผ่านพารามิเตอร์ — ห้ามมี business query เอง
 */
class AddonPricing
{
    /**
     * 🍳 resolve อาหารเช้า → {enabled, code} (toggle + เรทเดียวต่อห้อง)
     *
     * ลำดับ: canonical breakfast_enabled/breakfast_code → legacy breakfast_sets
     * → legacy breakfast (int) → คงค่าเดิมจากแถว addon
     *
     * @param  array<string,int>  $availableRates  GlobalRate::breakfastRates() — [code => price]
     *                                             (active เท่านั้น) ใช้ validate breakfast_code + default เรทถูกสุดเมื่อไม่ส่ง
     * @return array{enabled: bool, code: ?string} code = null เมื่อปิด toggle
     *
     * @throws Exception 422 — breakfast_code ไม่อยู่ในเรท active / เปิด toggle แต่ระบบไม่มีเรท
     */
    public static function resolveBreakfast(?array $addonInput, ?Addon $existing = null, array $availableRates = []): array
    {
        $keep = fn (): array => [
            'enabled' => (bool) ($existing?->breakfast_enabled ?? false),
            'code' => $existing?->breakfast_code,
        ];

        if (is_array($addonInput)) {
            // canonical — toggle bool (+ เลือกเรท)
            if (array_key_exists('breakfast_enabled', $addonInput)) {
                $enabled = (bool) $addonInput['breakfast_enabled'];

                if (! $enabled) {
                    return ['enabled' => false, 'code' => null];
                }

                $code = $addonInput['breakfast_code'] ?? ($existing?->breakfast_code ?? null);

                return [
                    'enabled' => true,
                    'code' => self::normalizeCode($code, $existing, $availableRates),
                ];
            }

            // legacy — breakfast_sets: {set_100, set_200} ชุดไหน > 0 → enabled ตามชุดนั้น
            $sets = $addonInput['breakfast_sets'] ?? null;

            if (is_array($sets)) {
                $set200 = (int) ($sets['set_200'] ?? 0);
                $set100 = (int) ($sets['set_100'] ?? 0);

                if ($set200 > 0) {
                    return ['enabled' => true, 'code' => self::normalizeCode('breakfast_200', $existing, $availableRates)];
                }

                if ($set100 > 0) {
                    return ['enabled' => true, 'code' => self::normalizeCode('breakfast_100', $existing, $availableRates)];
                }

                return ['enabled' => false, 'code' => null];
            }

            // legacy — `breakfast` (int) เดิมเป็นเรท 200 → normalize เป็น breakfast_200
            if (array_key_exists('breakfast', $addonInput)) {
                $qty = (int) $addonInput['breakfast'];

                if ($qty > 0) {
                    return ['enabled' => true, 'code' => self::normalizeCode('breakfast_200', $existing, $availableRates)];
                }

                return ['enabled' => false, 'code' => null];
            }

            // ส่ง addons มาแต่ไม่แตะ breakfast → คงค่าเดิม
            return $keep();
        }

        // ไม่ส่ง addons เลย = คงค่าเดิม
        return $keep();
    }

    /**
     * 🍳 normalize breakfast_code — null/ไม่ส่ง → คงเดิม → default เรทถูกสุดที่ active
     *
     * @param  array<string,int>  $availableRates
     *
     * @throws Exception 422 — code ที่ส่งมาไม่ใช่เรท active / ระบบไม่มีเรทให้เลือก
     */
    private static function normalizeCode(?string $code, ?Addon $existing, array $availableRates): ?string
    {
        if ($availableRates === []) {
            throw new Exception('ระบบยังไม่มีเรทอาหารเช้า (global_rates code ขึ้นต้น breakfast) ให้เลือกค่ะ 🥺', 422);
        }

        if ($code !== null && $code !== '') {
            if (! isset($availableRates[$code])) {
                throw new Exception("เรทอาหารเช้า '{$code}' ไม่มีในระบบ หรือถูกปิดใช้งานอยู่ค่ะ 🥺", 422);
            }

            return $code;
        }

        // ไม่ส่ง code — คงค่าเดิมจากแถว addon (ถ้ายัง valid) ไม่งั้น default ถูกสุด
        $existingCode = $existing?->breakfast_code;

        if ($existingCode !== null && isset($availableRates[$existingCode])) {
            return $existingCode;
        }

        return array_search(min($availableRates), $availableRates) ?: array_key_first($availableRates);
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
     * @return array<string,int> map คืน (YYYY-MM-DD) → qty — [] = ไม่มีเตียงเสริม
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
     * 🧑‍🤝‍🧑 จำนวนผู้เข้าพักสำหรับคิดเงิน breakfast — guests ว่าง/null = นับ 1 คน
     * (กัน undercharge: เปิด toggle = มีคนกินแน่นอนอย่างน้อย 1 — มติ owner 19/11/26)
     */
    public static function guestCount(?BookingRoom $br): int
    {
        $guests = $br?->guests;

        return max(1, is_array($guests) ? count($guests) : 0);
    }

    /**
     * 🍳 breakfast_total = rate(breakfast_code) × nights × guests
     * — คิด × คืน × หัว (toggle ราคาห้องละชุดเดียว ทุกคนในห้องกิน)
     *
     * @param  bool  $enabled  addons.breakfast_enabled (toggle)
     * @param  int  $rate  เรทของ breakfast_code จาก GlobalRate::breakfastRates()
     * @param  int  $guests  AddonPricing::guestCount(...) — ว่าง = 1
     */
    public static function breakfastTotal(bool $enabled, int $rate, int $nights, int $guests): int
    {
        if (! $enabled || $rate <= 0) {
            return 0;
        }

        return $rate * $nights * max(1, $guests);
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
