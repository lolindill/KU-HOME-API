<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 🌟 1. เพิ่มคอลัมน์ guests (JSON), children, is_ku_member ใน booking_rooms
        Schema::table('booking_rooms', function (Blueprint $table) {
            // 🧍‍♂️ ข้อมูลผู้เข้าพัก (รองรับหลายคนใน 1 ห้อง)
            // JSON: [{ "title": "Mr.", "name": "สมชาย", "nationality": "Thai", "is_ku_member": false }, ...]
            $table->json('guests')->nullable()->after('room_id');
            $table->integer('children')->default(0)->after('guests');
        });

        // 🌟 2. ลบคอลัมน์ข้อมูลผู้เข้าพักออกจาก bookings (ย้ายไป booking_rooms แล้ว)
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'guest_title',
                'guest_name',
                'guest_email',
                'guest_phone',
                'guest_nationality',
                'is_ku_member',
                'children',
            ]);
        });
    }

    public function down(): void
    {
        // 🔙 คืนคอลัมน์เดิมให้ bookings
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('guest_title')->nullable()->after('check_out');
            $table->string('guest_name')->nullable()->after('guest_title');
            $table->string('guest_email')->nullable()->after('guest_name');
            $table->string('guest_phone')->nullable()->after('guest_email');
            $table->string('guest_nationality')->nullable()->default('Thai')->after('guest_phone');
            $table->boolean('is_ku_member')->default(false)->after('guest_nationality');
            $table->integer('children')->default(0)->after('is_ku_member');
        });

        // 🔙 ลบคอลัมน์ guests ออกจาก booking_rooms
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropColumn(['guests', 'children']);
        });
    }
};