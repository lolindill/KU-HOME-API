<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🌟 Fix H2 (03/07/26): ลบ orphan column `bookings.children`
 *
 * Refactor ย้ายข้อมูลเด็กไปที่ `booking_rooms.children` แล้ว (แต่ละห้องต่างวัน/ต่างเด็กได้)
 * แต่ migration `2026_05_14` ยังค้างเพิ่ม column นี้ที่ `bookings` — เป็นขยะที่ไม่มี code ใช้งาน
 * Booking model ไม่ได้ fillable + ไม่มี cast → เป็น dead column ลบทิ้งได้เลย
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'children')) {
                $table->dropColumn('children');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'children')) {
                $table->integer('children')->default(0);
            }
        });
    }
};
