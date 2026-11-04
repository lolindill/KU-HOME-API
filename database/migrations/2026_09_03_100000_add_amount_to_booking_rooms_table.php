<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🧾 (03/09/26) เพิ่ม `booking_rooms.amount` = ยอดสุทธิต่อห้อง (satang):
     *    amount = room_amount − discount_amount + addon 4 รายการ
     *    → invariant Σ booking_rooms.amount == bookings.total_amount (บังคับด้วย test เท่านั้น)
     *    ไม่มี backfill — ยังไม่มีข้อมูลจริง (T3, wayfinder booking-room-amount)
     */
    public function up(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->integer('amount')->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
