<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 💳 (25/09/26) Backfill payments ledger ย้อนหลังสำหรับ booking เดิม (booking-payment-types ticket 04)
 *
 * สมมติฐาน: ระบบเดิมไม่มีชำระบางส่วน — booking ที่ paid|confirmed แล้ว = จ่ายเต็ม total_amount
 * → สร้าง payments row ย้อนหลัง 1 row ต่อ booking (reference_number = 'legacy-backfill:{id}')
 * booking draft|pending|verify_error ไม่มี row (ยังไม่จ่ายจริง)
 *
 * แยกเป็น named class (ไม่ใช่ anonymous ใน migration) เพื่อให้ test เรียกซ้ำจำลองได้
 * และ idempotent — booking ที่มี payments row อยู่แล้วข้าม
 */
class LegacyPaymentBackfill
{
    public static function run(): void
    {
        if (! Schema::hasTable('bookings') || ! Schema::hasTable('payments')) {
            return;
        }

        $now = now();

        $paidBookings = DB::table('bookings')
            ->whereIn('status', ['paid', 'confirmed'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('payments')
                    ->whereColumn('payments.booking_id', 'bookings.id');
            })
            ->get(['id', 'total_amount']);

        foreach ($paidBookings as $booking) {
            DB::table('payments')->insert([
                'id' => (string) Str::uuid(),
                'booking_id' => $booking->id,
                'amount' => $booking->total_amount,
                'status' => 'completed',
                'reference_number' => 'legacy-backfill:'.$booking->id,
                'received_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
