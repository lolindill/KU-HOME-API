<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🏨 wayfinder/reserved-room-pool ticket 90 (2026-09-24): "ห้องสำรอง" เปลี่ยนจากสถานะเป็นคุณสมบัติ
 *
 *    - เพิ่ม rooms.is_reserved (boolean, PgBoolean) = pool membership ถาวรของห้อง
 *      (สถานะกายภาพวิ่ง lifecycle ปกติ — ห้องสำรอง check-in/checkout แล้วกลับเข้า pool สำรองเอง
 *       เพราะไม่เคยออกจาก pool ไปไหน)
 *    - แปลงข้อมูลเดิม: status reserved_closed → (status available, is_reserved = true)
 *    - สถานะ reserved_closed ถูกถอดออกจาก Room::transitionStatusTo() พร้อมกัน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('is_reserved')->default(false)->after('status');
        });

        // PostgreSQL strict boolean — เขียนด้วย SQL literal ตาม gotcha (มิใช่ bind int 0/1)
        DB::table('rooms')
            ->whereRaw("status = 'reserved_closed'")
            ->update([
                'is_reserved' => DB::raw('TRUE'),
                'status' => 'available',
            ]);
    }

    public function down(): void
    {
        DB::table('rooms')
            ->whereRaw('is_reserved = TRUE')
            ->whereRaw("status = 'available'")
            ->update([
                'is_reserved' => DB::raw('FALSE'),
                'status' => 'reserved_closed',
            ]);

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('is_reserved');
        });
    }
};
