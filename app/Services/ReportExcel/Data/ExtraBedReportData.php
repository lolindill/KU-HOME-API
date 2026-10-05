<?php

namespace App\Services\ReportExcel\Data;

use App\Models\Addon;
use App\Services\ReportExcel\BaseReportData;
use Carbon\Carbon;

/**
 * 🛏️ ExtraBedReportData — รายงานเตียงเสริม (excel-reports spec §3.4, ticket 10)
 *
 *    คอลัมน์ **dynamic ตามคืนในช่วง filter** (รายงานพิเศษตาม spec §1.2 — columns() ขยายเอง):
 *    booking_no · guest_name · room_no · arrival · departure + 1 คอลัมน์ต่อคืน
 *    (label = วันที่ พ.ศ. · ค่า = qty รายคืนจาก addons.extra_beds_by_night, null = 0)
 *
 *    · ห้อง "-" = ยังไม่ได้ assign ห้อง (room_id null — draft ยังไม่จ่ายห้อง)
 *    · summary 3 ชั้นตามชีต: Total Allocated (รวมจัดสรรรายคืน) / Total Inventory (config
 *      reporting.extra_bed_fleet_size — ไม่ derive จาก rooms.builtin_extra_beds) / Balance
 *      → วางเป็นแถวเดียว "จัดสรร / คงเหลือ" ต่อคืน (คง stock ไว้ที่ config)
 */
class ExtraBedReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'extra-bed-report';
    }

    public function filterRules(): array
    {
        return $this->dateRangeRules('date_from', 'date_to');
    }

    /**
     * คอลัมน์ dynamic — แถบ 5 คอลัมน์หลัก + คอลัมน์รายคืน (key = YYYY-MM-DD)
     */
    public function columns(array $filters): array
    {
        $base = collect($this->templateColumns())
            ->reject(fn (array $col) => $col['type'] === 'integer_by_date')
            ->values()
            ->all();

        $nightColumns = [];
        foreach ($this->nights($filters) as $night) {
            $nightColumns[] = [
                'key' => $night,
                'label_th' => \App\Services\ReportExcel\ThaiDate::format($night),
                'type' => 'integer',
            ];
        }

        return array_merge($base, $nightColumns);
    }

    public function rows(array $filters): iterable
    {
        [$from, $to, $nights] = $this->range($filters);

        $addons = Addon::query()
            ->with(['bookingRoom.booking.user', 'bookingRoom.booking.organization', 'bookingRoom.room'])
            ->whereHas('bookingRoom', function ($q) use ($from, $to) {
                $q->where('check_in', '<', $to)
                    ->where('check_out', '>', $from)
                    ->whereIn('status', ['draft', 'confirmed', 'checked_in']);
            })
            ->get()
            ->filter(fn (Addon $addon) => $addon->extra_beds_total > 0);

        return $addons
            ->map(fn (Addon $addon) => $this->mapRow($addon, $nights))
            ->values();
    }

    public function summary(array $filters): array
    {
        [$from, $to, $nights] = $this->range($filters);
        $fleet = (int) config('reporting.extra_bed_fleet_size', 35);

        $rows = iterator_to_array($this->rows($filters));
        if ($rows === [] && $nights === []) {
            return [];
        }

        $summary = ['_label' => "Total Allocated / Total Inventory ({$fleet}) / Balance"];
        foreach ($nights as $night) {
            $allocated = array_sum(array_column($rows, $night));
            $summary[$night] = "{$allocated} / {$fleet} / ".max(0, $fleet - $allocated);
        }

        return $summary;
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: list<string>}
     */
    private function range(array $filters): array
    {
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        $to = Carbon::parse($filters['date_to'])->startOfDay();

        $nights = [];
        for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
            $nights[] = $d->toDateString();
        }

        return [$from, $to, $nights];
    }

    private function nights(array $filters): array
    {
        return $this->range($filters)[2];
    }

    private function mapRow(Addon $addon, array $nights): array
    {
        $br = $addon->bookingRoom;
        $booking = $br->booking;
        $byNight = $addon->extra_beds_by_night ?? [];

        $row = [
            'booking_no' => $booking->confirmation,
            'guest_name' => $booking->primary_guest_name,
            // ห้อง "-" = ยังไม่ได้ assign (note ใน template)
            'room_no' => $br->room?->room_number ?? '-',
            'arrival' => $br->check_in->toDateString(),
            'departure' => $br->check_out->toDateString(),
        ];

        foreach ($nights as $night) {
            $row[$night] = $byNight[$night] ?? null;
        }

        return $row;
    }
}
