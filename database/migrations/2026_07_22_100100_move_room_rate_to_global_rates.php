<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 🌟 Refactor (22/07/26): step 2 — ย้าย rate_daily_general จาก room_types ไป global_rates
 *
 * ทำงานหลัง migration rename (100000):
 *   1. Seed: copy ค่า rate_daily_general ของแต่ละ RoomType ไปเป็น row daily ใน global_rates
 *   2. Drop: ลบคอลัมน์ rate_daily_general ออกจาก room_types (clean break)
 *
 * Idempotent: ใช้ firstOrCreate ตาม (room_type_id, rate_type='daily') จึงรันซ้ำได้
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Seed room rate rows จากค่าเดิมใน room_types
        //    (ใช้ DB facade ตรงๆ เพื่อไม่พึ่งพา Model ที่อาจมี cast ที่ซับซ้อน)
        $roomTypes = DB::table('room_types')->get(['id', 'name_en', 'rate_daily_general']);

        foreach ($roomTypes as $rt) {
            $exists = DB::table('global_rates')
                ->where('room_type_id', $rt->id)
                ->where('rate_type', 'daily')
                ->exists();

            if (! $exists) {
                DB::table('global_rates')->insert([
                    'id' => (string) Str::uuid(),
                    'rate_type' => 'daily',
                    'room_type_id' => $rt->id,
                    'code' => null,
                    'name_en' => $rt->name_en.' Daily',
                    'name_th' => $rt->name_en.' (ราคารายวัน)',
                    'default_price' => (int) $rt->rate_daily_general,
                    'is_active' => DB::raw('TRUE'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 2. Drop คอลัมน์ rate_daily_general จาก room_types
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('rate_daily_general');
        });
    }

    public function down(): void
    {
        // 1. เพิ่มคอลัมน์กลับ
        Schema::table('room_types', function (Blueprint $table) {
            $table->integer('rate_daily_general')->default(0)->after('extra_bed_price');
        });

        // 2. Restore ค่าจาก global_rates daily rows (best-effort)
        $dailyRates = DB::table('global_rates')
            ->where('rate_type', 'daily')
            ->whereNotNull('room_type_id')
            ->get(['room_type_id', 'default_price']);

        foreach ($dailyRates as $rate) {
            DB::table('room_types')
                ->where('id', $rate->room_type_id)
                ->update(['rate_daily_general' => $rate->default_price]);
        }

        // 3. ลบ room rate rows ที่ seed มา (คืน global_rates ให้เป็น addon-only)
        DB::table('global_rates')
            ->where('rate_type', 'daily')
            ->whereNotNull('room_type_id')
            ->delete();
    }
};
