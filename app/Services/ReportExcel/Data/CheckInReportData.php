<?php

namespace App\Services\ReportExcel\Data;

use App\Models\BookingRoom;
use App\Services\ReportExcel\BaseReportData;
use App\Services\ReportExcel\Support\AddonNote;

/**
 * 🏨 CheckInReportData — รายงานห้องเข้าพัก (excel-reports spec §3.1/§3.2)
 *
 *    แถว = booking_room ที่เข้าพักจริง (checked_in/checked_out — มีเลขห้อง) โดยวันเช็คอิน = filter
 *    · 2 section: "Fully Paid" / "deposit/ยังไม่ได้ชำระ" (ตามชีต — แถว bold ไม่ merge)
 *    · special-case ตามต้นทาง (ticket 09): แถว inter-unit (organization_id not null)
 *      จัด section Fully Paid เสมอ — หน่วยงานรับผิดชอบผ่าน ERP ไม่ใช่ค้างชำระของแขก
 *    · is_inter_unit_transfer = derive จาก organization_id ไม่เพิ่ม flag
 *    · special_request ระดับ booking — โชว์ซ้ำทุกแถวห้องของ booking เดียวกัน
 *    · ช่องยอดเงินเป็น booking-level (ชั้น B derive จากแมป booking-payment-types)
 */
class CheckInReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'check-in-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->singleDateRules('checkin_date'),
            ['room_type' => ['nullable', 'string', 'max:100']],
        );
    }

    public function rows(array $filters): iterable
    {
        $rooms = BookingRoom::query()
            ->with(['booking.organization', 'roomType', 'addon', 'room'])
            ->whereIn('booking_rooms.status', ['checked_in', 'checked_out'])
            ->whereNotNull('booking_rooms.room_id')
            ->whereDate('booking_rooms.check_in', $filters['checkin_date'])
            ->join('bookings', 'bookings.id', '=', 'booking_rooms.booking_id')
            ->when(! empty($filters['room_type']) && $filters['room_type'] !== 'ทุกประเภท', function ($q) use ($filters) {
                $q->whereHas('roomType', fn ($rt) => $rt->where('name_en', $filters['room_type']));
            })
            ->orderBy('bookings.confirmation')
            ->select('booking_rooms.*')
            ->get();

        // แบ่ง section ตามชีต — inter-unit จัด Fully Paid เสมอ (ticket 09)
        $fullyPaid = $rooms->filter(fn ($br) => $this->isInterUnit($br) || $br->booking->paid_amount >= $br->booking->total_amount);
        $depositUnpaid = $rooms->reject(fn ($br) => $this->isInterUnit($br) || $br->booking->paid_amount >= $br->booking->total_amount);

        foreach ([['Fully Paid', $fullyPaid], ['deposit/ยังไม่ได้ชำระ', $depositUnpaid]] as [$label, $group]) {
            if ($group->isEmpty()) {
                continue;
            }

            yield ['_section' => $label];

            foreach ($group as $br) {
                yield $this->mapRow($br);
            }
        }
    }

    public function summary(array $filters): array
    {
        return [];
    }

    private function mapRow(BookingRoom $br): array
    {
        $booking = $br->booking;

        return [
            'booking_no' => $booking->confirmation,
            'room_no' => $br->room?->room_number ?? $br->room_id,
            'is_inter_unit_transfer' => $this->isInterUnit($br),
            'guest_name' => $booking->primary_guest_name,
            'room_type' => $br->roomType?->name_en,
            'checkin_date' => $br->check_in->toDateString(),
            'checkout_date' => $br->check_out->toDateString(),
            'nights' => $br->check_in->startOfDay()->diffInDays($br->check_out->startOfDay()) ?: 1,
            'full_price' => (int) $br->amount,
            'paid_amount' => $booking->paid_amount,
            'outstanding_amount' => $booking->outstanding_amount,
            'special_request' => $booking->special_request,
            'early_checkin_extra_bed_note' => AddonNote::make($br),
        ];
    }

    private function isInterUnit(BookingRoom $br): bool
    {
        return $br->booking->organization_id !== null;
    }
}
