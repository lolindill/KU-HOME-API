<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📊 (05/10/26) excel-reports spec §2.1 — booking attributes สำหรับรายงาน (ticket 09)
 *
 *    - invoice_requested_at — เวลา admin กด "ทำเรื่องแจ้งหนี้" (รายงาน erp โชว์วันที่, null = ยังไม่ทำ)
 *    - special_request      — คำขอของแขกตอนจอง (ระดับ booking ทั้งการจอง — check-in/out report โชว์ซ้ำทุกแถวห้อง)
 *    - comment              — หมายเหตุฝั่ง admin/บัญชีงาน ERP (แยกจาก special_request)
 *    - is_complimentary     — tag-only · ยอดเงิน booking คงเดิมทุกอย่าง (ไม่แตะ pricing/invariant)
 *                             · ตั้งได้ admin เท่านั้น (guard ใน controller)
 *
 *    ไม่มีตารางใหม่ · เขียนผ่าน admin booking path เดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('invoice_requested_at')->nullable()
                ->comment('เวลา admin ทำเรื่องแจ้งหนี้ — null = ยังไม่ทำ (erp-transfer-report)')
                ->after('deposit_amount');
            $table->text('special_request')->nullable()
                ->comment('คำขอพิเศษของแขกตอนจอง — ระดับ booking ทั้งการจอง (check-in/out report)')
                ->after('invoice_requested_at');
            $table->text('comment')->nullable()
                ->comment('หมายเหตุฝั่ง admin/บัญชีงาน ERP — แยกจาก special_request')
                ->after('special_request');
            $table->boolean('is_complimentary')->default(false)
                ->comment('tag-only ห้องรับราชการ — ยอดเงิน booking คงเดิม · admin เท่านั้น')
                ->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['invoice_requested_at', 'special_request', 'comment', 'is_complimentary']);
        });
    }
};
