<?php

namespace App\Services\ReportExcel\Data;

use App\Models\BookingRoom;
use App\Services\ReportExcel\BaseReportData;
use App\Services\ReportExcel\Support\AddonNote;

/**
 * 🏨 CheckOutReportData — รายงานห้องออก (excel-reports spec §3.1)
 *
 *    แถว = booking_room สถานะ checked_out ที่วันเช็คเอาท์ = filter (รันย้อนหลังได้)
 *    · ช่องยอดเงินเป็น booking-level (ชั้น B derive จากแมป booking-payment-types)
 *    · summary = นับจำนวนห้องตามประเภท (ตามชีต by_room_type) — วางเป็นข้อความเดียว
 */
class CheckOutReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'check-out-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->singleDateRules('checkout_date'),
            ['room_type' => ['nullable', 'string', 'max:100']],
        );
    }

    public function rows(array $filters): iterable
    {
        return BookingRoom::query()
            ->with(['booking.organization', 'roomType', 'addon', 'room'])
            ->where('booking_rooms.status', 'checked_out')
            ->whereNotNull('booking_rooms.room_id')
            ->whereDate('booking_rooms.check_out', $filters['checkout_date'])
            ->join('bookings', 'bookings.id', '=', 'booking_rooms.booking_id')
            ->when(! empty($filters['room_type']) && $filters['room_type'] !== 'ทุกประเภท', function ($q) use ($filters) {
                $q->whereHas('roomType', fn ($rt) => $rt->where('name_en', $filters['room_type']));
            })
            ->orderBy('bookings.confirmation')
            ->select('booking_rooms.*')
            ->get()
            ->map(fn (BookingRoom $br) => $this->mapRow($br));
    }

    public function summary(array $filters): array
    {
        $counts = BookingRoom::query()
            ->where('booking_rooms.status', 'checked_out')
            ->whereDate('booking_rooms.check_out', $filters['checkout_date'])
            ->when(! empty($filters['room_type']) && $filters['room_type'] !== 'ทุกประเภท', function ($q) use ($filters) {
                $q->whereHas('roomType', fn ($rt) => $rt->where('name_en', $filters['room_type']));
            })
            ->join('room_types', 'room_types.id', '=', 'booking_rooms.room_type_id')
            ->groupBy('room_types.name_en')
            ->selectRaw('room_types.name_en, COUNT(*) as total')
            ->pluck('total', 'name_en');

        if ($counts->isEmpty()) {
            return [];
        }

        $byType = $counts->map(fn ($n, $type) => "{$type} {$n}")->implode(' · ');

        return [
            '_label' => 'รวม '.$counts->sum().' ห้อง',
            'room_type' => $byType,
        ];
    }

    private function mapRow(BookingRoom $br): array
    {
        $booking = $br->booking;

        return [
            'booking_no' => $booking->confirmation,
            'room_no' => $br->room?->room_number ?? $br->room_id,
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
}
