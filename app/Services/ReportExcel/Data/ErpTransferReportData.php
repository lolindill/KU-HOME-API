<?php

namespace App\Services\ReportExcel\Data;

use App\Models\Booking;
use App\Services\ReportExcel\BaseReportData;

/**
 * 🏛️ ErpTransferReportData — รายงานการโอนระหว่างหน่วยงาน (excel-reports spec §3.2, ticket 09)
 *
 *    แถว = booking_room ของ org booking (organization_id not null — deferred เสมอตาม
 *    แมป booking-payment-types) ที่สร้างในช่วง date_range · filter agency_code =
 *    organizations.erp ("ทั้งหมด" = ไม่กรอง)
 *    · agency_name = organizations.name · erp_code = organizations.erp — ไม่เพิ่ม storage
 *    · comment = bookings.comment (หมายเหตุฝั่ง admin/บัญชี — แยกจาก special_request)
 */
class ErpTransferReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'erp-transfer-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->dateRangeRules('date_from', 'date_to'),
            ['agency_code' => ['nullable', 'string', 'max:100']],
        );
    }

    public function rows(array $filters): iterable
    {
        $agency = trim((string) ($filters['agency_code'] ?? ''));

        return Booking::query()
            ->with(['organization', 'bookingRooms.roomType', 'bookingRooms.room'])
            ->whereNotNull('organization_id')
            ->whereDate('created_at', '>=', $filters['date_from'])
            ->whereDate('created_at', '<=', $filters['date_to'])
            ->when($agency !== '' && $agency !== 'ทั้งหมด', function ($q) use ($agency) {
                $q->whereHas('organization', fn ($org) => $org->where('erp', $agency));
            })
            ->orderBy('confirmation')
            ->get()
            ->flatMap(function (Booking $booking) {
                return $booking->bookingRooms->map(fn ($br) => [
                    'date' => $booking->created_at->toDateString(),
                    'booking_no' => $booking->confirmation,
                    'room_no' => $br->room?->room_number ?? $br->room_id,
                    'agency_name' => $booking->organization?->name,
                    'erp_code' => $booking->organization?->erp,
                    'checkin_date' => $br->check_in->toDateString(),
                    'checkout_date' => $br->check_out->toDateString(),
                    'nights' => $br->check_in->startOfDay()->diffInDays($br->check_out->startOfDay()) ?: 1,
                    'price' => (int) $br->amount,
                    'comment' => $booking->comment,
                ]);
            });
    }

    public function summary(array $filters): array
    {
        $rows = iterator_to_array($this->rows($filters));

        if ($rows === []) {
            return [];
        }

        return [
            '_label' => 'รวม '.count($rows).' รายการ',
            'price' => array_sum(array_column($rows, 'price')),
        ];
    }
}
