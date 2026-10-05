<?php

namespace App\Services\ReportExcel\Data;

use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Services\ReportExcel\BaseReportData;
use Carbon\Carbon;

/**
 * 🛏️ RoomStatusReportData — รายงานสถานะห้องพัก real-time (excel-reports spec §2.8, ticket 11)
 *
 *    แถว = ห้องทุกห้อง ณ เวลาที่ export · legend **5 ค่า** (VIP ถูก defer — จดใน map):
 *    OCC = มีแขกอยู่ (BR checked_in ครอบคลุมวันนี้) · OOO = period ซ่อม active ·
 *    EA = ว่างรอแขกมาถึง (confirmed มาถึงวันนี้) · VD = ว่างสกปรก (rooms.status dirty) ·
 *    VC = ว่างสะอาด (available/prep_checkin)
 *    ลำดับตัดสิน: OOO > OCC > EA > VD > VC
 */
class RoomStatusReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'room-status-report';
    }

    public function filterRules(): array
    {
        return ['status' => ['nullable', 'string', 'in:ทุกสถานะ,OCC,OOO,EA,VD,VC']];
    }

    public function rows(array $filters): iterable
    {
        $today = Carbon::today();

        $spans = BookingRoom::query()
            ->with(['booking', 'roomType'])
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->whereDate('check_in', '<=', $today->copy()->addDay())
            ->whereDate('check_out', '>', $today)
            ->get()
            ->groupBy('room_id');

        $maintenance = RoomStatePeriod::query()
            ->activeOn($today)
            ->where('kind', RoomStatePeriod::KIND_MAINTENANCE)
            ->pluck('room_id')
            ->flip();

        return Room::query()
            ->with('roomType')
            ->orderBy('room_number')
            ->get()
            ->map(fn (Room $room) => $this->mapRow($room, $today, $spans->get($room->id), $maintenance->has($room->id)))
            ->filter(function (array $row) use ($filters) {
                $want = $filters['status'] ?? 'ทุกสถานะ';

                return $want === 'ทุกสถานะ' || $want === '' || $row['status'] === $want;
            })
            ->values();
    }

    public function summary(array $filters): array
    {
        return [];
    }

    private function mapRow(Room $room, Carbon $today, ?$spans, bool $maintenance): array
    {
        // span ที่กำลังเข้าพักจริง (checked_in ครอบคลุมวันนี้) ก่อน แล้วค่อย confirmed ที่มาถึงวันนี้
        $occupied = $spans?->first(fn ($br) => $br->status === 'checked_in' && $br->check_in->lte($today) && $br->check_out->gt($today));
        $arriving = $occupied
            ? null
            : $spans?->first(fn ($br) => $br->status === 'confirmed' && $br->check_in->isSameDay($today));

        [$status, $span] = match (true) {
            $maintenance => ['OOO', null],
            $occupied !== null => ['OCC', $occupied],
            $arriving !== null => ['EA', $arriving],
            $room->status === 'dirty' => ['VD', null],
            default => ['VC', null],
        };

        return [
            'room_no' => $room->room_number,
            'room_type' => $room->roomType?->name_en,
            'status' => $status,
            'guest_names' => $span
                ? collect($span->booking->guests ?? [])->pluck('name')->filter()->implode(', ')
                : null,
            'arrival' => $span?->check_in->toDateString(),
            'departure' => $span?->check_out->toDateString(),
            'nights' => $span
                ? ((int) $span->check_in->startOfDay()->diffInDays($span->check_out->startOfDay()) ?: 1)
                : null,
            'note' => $span?->booking?->special_request,
        ];
    }
}
