<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🌟 (22/10/26): backfill code ของ room rate rows ให้มีค่าเสมอ — เลิกใช้ code = NULL
 *
 * เดิมแถว daily/daily_ku/month เก็บ code = NULL (ใช้ room_type_id + rate_type ระบุตัวตนพอ)
 * ทำให้ wire format มี code: null ให้ frontend ต้องเช็ค null ทุกครั้ง
 * ตอนนี้บังคับ code = rate_type ('daily' | 'daily_ku' | 'month') ให้ทุกแถว self-describing
 * (แถว group มี code min_5_rooms/min_10_rooms อยู่แล้ว · แถว addon คง code เดิม)
 *
 * คู่กับ: GlobalRate::getRoomRate() lookup แบบ tolerant (code = rate_type OR NULL — กันช่วง
 * transition ระหว่าง deploy โค้ดใหม่ก่อน migrate) และ GlobalRateController::update()
 * ที่ force code = rate_type ให้เองเสมอ
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('global_rates')
            ->whereNull('code')
            ->whereIn('rate_type', ['daily', 'daily_ku', 'month'])
            ->update(['code' => DB::raw('rate_type')]);
    }

    public function down(): void
    {
        DB::table('global_rates')
            ->whereIn('rate_type', ['daily', 'daily_ku', 'month'])
            ->whereColumn('code', 'rate_type')
            ->update(['code' => null]);
    }
};
