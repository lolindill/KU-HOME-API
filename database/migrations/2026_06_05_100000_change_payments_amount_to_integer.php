<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ✅ #30 Fixed: เปลี่ยน payments.amount จาก decimal(10,2) เป็น bigint
     * 
     * มาตรฐานใหม่: ทุก amount field ในระบบใช้ integer (satang/cents)
     * - Booking.total_amount = integer ✅
     * - Payment.amount = integer (เปลี่ยนใน migration นี้)
     * - Receipt.amount = integer (เปลี่ยนใน migration ถัดไป)
     */
    public function up(): void
    {
        // แปลงข้อมูลเดิม: decimal (baht) → integer (satang) คูณ 100
        // ถ้ามีข้อมูลอยู่แล้วใน production
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('UPDATE payments SET amount = amount * 100 WHERE amount IS NOT NULL');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('amount')->comment('ยอดเงินที่ชำระ (satang)')->change();
        });
    }

    public function down(): void
    {
        // กลับคืน: integer (satang) → decimal (baht) หาร 100
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('UPDATE payments SET amount = amount / 100 WHERE amount IS NOT NULL');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->comment('ยอดเงินที่ชำระ')->change();
        });
    }
};