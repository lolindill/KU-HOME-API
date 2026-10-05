<?php

namespace App\Services\ReportExcel\Data;

use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Services\ReportExcel\BaseReportData;

/**
 * 🔧 OutOfServiceRoomReportData — รายงานห้องชำรุด/บำรุงรักษา (excel-reports spec §2.5, ticket 11)
 *
 *    แถว = room_state_periods kind=maintenance ที่ **วันแจ้งซ่อม (start_date) อยู่ในช่วง
 *    date_range** + filter work_type (ไฟฟ้า | ประปา | งานระบบ · ทุกประเภท)
 *    · fixed_date = end_date (เปิดปลาย = ยังไม่เสร็จ → null แสดง "-")
 *    · repair_duration_days = end − start (derive ไม่มี column)
 *    · CRUD ใช้ route periods ที่ landed แล้ว — ตารางนี้ไม่มี chokepoint ใหม่
 */
class OutOfServiceRoomReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'out-of-service-room-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->dateRangeRules('date_from', 'date_to'),
            $this->enumFilterRules('work_type', ['ทุกประเภท', ...RoomStatePeriod::WORK_TYPES], 'ทุกประเภท'),
        );
    }

    public function rows(array $filters): iterable
    {
        $workType = $filters['work_type'] ?? 'ทุกประเภท';

        $rooms = Room::query()->pluck('room_number', 'id');

        return RoomStatePeriod::query()
            ->where('kind', RoomStatePeriod::KIND_MAINTENANCE)
            ->whereDate('start_date', '>=', $filters['date_from'])
            ->whereDate('start_date', '<=', $filters['date_to'])
            ->when($workType !== 'ทุกประเภท' && $workType !== '', fn ($q) => $q->where('work_type', $workType))
            ->orderBy('start_date')
            ->get()
            ->map(fn (RoomStatePeriod $period) => [
                'report_date' => $period->start_date->toDateString(),
                'room_no' => $rooms[$period->room_id] ?? $period->room_id,
                'work_type' => $period->work_type,
                'repair_detail' => $period->repair_detail,
                // เปิดปลาย = ยังไม่เสร็จ — แสดง "-" (null)
                'fixed_date' => $period->end_date?->toDateString(),
                'repair_duration_days' => $period->end_date
                    ? (int) $period->start_date->diffInDays($period->end_date)
                    : null,
            ]);
    }

    public function summary(array $filters): array
    {
        $rows = iterator_to_array($this->rows($filters));

        if ($rows === []) {
            return [];
        }

        return [
            '_label' => 'รวม '.count($rows).' รายการ',
            'repair_duration_days' => array_sum(array_filter(array_column($rows, 'repair_duration_days'))),
        ];
    }
}
