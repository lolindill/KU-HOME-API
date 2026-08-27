<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            // 🕐 (27/08/26): สูตรรายชั่วโมง — เก็บจำนวนชั่วโมง early/late รายห้อง (0 = ไม่ใช้)
            $table->integer('early_hours')->default(0)->after('early_checkIn_price');
            $table->integer('late_hours')->default(0)->after('late_checkOut_price');
        });

        // แถวเดิมที่เคยจ่ายค่า flat ไปแล้ว = ย้อนถือว่าใช้ 1 ชม. (ราคา frozen เดิม = 1 ชม. × rate สมัยนั้น)
        DB::table('addons')->where('early_checkIn_price', '>', 0)->update(['early_hours' => 1]);
        DB::table('addons')->where('late_checkOut_price', '>', 0)->update(['late_hours' => 1]);
    }

    public function down(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn(['early_hours', 'late_hours']);
        });
    }
};
