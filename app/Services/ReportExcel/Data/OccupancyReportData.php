<?php

namespace App\Services\ReportExcel\Data;

use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Services\ReportExcel\BaseReportData;
use Carbon\Carbon;

/**
 * 📈 OccupancyReportData — รายงานการเข้าพัก 7 วันถัดไป (excel-reports spec §3.3, ticket 06)
 *
 *    1 แถว = 1 คืน เริ่มวันที่ filter (7 คืน) — นิยามคืนเดียวกับ manager-report:
 *    - occupied = BR checked_in ครอบคลุมคืนนั้น (check_in <= D < check_out)
 *    - reserved = period kind=reserved active (ไม่ทับ occupied) · out_of_service = kind=maintenance
 *    - vacant = total − occupied (ตามชีตต้นทาง — reserved/OOS เป็นข้อมูลประกอบ)
 *    - arrivals = BR มาถึงคืนนั้น · departures = BR ดิวเอาท์คืนนั้น
 *    · +2 คอลัมน์ที่สารบัญระบุแต่ template ไม่มี (note 3): percent_occupancy + adr
 *      (ADR = รายได้ค่าห้องเฉลี่ยต่อห้อง occupied ของคืนนั้น)
 */
class OccupancyReportData extends BaseReportData
{
    private const NIGHTS = 7;

    public function templateId(): string
    {
        return 'occupancy-report';
    }

    public function filterRules(): array
    {
        return $this->singleDateRules('date');
    }

    /**
     * เพิ่ม 2 คอลัมน์ตามสารบัญ (template note 3 แนะนำเอง) — นอกนั้นใช้ของ template
     */
    public function columns(array $filters): array
    {
        return array_merge($this->templateColumns(), [
            ['key' => 'percent_occupancy', 'label_th' => '% Occupancy', 'type' => 'percent'],
            ['key' => 'adr', 'label_th' => 'ADR (บาท)', 'type' => 'money_baht'],
        ]);
    }

    public function rows(array $filters): iterable
    {
        $start = Carbon::parse($filters['date'])->startOfDay();

        $totalRooms = Room::count();

        $spans = BookingRoom::query()
            ->with(['booking', 'roomType'])
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->whereDate('check_in', '<=', $start->copy()->addDays(self::NIGHTS))
            ->whereDate('check_out', '>', $start)
            ->get();

        $maintenanceByDate = [];
        $reservedByDate = [];
        foreach (RoomStatePeriod::query()->get() as $period) {
            for ($i = 0; $i < self::NIGHTS; $i++) {
                $night = $start->copy()->addDays($i);
                if ($period->start_date->lte($night) && ($period->end_date === null || $period->end_date->gt($night))) {
                    $key = $night->toDateString();
                    $maintenanceByDate[$key][] = $period->room_id;
                    if ($period->kind === RoomStatePeriod::KIND_RESERVED) {
                        $reservedByDate[$key][] = $period->room_id;
                    }
                }
            }
        }

        $rows = [];
        for ($i = 0; $i < self::NIGHTS; $i++) {
            $night = $start->copy()->addDays($i);
            $key = $night->toDateString();

            $occupiedSpans = $spans->filter(fn ($br) => $br->status === 'checked_in'
                && $br->check_in->lte($night) && $br->check_out->gt($night));
            $occupied = $occupiedSpans->count();
            $ooo = collect($maintenanceByDate[$key] ?? [])->unique()->count();
            $reserved = collect($reservedByDate[$key] ?? [])
                ->diff($maintenanceByDate[$key] ?? [])
                ->unique()
                ->count();

            $rows[] = [
                'date' => $key,
                'total_rooms' => $totalRooms,
                'occupied_rooms' => $occupied,
                'occupied_superior' => $occupiedSpans->filter(fn ($br) => $br->roomType?->name_en === 'Superior')->count(),
                'occupied_deluxe' => $occupiedSpans->filter(fn ($br) => $br->roomType?->name_en === 'Deluxe')->count(),
                'occupied_suite' => $occupiedSpans->filter(fn ($br) => $br->roomType?->name_en === 'Suite')->count(),
                // vacant ตามชีต = total − occupied (reserved/OOS เป็นข้อมูลประกอบ)
                'vacant_rooms' => $totalRooms - $occupied,
                'reserved_rooms' => $reserved,
                'out_of_service_rooms' => $ooo,
                'arrivals' => $spans->filter(fn ($br) => $br->check_in->isSameDay($night) && $br->status !== 'checked_out')->count(),
                'departures' => $spans->filter(fn ($br) => $br->check_out->isSameDay($night))->count(),
                'percent_occupancy' => $totalRooms > 0 ? round($occupied * 100 / $totalRooms, 1) : 0,
                'adr' => $occupied > 0
                    ? (int) round($occupiedSpans->sum(fn ($br) => (int) $br->amount / max(1, (int) $br->check_in->startOfDay()->diffInDays($br->check_out->startOfDay()))) / $occupied)
                    : 0,
            ];
        }

        // แถวรวม — sum ทุกคอลัมน์ตัวนับ (template note: ชีตต้นทางวางเลขไม่ตรงคอลัมน์ ให้คำนวณเอง)
        return $rows;
    }

    public function summary(array $filters): array
    {
        $rows = iterator_to_array($this->rows($filters));

        if ($rows === []) {
            return [];
        }

        return [
            '_label' => 'รวม '.count($rows).' คืน',
            'occupied_rooms' => array_sum(array_column($rows, 'occupied_rooms')),
            'occupied_superior' => array_sum(array_column($rows, 'occupied_superior')),
            'occupied_deluxe' => array_sum(array_column($rows, 'occupied_deluxe')),
            'occupied_suite' => array_sum(array_column($rows, 'occupied_suite')),
            'arrivals' => array_sum(array_column($rows, 'arrivals')),
            'departures' => array_sum(array_column($rows, 'departures')),
            'percent_occupancy' => round(collect($rows)->avg('percent_occupancy'), 1),
            'adr' => (int) round(collect($rows)->avg('adr')),
        ];
    }
}
