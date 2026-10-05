<?php

namespace App\Services\ReportExcel\Data;

use App\Models\BookingRoom;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Services\ReportExcel\BaseReportData;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 🧹 HousekeepingReportData (v1) — รายงานแม่บ้าน (excel-reports spec §2.8, ticket 11)
 *
 *    แถว = ห้องพักทุกห้อง ณ วันที่ filter · คอลัมน์ตามชีต v1:
 *    - house_status: available→Clean · dirty→Dirty · prep_checkin→Inspected (derive —
 *      default รอ sign-off spec §9 · ไม่แตะ state machine) · period ซ่อม active → Out of Order
 *    - room_status_detail ตาม legend ชีต: Arrival / Due Out / ห้องว่างและไม่มีเข้าพัก / ปิดซ่อม
 *    - arrival/departure/nights = span ของ BR ที่ครอบคลุม/มาถึงวันนั้น (ห้องว่าง = null)
 *    - housekeeping_note = note ของ task ล่าสุดที่ scheduled_for = วัน filter
 */
class HousekeepingReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'housekeeping-report';
    }

    public function filterRules(): array
    {
        return $this->singleDateRules('date');
    }

    public function rows(array $filters): iterable
    {
        $date = Carbon::parse($filters['date'])->startOfDay();

        // spans ที่เกี่ยวกับวันนั้น: ครอบคลุมวันนั้น (stay/ดิวเอาท์) หรือมาถึงวันนั้น
        $spans = BookingRoom::query()
            ->with(['room', 'roomType'])
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->whereDate('check_in', '<=', $date->copy()->addDay())
            ->whereDate('check_out', '>=', $date)
            ->get()
            ->groupBy('room_id');

        $tasks = HousekeepingTask::query()
            ->whereDate('scheduled_for', $date)
            ->get()
            ->groupBy('room_id');

        return Room::query()
            ->with('roomType')
            ->orderBy('room_number')
            ->get()
            ->map(fn (Room $room) => $this->mapRow($room, $date, $spans->get($room->id), $tasks->get($room->id)));
    }

    public function summary(array $filters): array
    {
        return [];
    }

    private function mapRow(Room $room, Carbon $date, ?Collection $spans, ?Collection $tasks): array
    {
        // span ที่ใช้แสดง: ครอบคลุมวันนั้นก่อน (stay/due out) ไม่งั้น span ที่มาถึงวันนั้น
        $span = $spans
            ?->first(fn ($br) => $br->status !== 'confirmed' && $br->check_in->lte($date) && $br->check_out->gt($date))
            ?? $spans?->first(fn ($br) => $br->check_in->isSameDay($date));

        $maintenance = RoomStatePeriod::where('room_id', $room->id)
            ->activeOn($date)
            ->where('kind', RoomStatePeriod::KIND_MAINTENANCE)
            ->exists();

        if ($maintenance) {
            $detail = 'ปิดซ่อม';
        } elseif ($span && $span->check_out->isSameDay($date)) {
            $detail = 'Due Out';
        } elseif ($span) {
            $detail = $span->check_in->isSameDay($date) ? 'Arrival / Due In / Vacant Dirty' : 'Arrival / Due In / Vacant Dirty';
        } elseif ($room->status === 'dirty') {
            $detail = 'ห้องว่างและไม่มีเข้าพัก (Vacant Dirty)';
        } else {
            $detail = 'ห้องว่างและไม่มีเข้าพัก';
        }

        $task = $tasks?->first();

        return [
            'room_no' => $room->room_number,
            'room_status' => $span && $span->status !== 'checked_out' ? 'Occupied' : 'Vacant',
            'room_status_detail' => $detail,
            'house_status' => $this->houseStatus($room, $maintenance),
            'arrival' => $span?->check_in->toDateString(),
            'departure' => $span?->check_out->toDateString(),
            'nights' => $span
                ? ((int) $span->check_in->startOfDay()->diffInDays($span->check_out->startOfDay()) ?: 1)
                : null,
            'housekeeping_note' => $task?->notes,
        ];
    }

    /**
     * Inspected = derive จาก prep_checkin (spec §2.8 — default รอ sign-off §9)
     * Out of Order จาก period ซ่อม active (ไม่ใช่ lifecycle ห้อง — ถูกถอดจาก machine แล้ว)
     */
    private function houseStatus(Room $room, bool $maintenance): string
    {
        if ($maintenance) {
            return 'Out of Order';
        }

        return match ($room->status) {
            'dirty' => 'Dirty',
            'prep_checkin' => 'Inspected',
            default => 'Clean',
        };
    }
}
