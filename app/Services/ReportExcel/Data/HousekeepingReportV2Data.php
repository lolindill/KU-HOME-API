<?php

namespace App\Services\ReportExcel\Data;

use App\Models\BookingRoom;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Services\ReportExcel\BaseReportData;
use Carbon\Carbon;

/**
 * 🧹✨ HousekeepingReportV2Data — รายงานแม่บ้าน v2 (excel-reports spec §2.4/§6.2, ticket 10)
 *
 *    สถานะห้องแบบ emoji ตามชีต: 🟢 IN (มาถึงวันนี้) / 🔴 OUT (ดิวเอาท์วันนี้) /
 *    🟡 Stay (อยู่ต่อ) / ⚫ Out of Order (period ซ่อม active) · ห้องว่าง = null
 *
 *    คอลัมน์ checklist 3 ช่อง = cleaning_check_1/2/3 ของ task แม่บ้านวันนั้น —
 *    **หัวคอลัมน์จาก config reporting.cleaning_checks** (sheet-wins exception §6.2 —
 *    ชีตต้นทาง G/H/I ไม่มีหัวข้อ owner ยืนยันให้ระบบตั้งเอง)
 */
class HousekeepingReportV2Data extends BaseReportData
{
    public function templateId(): string
    {
        return 'housekeeping-report-v2';
    }

    public function filterRules(): array
    {
        return $this->singleDateRules('date');
    }

    /**
     * หัวคอลัมน์ checklist จาก config (ข้อยกเว้น sheet-wins §6.2) — นอกนั้นใช้ของ template
     */
    public function columns(array $filters): array
    {
        $labels = config('reporting.cleaning_checks', ['หัวข้อ 1', 'หัวข้อ 2', 'หัวข้อ 3']);

        return collect($this->templateColumns())
            ->map(function (array $col) use ($labels) {
                if (preg_match('/^cleaning_check_([123])$/', $col['key'], $m)) {
                    $col['label_th'] = $labels[(int) $m[1] - 1] ?? $col['label_th'];
                }

                return $col;
            })
            ->all();
    }

    public function rows(array $filters): iterable
    {
        $date = Carbon::parse($filters['date'])->startOfDay();

        $spans = BookingRoom::query()
            ->with(['room', 'roomType'])
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->whereDate('check_in', '<=', $date->copy()->addDay())
            ->whereDate('check_out', '>=', $date)
            ->get()
            ->groupBy('room_id');

        $tasks = HousekeepingTask::query()
            ->whereDate('scheduled_for', $date)
            ->whereIn('status', ['accepted', 'in_progress', 'done'])
            ->get()
            ->groupBy('room_id');

        $maintenanceRoomIds = RoomStatePeriod::query()
            ->activeOn($date)
            ->where('kind', RoomStatePeriod::KIND_MAINTENANCE)
            ->pluck('room_id')
            ->flip();

        return Room::query()
            ->with('roomType')
            ->orderBy('room_number')
            ->get()
            ->map(fn (Room $room) => $this->mapRow($room, $date, $spans->get($room->id), $tasks->get($room->id), $maintenanceRoomIds->has($room->id)));
    }

    public function summary(array $filters): array
    {
        return [];
    }

    private function mapRow(Room $room, Carbon $date, ?$spans, ?$tasks, bool $maintenance): array
    {
        $span = $spans
            ?->first(fn ($br) => $br->status === 'checked_in' && $br->check_in->lte($date) && $br->check_out->gt($date))
            ?? $spans?->first(fn ($br) => $br->check_in->isSameDay($date));

        // สถานะ emoji — OOO ทับทุกสถานะ (ห้องปิดซ่อมไม่นับ IN/OUT/Stay)
        if ($maintenance) {
            $status = '⚫ Out of Order';
        } elseif ($span && $span->check_out->isSameDay($date)) {
            $status = '🔴 OUT';
        } elseif ($span && $span->check_in->isSameDay($date) && $span->status === 'confirmed') {
            $status = '🟢 IN';
        } elseif ($span) {
            $status = '🟡 Stay';
        } else {
            $status = null;
        }

        $task = $tasks?->first();

        return [
            'room_no' => $room->room_number,
            'room_type' => $room->roomType?->name_en,
            'room_status' => $status,
            'arrival' => $span?->check_in->toDateString(),
            'departure' => $span?->check_out->toDateString(),
            'nights' => $span
                ? ((int) $span->check_in->startOfDay()->diffInDays($span->check_out->startOfDay()) ?: 1)
                : null,
            // checklist — tick บันทึกประกอบ ไม่ผูกเงื่อนไขกับ done (v1)
            'cleaning_check_1' => $task?->cleaning_check_1,
            'cleaning_check_2' => $task?->cleaning_check_2,
            'cleaning_check_3' => $task?->cleaning_check_3,
        ];
    }
}
