<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🏨 Phase 1 — เพิ่ม bed_preference ให้ booking_rooms
 *
 *    Algorithm ใหม่ต้องการรู้ว่าผู้จองแต่ละห้องต้องการเตียงประเภทใด:
 *      - 'king_size' → ตรงกับ rooms.bed_type='king_size' (ห้องชั้น 8 เท่านั้น)
 *        (27/08/26 rename: 'twin' → 'king_size' ตามชุดค่า bed_type ใหม่)
 *      - null   → ไม่ระบุ (รับได้ทุกประเภท, default)
 *
 *    ใช้ใน bedPrefPenalty (cost function) + Booking Priority (มี preference ต้องจัดก่อน)
 *
 *    nullable เพราะ booking เดิมที่สร้างก่อน migration นี้ยังไม่มีค่า → ถือว่า 'any'
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §5 (bedPrefPenalty), §7 (Booking Priority)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            // 'king_size' | null(=any)
            $table->string('bed_preference', 16)->nullable()->after('check_out');
        });
    }

    public function down(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropColumn('bed_preference');
        });
    }
};
