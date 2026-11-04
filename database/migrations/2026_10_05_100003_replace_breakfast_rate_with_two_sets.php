<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 📊 (05/10/26) excel-reports spec §2.3 — เรทอาหารเช้าแยก 2 ชุด (ticket 10)
 *
 *    เพิ่ม code breakfast_100 (100 บาท) + breakfast_200 (200 บาท) แทน code breakfast เดิม
 *    (GlobalRateSeeder อัปเดตตามใน release เดียวกัน) · code extra_bed คงเดิมเป็น pricing source
 *    ของ extra-bed — room_types.extra_bed_price คงสถานะ display-only
 *
 *    breakfast row เดิมถูกปิดใช้งาน (คงไว้ดูอดีต — ไม่ลบ)
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ⚠️ PgBoolean gotcha — DB::table insert เขียน boolean เป็น SQL literal (DB::raw)
        //    ไม่ใช่ PHP true ที่ PDO แปลงเป็น integer 1 แล้ว PostgreSQL ปฏิเสธ
        DB::table('global_rates')->insert([
            [
                'id' => Str::uuid(),
                'rate_type' => 'addon',
                'room_type_id' => null,
                'code' => 'breakfast_100',
                'name_en' => 'Breakfast Set 100',
                'name_th' => 'อาหารเช้าชุด 100',
                'default_price' => 100,
                'is_active' => DB::raw('TRUE'),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => Str::uuid(),
                'rate_type' => 'addon',
                'room_type_id' => null,
                'code' => 'breakfast_200',
                'name_en' => 'Breakfast Set 200',
                'name_th' => 'อาหารเช้าชุด 200',
                'default_price' => 200,
                'is_active' => DB::raw('TRUE'),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // เรทเดิมปิดใช้งาน — code คงอยู่เพื่อความต่อเนื่องของข้อมูล (ไม่มี FK อ้างอิง)
        DB::table('global_rates')
            ->where('code', 'breakfast')
            ->where('rate_type', 'addon')
            ->update(['is_active' => DB::raw('FALSE'), 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('global_rates')->whereIn('code', ['breakfast_100', 'breakfast_200'])->delete();

        DB::table('global_rates')
            ->where('code', 'breakfast')
            ->where('rate_type', 'addon')
            ->update(['is_active' => DB::raw('TRUE')]);
    }
};
