<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ✅ #30 Fixed: เปลี่ยน receipts.amount จาก decimal(10,2) เป็น bigint
     * 
     * มาตรฐานเดียวกัน: integer (satang/cents) ทุก amount field
     */
    public function up(): void
    {
        // แปลงข้อมูลเดิม: decimal (baht) → integer (satang) คูณ 100
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('UPDATE receipts SET amount = amount * 100 WHERE amount IS NOT NULL');
        }

        Schema::table('receipts', function (Blueprint $table) {
            $table->bigInteger('amount')->comment('ยอดเงินในใบเสร็จ (satang)')->change();
        });
    }

    public function down(): void
    {
        // กลับคืน: integer (satang) → decimal (baht) หาร 100
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('UPDATE receipts SET amount = amount / 100 WHERE amount IS NOT NULL');
        }

        Schema::table('receipts', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->comment('ยอดเงินในใบเสร็จ')->change();
        });
    }
};