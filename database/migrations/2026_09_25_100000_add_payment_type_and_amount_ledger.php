<?php

use App\Support\LegacyPaymentBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 💳 (25/09/26) ระบบประเภทการชำระเงิน (wayfinder/booking-payment-types — tickets 01–05)
 *
 *    1. bookings.payment_type = 'full | deposit | deferred' (string ธรรมดาแบบ status อื่น)
 *       NOT NULL DEFAULT 'full' + backfill ทุก row → flow เดิม regression 0%
 *       (ticket 01: ตั้งได้ admin/system เท่านั้น ผ่าน POST /bookings หรือ PUT /bookings/{id})
 *
 *    2. bookings.deposit_amount = ยอดมัดจำ (integer บาท) nullable — null = ระบบคิด 50%
 *       จาก config booking.deposit_percent (ticket 04)
 *
 *    3. booking_confirmations.amount = ยอดที่ user แจ้งต่อครั้งส่งสลิป (integer บาท) nullable —
 *       สลิปเดิมก่อน deploy คง null ตามจริง · สลิปใหม่ required ≥ 1 (ticket 04 ชั้น A)
 *
 *    4. Backfill ledger: booking เดิมสถานะ paid|confirmed = จ่ายเต็ม (ระบบเดิมไม่มีชำระบางส่วน)
 *       → payments row ย้อนหลัง 1 row ต่อ booking = total_amount (ดู App\Support\LegacyPaymentBackfill)
 *
 *    ⚠️ ไม่มี column ยอดใหม่บน bookings สำหรับจ่ายแล้ว/ค้าง — ชั้น B derive จาก payments ledger ล้วน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function ($table) {
            $table->string('payment_type', 20)->default('full')
                ->comment('ประเภทการชำระ: full | deposit | deferred')
                ->after('is_paid');
            $table->integer('deposit_amount')->nullable()
                ->comment('ยอดมัดจำ (integer บาท) — null = คิด 50% จาก config booking.deposit_percent')
                ->after('payment_type');
        });

        Schema::table('booking_confirmations', function ($table) {
            $table->integer('amount')->nullable()
                ->comment('ยอดที่ user แจ้งต่อครั้งส่งสลิป (integer บาท) — สลิปเดิม null')
                ->after('transfer_time');
        });

        LegacyPaymentBackfill::run();
    }

    public function down(): void
    {
        Schema::table('bookings', function ($table) {
            $table->dropColumn(['payment_type', 'deposit_amount']);
        });

        Schema::table('booking_confirmations', function ($table) {
            $table->dropColumn(['amount']);
        });

        // ลบ ledger row ที่ backfill สร้าง (ไม่แตะ row จริงของ flow เงินสด/QR)
        DB::table('payments')
            ->where('reference_number', 'like', 'legacy-backfill:%')
            ->delete();
    }
};
