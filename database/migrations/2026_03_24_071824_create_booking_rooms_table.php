<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->onDelete('cascade');

            // 🛏️ จองด้วย Room Type ก่อน (assign ห้องตอน check-in)
            $table->foreignUuid('room_type_id')->constrained('room_types');

            // Room ID nullable — ใส่ค่าตอน check-in
            $table->foreignUuid('room_id')->nullable()->constrained('rooms');

            // 📅 วันที่เข้า-ออก ย้ายมาไว้ที่ BR (แต่ละห้องมีวันที่ต่างกันได้)
            $table->date('check_in');
            $table->date('check_out');

            // 🧍‍♂️ ข้อมูลผู้เข้าพัก (รองรับหลายคนใน 1 ห้อง)
            // JSON: [{ "title": "Mr.", "name": "สมชาย", "nationality": "Thai", "is_ku_member": false }, ...]
            $table->json('guests')->nullable()->after('room_id');
            $table->integer('children')->default(0)->after('guests');

            // 🚦 State Machine: draft → confirmed → {checked_in → checked_out | no_show}
            $table->string('status')->default('draft');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_rooms');
    }
};