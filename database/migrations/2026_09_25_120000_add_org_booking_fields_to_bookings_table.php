<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🏛️ Organization Bookings (25/09/26 — wayfinder/organization-bookings ticket 03/06)
     *
     * org booking fields บน bookings — จองให้องค์กรโดยไม่ผูก user account (user_id = null):
     * - `organization_id` = FK ไป organizations (nullable — เฉพาะ org booking) ·
     *   restrictOnDelete เพราะ organizations ไม่มี DELETE by design (ticket 01) —
     *   FK เป็นชั้น replaceability แรก (erp = key map ไป organization-data API ตอนเปลี่ยน)
     * - snapshot 3 columns (ticket 03 — contact 1 คนต่อ booking ไม่ใช่ต่อห้อง):
     *   `customer_name` (บังคับตอนส่ง `organize` · nullable ที่ DB เพราะบิลโหมดอื่นไม่มี)
     *   + `customer_phone` / `customer_email` (nullable) — เก็บติดต่อล้วน ไม่ผูก flow (ticket 04)
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->after('user_id');
            $table->foreign('organization_id')
                ->references('id')->on('organizations')
                ->restrictOnDelete();
            $table->string('customer_name')->nullable()->after('organization_id');
            $table->string('customer_phone')->nullable()->after('customer_name');
            $table->string('customer_email')->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['organization_id', 'customer_name', 'customer_phone', 'customer_email']);
        });
    }
};
