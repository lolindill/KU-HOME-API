<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🌟 Refactor (19/08/26): ลบ payment_method ออกจากทุก flow
     *
     * Flow การชำระเงินเหลือ "ส่งสลิป → รอแอดมินตรวจ" เท่านั้น
     * - booking_confirmations: slip + transfer_time (optional) ไม่ต้องแยกช่องทางแล้ว
     * - payments: ปลด freeze เพื่อลบ column นี้ (receipts ยัง frozen เหมือนเดิม)
     */
    public function up(): void
    {
        Schema::table('booking_confirmations', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }

    public function down(): void
    {
        // คืนเป็น nullable ทั้งคู่ — คืนแบบ NOT NULL จะพังถ้าตารางมี rows อยู่
        Schema::table('booking_confirmations', function (Blueprint $table) {
            $table->string('payment_method')->nullable()
                ->comment('cash|credit_card|transfer');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_method')->nullable()
                ->comment('ช่องทางชำระ เช่น cash, credit_card, transfer');
        });
    }
};
