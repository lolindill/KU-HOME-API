<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📝 Audit Log (04/08/26): เก็บประวัติการเปลี่ยนสถานะของ Booking & BookingRoom
 *
 * - Polymorphic ตารางเดียว (entity_type + entity_id) เพื่อขยาย entity ใหม่ง่ายในอนาคต
 *   (เช่น Room / HousekeepingTask)
 * - ไม่ใส่ FK constraint บน entity_id เพราะ morph target หลายตาราง —
 *   แลกกับความยืดหยุ่น (ความเสี่ยง orphan ต่ำ เพราะ row ถูกสร้างเฉพาะตอนที่ entity มีอยู่จริง)
 * - แต่ละ row ถูกเขียนจาก transitionStatus() ของ model — เป็น chokepoint เดียว
 *   (ห้าม bypass ด้วยการ ->status = ตรงๆ เพราะจะหลุด audit)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_change_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 🎯 Polymorphic target — 'booking' | 'booking_room' (ขยายได้)
            $table->string('entity_type');
            $table->uuid('entity_id');

            // 🚦 Transition snapshot
            $table->string('from_status');
            $table->string('to_status');

            // 👤 ใครทำ — role ที่ authorize transition + user id (nullable เพราะ system/queue ไม่มี user)
            $table->string('role')->comment('role ที่ authorize transition (admin/system/user/...)');
            $table->uuid('causer_id')->nullable()->comment('Auth::id() — null สำหรับ system/queue transition');

            // 📝 สำรองไว้สำหรับ context เช่น "auto via syncStatusFromRooms"
            $table->text('note')->nullable();

            $table->timestamps();

            // 🚀 Index สำหรับ query history ตาม entity + เวลา (เรียงลำดับเหตุการณ์)
            $table->index(['entity_type', 'entity_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_change_logs');
    }
};
