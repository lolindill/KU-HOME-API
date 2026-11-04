<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🌟 Refactor (24/07/26): Booking Confirmation Table (1:N with bookings)
     *
     * แยก payment info ออกจาก bookings container → table ใหม่เก็บ history ทุกครั้ง
     * - 1 booking มีได้หลาย confirmation rows (audit trail)
     * - state machine ของตัวเอง: pending → verified | rejected
     * - แทนที่ payments/receipts table ที่ถูก freeze แล้ว (legacy read-only)
     */
    public function up(): void
    {
        Schema::create('booking_confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 🔗 1:N with bookings — ไม่ unique (เก็บ history ทุกครั้ง แม้ reject)
            $table->foreignUuid('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();

            // 💳 Payment info (snapshot จาก slip ที่ user ส่ง)
            $table->string('payment_method')->nullable()
                ->comment('cash|credit_card|transfer');
            $table->string('slip_image')->nullable()
                ->comment('path ของไฟล์สลิป (storage/app/public/slips/...)');
            $table->timestamp('transfer_time')->nullable()
                ->comment('เวลาที่ลูกค้าแจ้งโอน (จากสลิป)');

            // 🚦 State machine: pending → verified | rejected (ทั้งคู่ terminal)
            $table->string('status')->default('pending')
                ->comment('pending|verified|rejected');

            // 🔍 Audit: admin ที่ review + เวลา + เหตุผล
            $table->foreignUuid('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable()->comment('เหตุผล reject (optional)');

            $table->timestamps();

            // 🚀 Index สำหรับ query "pending ล่าสุดของ booking" + dashboard admin list
            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_confirmations');
    }
};
