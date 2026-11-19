<?php

namespace App\Services\ReportExcel\Data;

use App\Models\Addon;
use App\Models\GlobalRate;
use App\Services\Addon\AddonPricing;
use App\Services\ReportExcel\BaseReportData;
use Carbon\Carbon;

/**
 * 🍳 BreakfastReportData — รายงานอาหารเช้า (excel-reports spec §3.4, ticket 10)
 * 🍳 (19/11/26) breakfast reshape — toggle + เรทรายหัว:
 *
 *    แถว = per (booking_room × วันกิน) — **วันกิน = เช้าวันถัดจากคืนนอน** D ∈ (check_in, check_out]
 *    (เช้าเช็คเอาท์นับ — ตรง sample ชีต: พัก 9/11→9/12 โผล่รายงานวันที่ 9/12)
 *    · qty = จำนวนผู้เข้าพักของห้อง (toggle ราคาห้องละชุดเดียว — ทุกคนในห้องกิน;
 *      guests ว่าง นับ 1 ตาม AddonPricing::guestCount) · เรทที่เลือก = breakfast_code
 *    · filter breakfast_type รับ 'ทั้งหมด' หรือ code เรท (breakfast_100/breakfast_200/…)
 */
class BreakfastReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'breakfast-report';
    }

    public function filterRules(): array
    {
        // เรท dynamic จาก global_rates (code ขึ้นต้น breakfast, active เท่านั้น)
        $codes = array_keys(GlobalRate::breakfastRates());

        return array_merge(
            $this->singleDateRules('date'),
            $this->enumFilterRules('breakfast_type', $codes, 'ทั้งหมด'),
        );
    }

    public function rows(array $filters): iterable
    {
        $eatDay = Carbon::parse($filters['date'])->startOfDay();
        $type = $filters['breakfast_type'] ?? 'ทั้งหมด';

        // วันกิน D ∈ (check_in, check_out] — check_in < D <= check_out
        $addons = Addon::query()
            ->with(['bookingRoom.booking.user', 'bookingRoom.booking.organization', 'bookingRoom.room', 'bookingRoom.roomType'])
            ->where('breakfast_enabled', true)
            ->whereHas('bookingRoom', function ($q) use ($eatDay) {
                $q->where('check_in', '<', $eatDay->copy()->addDay())
                    ->where('check_out', '>=', $eatDay)
                    ->holdingSlot();
            })
            ->get();

        return $addons
            ->map(fn (Addon $addon) => $this->mapRow($addon, $eatDay))
            ->filter()
            ->filter(function (array $row) use ($type) {
                if ($type !== 'ทั้งหมด') {
                    return ($row['breakfast_code'] ?? null) === $type;
                }

                return true;
            })
            ->values();
    }

    public function summary(array $filters): array
    {
        $rows = iterator_to_array($this->rows($filters));

        if ($rows === []) {
            return [];
        }

        return [
            '_label' => 'รวม '.count($rows).' รายการ',
            'qty' => array_sum(array_column($rows, 'qty')),
        ];
    }

    private function mapRow(Addon $addon, Carbon $eatDay): ?array
    {
        $br = $addon->bookingRoom;
        $booking = $br->booking;

        return [
            'date' => $eatDay->toDateString(),
            'booking_no' => $booking->confirmation,
            'room_no' => $br->room?->room_number ?? $br->room_id,
            'user_name' => $booking->user?->name ?? $booking->customer_name,
            'checkin_date' => $br->check_in->toDateString(),
            'checkout_date' => $br->check_out->toDateString(),
            'breakfast_code' => $addon->breakfast_code,
            'qty' => AddonPricing::guestCount($br),
        ];
    }
}
