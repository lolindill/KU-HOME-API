<?php

namespace App\Services\ReportExcel\Data;

use App\Models\Addon;
use App\Services\ReportExcel\BaseReportData;
use Carbon\Carbon;

/**
 * 🍳 BreakfastReportData — รายงานอาหารเช้า (excel-reports spec §3.4, ticket 10)
 *
 *    แถว = per (booking_room × วันกิน) — **วันกิน = เช้าวันถัดจากคืนนอน** D ∈ (check_in, check_out]
 *    (เช้าเช็คเอาท์นับ — ตรง sample ชีต: พัก 9/11→9/12 โผล่รายงานวันที่ 9/12)
 *    · qty แยกชุด 100/200 จาก addons.breakfast_set_100/200 · "x" ในชีต = null คงตีความเดิม
 *      (ไม่ได้ซื้อชุดนั้น)
 *    · filter breakfast_type ตัด row ที่ชุดนั้น qty = 0
 */
class BreakfastReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'breakfast-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->singleDateRules('date'),
            $this->enumFilterRules('breakfast_type', ['ทั้งหมด', 'ชุด 100', 'ชุด 200'], 'ทั้งหมด'),
        );
    }

    public function rows(array $filters): iterable
    {
        $eatDay = Carbon::parse($filters['date'])->startOfDay();
        $type = $filters['breakfast_type'] ?? 'ทั้งหมด';

        // วันกิน D ∈ (check_in, check_out] — check_in < D <= check_out
        $addons = Addon::query()
            ->with(['bookingRoom.booking.user', 'bookingRoom.booking.organization', 'bookingRoom.room', 'bookingRoom.roomType'])
            ->whereHas('bookingRoom', function ($q) use ($eatDay) {
                $q->where('check_in', '<', $eatDay->copy()->addDay())
                    ->where('check_out', '>=', $eatDay)
                    ->whereIn('status', ['draft', 'confirmed', 'checked_in']);
            })
            ->where(function ($q) {
                $q->where('breakfast_set_100', '>', 0)->orWhere('breakfast_set_200', '>', 0);
            })
            ->get();

        return $addons
            ->map(fn (Addon $addon) => $this->mapRow($addon, $eatDay))
            ->filter()
            ->filter(function (array $row) use ($type) {
                if ($type === 'ชุด 100') {
                    return ($row['qty_set_100'] ?? 0) > 0;
                }
                if ($type === 'ชุด 200') {
                    return ($row['qty_set_200'] ?? 0) > 0;
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
            'qty_set_100' => array_sum(array_column($rows, 'qty_set_100')),
            'qty_set_200' => array_sum(array_column($rows, 'qty_set_200')),
        ];
    }

    private function mapRow(Addon $addon, Carbon $eatDay): ?array
    {
        $br = $addon->bookingRoom;
        $booking = $br->booking;

        $set100 = (int) $addon->breakfast_set_100;
        $set200 = (int) $addon->breakfast_set_200;

        if ($set100 === 0 && $set200 === 0) {
            return null;
        }

        return [
            'date' => $eatDay->toDateString(),
            'booking_no' => $booking->confirmation,
            'room_no' => $br->room?->room_number ?? $br->room_id,
            'user_name' => $booking->user?->name ?? $booking->customer_name,
            'checkin_date' => $br->check_in->toDateString(),
            'checkout_date' => $br->check_out->toDateString(),
            // "x" ในชีต = ไม่ได้ซื้อชุดนั้น → null
            'qty_set_100' => $set100 > 0 ? $set100 : null,
            'qty_set_200' => $set200 > 0 ? $set200 : null,
        ];
    }
}
