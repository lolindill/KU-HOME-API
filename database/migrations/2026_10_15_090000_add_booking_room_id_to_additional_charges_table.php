<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 💸 (15/10/26) srs-v2-gap-closure ticket 13 — charges บวกยอดจริง (REQ-042/044, design ticket 12)
 *
 *    เพิ่ม booking_room_id (nullable FK → booking_rooms) บน additional_charges:
 *    - ใส่คู่กับ booking_id = จดต่อห้อง · ใส่ booking_id เดียว = รวมทั้ง booking
 *    - FK restrict ตามธรรมเนียม audit trail (ห้ามลบ booking_room ทิ้งเมื่อยังมี charge ผูกอยู่)
 *    - charge_type = string(20) เดิม — เพิ่มค่า 'addon' (REQ-044) ไม่ต้องแก้ schema
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_charges', function (Blueprint $table) {
            $table->uuid('booking_room_id')
                ->nullable()
                ->after('booking_id')
                ->comment('ห้องที่ผูก (nullable) — null = รวมทั้ง booking, มีค่า = จดต่อห้อง (ต้องเป็นลูกของ booking_id)');
            $table->foreign('booking_room_id')->references('id')->on('booking_rooms')->restrictOnDelete();
            $table->index('booking_room_id');
        });
    }

    public function down(): void
    {
        Schema::table('additional_charges', function (Blueprint $table) {
            $table->dropForeign(['booking_room_id']);
            $table->dropIndex(['booking_room_id']);
            $table->dropColumn('booking_room_id');
        });
    }
};
