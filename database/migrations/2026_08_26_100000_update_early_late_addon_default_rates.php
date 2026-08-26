<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🌟 (26/08/26): ปรับ default rate addon early_checkin / late_checkout 300 → 100 THB
 *
 * Data migration (ไม่แต้ schema) — เพื่อให้ DB ที่ seed ไปแล้วได้ค่าใหม่โดยไม่ต้อง
 * migrate:fresh --seed (ค่าเดิม 30000 satang → 10000 satang ทั้งสอง code)
 *
 * หมายเหตุ: กระทบเฉพาะ rate ตั้งต้นใน global_rates เท่านั้น
 * Addon rows ที่คิดราคาแล้วใน `addons` table (ราย booking room) ไม่ถูกแตะ
 * ตามนโยบาย "ราคาคิดตอนสร้าง/แก้ไขจาก rate ปัจจุบัน แล้ว freeze ไว้ที่ Addon row"
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('global_rates')
            ->where('rate_type', 'addon')
            ->whereIn('code', ['early_checkin', 'late_checkout'])
            ->update(['default_price' => 10000, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('global_rates')
            ->where('rate_type', 'addon')
            ->whereIn('code', ['early_checkin', 'late_checkout'])
            ->update(['default_price' => 30000, 'updated_at' => now()]);
    }
};
