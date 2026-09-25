<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🏛️ Organization Bookings (25/09/26 — wayfinder/organization-bookings ticket 01/05)
     *
     * ตาราง organizations — stopgap ที่ออกแบบให้เปลี่ยนไปใช้ organization-data API ได้ทีหลัง:
     * - `erp` = รหัสองค์กรฝั่ง ERP ภายนอก — **logical FK** (ไม่มี DB FK constraint
     *   เพราะไม่มีตาราง erp ในเครื่อง) · nullable เผื่อองค์กรที่ยังไม่มีรหัส ·
     *   unique เพื่อ dedupe ตอน sync/import ในอนาคต
     * - replaceability: booking จะอ้าง org ด้วย FK + snapshot (ticket 03/06) —
     *   ตารางนี้เทิ้งได้โดย booking เก่ายังอ่านความหมายถูกจาก snapshot
     * - ไม่มี SoftDeletes / hard delete — เลิกใช้ = `is_active` + toggle (precedent discounts)
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('erp')->nullable()->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
