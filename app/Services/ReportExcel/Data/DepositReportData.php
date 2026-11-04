<?php

namespace App\Services\ReportExcel\Data;

use App\Models\Booking;
use App\Services\ReportExcel\BaseReportData;

/**
 * 💳 DepositReportData — รายงานยอดมัดจำ / ยอดค้างชำระ (excel-reports spec §3.1/§3.2)
 *
 *    แถว = booking ที่ payment_type = 'deposit' สร้างในวันที่ filter (ยังไม่ complete/no_show)
 *    · full_amount = total_amount (ชั้น B) · outstanding = total − paid (accessor แมปพี่เลี้ยง)
 *    · filter guest_type (ticket 09): หน่วยงาน = organization_id not null · ทั่วไป = null
 *      · หน่วยงาน/ทั่วไป = ทั้งหมด
 */
class DepositReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'deposit-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->singleDateRules('date'),
            $this->enumFilterRules('guest_type', ['หน่วยงาน/ทั่วไป', 'หน่วยงาน', 'ทั่วไป'], 'หน่วยงาน/ทั่วไป'),
        );
    }

    public function rows(array $filters): iterable
    {
        $guestType = $filters['guest_type'] ?? 'หน่วยงาน/ทั่วไป';

        return Booking::query()
            ->with(['bookingRooms.roomType', 'bookingRooms.room', 'user', 'organization'])
            ->where('payment_type', 'deposit')
            ->whereDate('created_at', $filters['date'])
            ->whereNotIn('status', ['complete', 'no_show'])
            ->when($guestType === 'หน่วยงาน', fn ($q) => $q->whereNotNull('organization_id'))
            ->when($guestType === 'ทั่วไป', fn ($q) => $q->whereNull('organization_id'))
            ->orderBy('confirmation')
            ->get()
            ->flatMap(function (Booking $booking) {
                // 1 แถวต่อห้องของ booking (สอดคล้องชีต — เลขห้องรายห้อง)
                return $booking->bookingRooms->map(fn ($br) => [
                    'booking_date' => $booking->created_at->toDateString(),
                    'booking_no' => $booking->confirmation,
                    'room_no' => $br->room?->room_number ?? $br->room_id,
                    'user_name' => $booking->user?->name ?? $booking->customer_name,
                    'checkin_date' => $br->check_in->toDateString(),
                    'checkout_date' => $br->check_out->toDateString(),
                    'nights' => $br->check_in->startOfDay()->diffInDays($br->check_out->startOfDay()) ?: 1,
                    'full_amount' => (int) $br->amount,
                    'outstanding_amount' => $booking->outstanding_amount,
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
            'full_amount' => array_sum(array_column($rows, 'full_amount')),
            'outstanding_amount' => array_sum(array_column($rows, 'outstanding_amount')),
        ];
    }
}
