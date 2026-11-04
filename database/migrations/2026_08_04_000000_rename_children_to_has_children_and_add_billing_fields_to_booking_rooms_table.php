<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🧒🧾 Refactor (04/08/26): rename `children` → `has_children` + เพิ่ม billing fields
 *
 *    เดิม `booking_rooms.children` เป็น integer (จำนวนเด็กที่เข้าพัก)
 *    ใหม่เปลี่ยนเป็น boolean flag `has_children` (มีเด็กไหม) ตาม requirement
 *    และเพิ่ม `billing_address` + `billing_comment` สำหรับข้อมูลใบกำกับภาษีระดับห้อง
 *
 *    Data migration: ค่า children > 0 → has_children = true (ใช้ raw SQL
 *    เพื่อความปลอดภัยกับ PostgreSQL strict boolean typing)
 *
 *    อ้างอิง: AGENTS.md § "PostgreSQL strict boolean typing" — ห้ามเขียน raw bool
 *    ลง boolean column โดยตรง ต้องใช้ DB::raw('TRUE'/'FALSE')
 */
return new class extends Migration
{
    public function up(): void
    {
        // 🛡️ Guard: ถ้าเป็น DB ใหม่ที่สร้างจาก migration ต้นฉบับที่แก้แล้ว
        //    (has_children มีอยู่แล้ว) ให้ข้ามการ rename ไปเลย
        if (Schema::hasColumn('booking_rooms', 'has_children')) {
            return;
        }

        Schema::table('booking_rooms', function (Blueprint $table) {
            // เพิ่ม boolean ใหม่ก่อน (default false)
            $table->boolean('has_children')->default(false)->after('guests');

            // 🧾 Billing fields
            $table->string('billing_address')->nullable()->after('has_children');
            $table->string('billing_comment')->nullable()->after('billing_address');
        });

        // ย้ายข้อมูล: children > 0 → has_children = true
        // (ใช้ DB::raw เพื่อป้องกัน PostgreSQL Datatype mismatch)
        DB::table('booking_rooms')
            ->where('children', '>', 0)
            ->update(['has_children' => DB::raw('TRUE')]);

        // ลบคอลัมน์ children เดิม
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropColumn('children');
        });
    }

    public function down(): void
    {
        // คืนคอลัมน์ children (integer count) — ค่าประมาณการจาก has_children flag
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->integer('children')->default(0)->after('guests');
        });

        // has_children = true → children = 1 (ค่าประมาณ; ข้อมูลตัวเลขเดิมสูญหาย)
        DB::table('booking_rooms')
            ->where('has_children', true)
            ->update(['children' => 1]);

        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropColumn(['has_children', 'billing_address', 'billing_comment']);
        });
    }
};
