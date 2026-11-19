<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🍳 (19/11/26) breakfast reshape — toggle + เรทรายหัว (breaking wire-format)
 *
 *    เดิม: breakfast_set_100 / breakfast_set_200 (int จำนวนชุด)
 *          ยอด = (set_100 × เรท 100 + set_200 × เรท 200) × คืน
 *    ใหม่: breakfast_enabled (bool toggle) + breakfast_code (เลือก 1 เรทจาก
 *          global_rates แถว code ขึ้นต้น 'breakfast' — รองรับ 2 เรทขึ้นไป)
 *          ยอด = rate(breakfast_code) × คืน × max(1, จำนวนผู้เข้าพักในห้อง)
 *
 *    data migration:
 *      · set_200 > 0 → enabled TRUE + code 'breakfast_200' (เรทเดิมของ breakfast int)
 *      · set_100 > 0 (set_200 = 0) → enabled TRUE + code 'breakfast_100'
 *      · ที่เหลือ → enabled FALSE (default)
 *
 *    global_rates ไม่แตะ — breakfast_100/breakfast_200 ยัง active อยู่เดิม
 *    (เพิ่มเรทใหม่อนาคต = insert แถว code ขึ้นต้น 'breakfast' ใช้ได้ทันที)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->boolean('breakfast_enabled')->default(false)
                ->after('breakfast_set_200');
            $table->string('breakfast_code')->nullable()
                ->after('breakfast_enabled');
        });

        // ⚠️ PgBoolean gotcha — DB::table update เขียน boolean เป็น SQL literal (DB::raw)
        //    ไม่ใช่ PHP true ที่ PDO แปลงเป็น integer 1 แล้ว PostgreSQL ปฏิเสธ
        DB::table('addons')
            ->where('breakfast_set_200', '>', 0)
            ->update([
                'breakfast_enabled' => DB::raw('TRUE'),
                'breakfast_code' => 'breakfast_200',
            ]);

        DB::table('addons')
            ->where('breakfast_set_200', 0)
            ->where('breakfast_set_100', '>', 0)
            ->update([
                'breakfast_enabled' => DB::raw('TRUE'),
                'breakfast_code' => 'breakfast_100',
            ]);

        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn(['breakfast_set_100', 'breakfast_set_200']);
        });
    }

    public function down(): void
    {
        // best-effort rollback: toggle → 1 ชุดของ code นั้น (จำนวนชุดเดิมหาคืนไม่ได้)
        Schema::table('addons', function (Blueprint $table) {
            $table->integer('breakfast_set_100')->default(0)
                ->after('breakfast_code');
            $table->integer('breakfast_set_200')->default(0)
                ->after('breakfast_set_100');
        });

        DB::table('addons')
            ->where('breakfast_enabled', DB::raw('TRUE'))
            ->where('breakfast_code', 'breakfast_200')
            ->update(['breakfast_set_200' => 1]);

        DB::table('addons')
            ->where('breakfast_enabled', DB::raw('TRUE'))
            ->where('breakfast_code', 'breakfast_100')
            ->update(['breakfast_set_100' => 1]);

        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn(['breakfast_enabled', 'breakfast_code']);
        });
    }
};
