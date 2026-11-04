<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🏨 Phase 1 — เพิ่ม topology columns ให้ rooms เพื่อรองรับ Room Allocation Algorithm (v3)
 *
 *    Algorithm ใหม่วัด "ระยะเดินจริง" ระหว่างห้อง จึงต้องรู้ตำแหน่งทางกายภาพของแต่ละห้อง:
 *      - floor  → ชั้น (5-9)         ใช้คำนวณ floor penalty + walking dist ข้ามชั้น
 *      - side   → ฝั่ง (V1/V2A/V2B)  ใช้แปลงเป็น global position 1D
 *      - pos    → ตำแหน่งในฝั่ง      ใช้คำนวณระยะแนวนอน
 *      - bed_type → 'twin' | 'king_size' ตรงกับ bed_preference ของ booking room
 *        (27/08/26 rename: double→twin, twin→king_size · ชั้น 8 = king_size)
 *
 *    nullable ทั้งหมดเพื่อ backward compat: ห้องเดิมที่ยังไม่ได้ระบุ topology จะถูก algorithm ข้ามไป
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §2-3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->integer('floor')->nullable()->after('room_number');
            $table->string('side', 4)->nullable()->after('floor');   // V1 | V2A | V2B
            $table->integer('pos')->nullable()->after('side');
            $table->string('bed_type', 16)->default('twin')->after('builtin_extra_beds'); // twin | king_size

            // Lookup เร็วตอนหา candidate rooms ตามชั้น/ฝั่ง
            $table->index(['floor', 'side', 'pos']);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex(['floor', 'side', 'pos']);
            $table->dropColumn(['floor', 'side', 'pos', 'bed_type']);
        });
    }
};
