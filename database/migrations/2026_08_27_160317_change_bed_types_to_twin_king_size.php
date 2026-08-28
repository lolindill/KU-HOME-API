<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🏨 เปลี่ยนชุดค่า bed_type / bed_preference ใหม่ทั้งระบบ
 *
 *    เดิม: rooms.bed_type = 'double' | 'twin'      (ชั้น 8 = twin, ชั้นอื่น = double)
 *    ใหม่: rooms.bed_type = 'twin'   | 'king_size' (ชั้น 8 = king_size, ชั้นอื่น = twin)
 *
 *    - booking_rooms.bed_preference 'twin' → 'king_size' (null = any คงเดิม)
 *      mirror ของระบบเดิมเป๊ะ: ขอได้เฉพาะชนิดพิเศษของชั้น 8 เท่านั้น
 *    - ขยาย varchar(8) → varchar(16) เฉพาะ PostgreSQL เพราะ 'king_size' ยาว 9 ตัวอักษร
 *      (SQLite ไม่ enforce ความยาว varchar — จึง no-op)
 *
 *    down(): revert ข้อมูลกลับฝั่งเดิม ส่วนความยาวคอลัมน์คง varchar(16) ไว้ (ปลอดภัยต่อค่าเดิม)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 🌟 pgsql enforce ความยาว varchar — ขยาย + เปลี่ยน default ให้ตรง vocabulary ใหม่
        // (fresh install ได้ default ใหม่จาก migration 2026_07_13_* ที่ edit แล้ว ส่วน DB เดิม fix ตรงนี้;
        //  SQLite ไม่ enforce ความยาวและ fresh path ใช้ default จาก migration เดิม — จึง no-op)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE rooms ALTER COLUMN bed_type TYPE varchar(16)');
            DB::statement("ALTER TABLE rooms ALTER COLUMN bed_type SET DEFAULT 'twin'");
            DB::statement('ALTER TABLE booking_rooms ALTER COLUMN bed_preference TYPE varchar(16)');
        }

        // ชั้นอื่น/legacy rows (floor null) → twin, แล้วชั้น 8 ทับเป็น king_size
        DB::table('rooms')->update(['bed_type' => 'twin']);
        DB::table('rooms')->where('floor', 8)->update(['bed_type' => 'king_size']);

        // คำขอ bed_preference เดิม ('twin' = อยากได้ชั้น 8) → 'king_size'
        DB::table('booking_rooms')
            ->where('bed_preference', 'twin')
            ->update(['bed_preference' => 'king_size']);
    }

    public function down(): void
    {
        DB::table('booking_rooms')
            ->where('bed_preference', 'king_size')
            ->update(['bed_preference' => 'twin']);

        DB::table('rooms')->update(['bed_type' => 'double']);
        DB::table('rooms')->where('floor', 8)->update(['bed_type' => 'twin']);
    }
};
