<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🌟 Refactor (22/07/26): เปลี่ยน addon_rates → global_rates
 *
 * global_rates ตอนนี้เก็บได้ทั้ง:
 *  - room rate (rate_type: daily/group/month, ผูกกับ room_type_id)
 *  - addon rate (rate_type: addon, ไม่ผูก room_type, ใช้ code เป็น key)
 *
 * Migration นี้เป็น step 1: rename + เพิ่มคอลัมน์ discriminator
 * (step 2 ใน migration ถัดไปจะ seed room rate และ drop rate_daily_general)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Rename table
        Schema::rename('addon_rates', 'global_rates');

        // 2. เพิ่มคอลัมน์ discriminator + FK
        Schema::table('global_rates', function (Blueprint $table) {
            // rate_type: 'addon' (default สำหรับ row เดิม) | 'daily' | 'group' | 'month'
            $table->string('rate_type')->default('addon')->after('id');
            // room_type_id: null = addon rate (global), set = room rate (per room type)
            $table->uuid('room_type_id')->nullable()->after('rate_type');
            $table->foreign('room_type_id')
                ->references('id')
                ->on('room_types')
                ->cascadeOnDelete();
        });

        // 3. code เดิม unique NOT NULL — ตอนนี้ room rate row ไม่ใช้ code
        //    ทำให้ nullable เพื่อให้ room rate row สามารถเป็น NULL ได้
        //    (Postgres unique constraint ยอมให้มี NULL ได้หลายตัว จึงไม่กระทบ addon rows)
        Schema::table('global_rates', function (Blueprint $table) {
            $table->string('code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('global_rates', function (Blueprint $table) {
            $table->dropForeign(['room_type_id']);
            $table->dropColumn(['rate_type', 'room_type_id']);
            $table->string('code')->nullable(false)->change();
        });

        Schema::rename('global_rates', 'addon_rates');
    }
};
