<?php

namespace App\Services\ReportExcel\Data;

use App\Models\BookingConfirmation;
use App\Models\Payment;
use App\Services\ReportExcel\BaseReportData;
use Illuminate\Support\Str;

/**
 * 💰 DailyFinancialReportData — รายงานการเงินประจำวัน (excel-reports spec §3.1 + §6.1)
 *
 *    แถว = payments ledger สถานะ completed ที่วันเขียน ledger = filter (1 row = 1 เหตุการณ์เงิน)
 *
 *    · **ที่มาเงิน 2 ค่า (sheet-wins exception ที่ owner อนุมัติ §6.1)** — derive จากจุดเขียน
 *      ledger ไม่เพิ่ม column: reference_number = UUID ของ booking_confirmations → "สลิป (โอน/QR)"
 *      (verify สลิป) · อื่น ๆ (recordPayment หน้าเคาน์เตอร์ / legacy-backfill ระบบเดิม) → "เงินสด"
 *    · payment_status ตามชีต: payment_type full|deferred → "เต็มจำนวน" · deposit → "มัดจำ 50%"
 *    · receipt_ref = "-" ทุกแถว (receipts FROZEN รอระบบใบเสร็จใหม่ — ห้ามใช้ payments.reference_number)
 */
class DailyFinancialReportData extends BaseReportData
{
    public const CHANNEL_SLIP = 'สลิป (โอน/QR)';

    public const CHANNEL_CASH = 'เงินสด';

    public function templateId(): string
    {
        return 'daily-financial-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->singleDateRules('date'),
            $this->enumFilterRules('payment_channel', [self::CHANNEL_SLIP, self::CHANNEL_CASH], 'ทั้งหมด'),
        );
    }

    public function rows(array $filters): iterable
    {
        $channel = $filters['payment_channel'] ?? 'ทั้งหมด';

        $payments = Payment::query()
            ->with(['booking.bookingRooms.room', 'booking.user', 'booking.organization'])
            ->where('status', 'completed')
            ->whereDate('created_at', $filters['date'])
            ->orderBy('created_at')
            ->get();

        // map confirmation UUID → เป็นหลักจำแนกสลิป (จุดเขียน verify ใส่ confirmation id ไว้)
        $confirmationIds = BookingConfirmation::whereIn('id', $payments->pluck('reference_number')->filter()->unique())
            ->pluck('id')
            ->flip();

        $slipFirst = $channel === self::CHANNEL_SLIP;
        $cashFirst = $channel === self::CHANNEL_CASH;

        return $payments
            ->map(fn (Payment $payment) => $this->mapRow($payment, $confirmationIds))
            ->filter(fn (array $row) => ! $slipFirst || $row['payment_channel'] === self::CHANNEL_SLIP)
            ->filter(fn (array $row) => ! $cashFirst || $row['payment_channel'] === self::CHANNEL_CASH)
            ->values();
    }

    public function summary(array $filters): array
    {
        $rows = iterator_to_array($this->rows($filters));

        if ($rows === []) {
            return [];
        }

        return [
            '_label' => 'รวม '.count($rows).' ธุรกรรม',
            'received_amount' => array_sum(array_column($rows, 'received_amount')),
        ];
    }

    private function mapRow(Payment $payment, $confirmationIds): array
    {
        $booking = $payment->booking;
        $rooms = $booking->bookingRooms;

        $checkIn = $rooms->min('check_in');
        $checkOut = $rooms->max('check_out');

        return [
            'date' => $payment->created_at->toDateString(),
            'time' => $payment->created_at->format('H:i'),
            'booking_no' => $booking->confirmation,
            'room_no' => $rooms->map(fn ($br) => $br->room?->room_number ?? $br->room_id)->implode(', '),
            'user_name' => $booking->user?->name ?? $booking->customer_name,
            'checkin_date' => $checkIn ? \Carbon\Carbon::parse($checkIn)->toDateString() : null,
            'checkout_date' => $checkOut ? \Carbon\Carbon::parse($checkOut)->toDateString() : null,
            'nights' => $checkIn && $checkOut
                ? ((int) \Carbon\Carbon::parse($checkIn)->startOfDay()->diffInDays(\Carbon\Carbon::parse($checkOut)->startOfDay()) ?: 1)
                : null,
            'received_amount' => (int) $payment->amount,
            'payment_status' => $booking->payment_type === 'deposit' ? 'มัดจำ 50%' : 'เต็มจำนวน',
            'payment_channel' => $this->channel($payment, $confirmationIds),
            'receipt_ref' => '-', // รอระบบใบเสร็จใหม่ (receipts FROZEN) — จดหมายเหตุใน template
            'slip_photo' => $this->channel($payment, $confirmationIds) === self::CHANNEL_SLIP ? 'แนบสลิป' : null,
        ];
    }

    /**
     * derive ที่มาเงิน — verify สลิปใส่ confirmation UUID ไว้ใน reference_number เสมอ
     * (recordPayment = เงินสด: ref เป็นข้อความอิสระ/null · legacy-backfill = เงินสดเดิมหน้าเคาน์เตอร์)
     */
    private function channel(Payment $payment, $confirmationIds): string
    {
        $ref = (string) ($payment->reference_number ?? '');

        return $ref !== '' && Str::isUuid($ref) && $confirmationIds->has($ref)
            ? self::CHANNEL_SLIP
            : self::CHANNEL_CASH;
    }
}
