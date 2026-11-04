<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📊 (05/10/26) excel-reports spec §2.6 — ตาราง additional_charges (ticket 11)
 *
 *    ledger รายงานล้วน — ค่าเสียหาย (damage) / ค่ายืม (rental) ผูกกับ booking:
 *    - ⚠️ ยอดไม่เข้า booking: invariant Σ booking_rooms.amount == total_amount +
 *      ชั้น A/B ของแมป booking-payment-types ไม่ถูกแตะ · ไม่ไหลเข้า payments
 *      (payments = ledger เดียวของ "เงินของ booking" — เก็บเงินสดเคาน์เตอร์แยกจาก booking)
 *    - บันทึกอิสระทุกเมื่อ ไม่ผูก flow checked_out · สิทธิ์ admin + staff (default spec §9)
 *    - money = integer บาท (convention 2026-09-11) · UUID PK
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_charges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id');
            $table->date('transaction_date')
                ->comment('วันที่ทำรายการ');
            $table->string('item_code')->nullable()
                ->comment('รหัสรายการ — free text (ชีต sample เป็น placeholder)');
            $table->string('item_name')
                ->comment('ชื่อรายการ เช่น "ค่าเสียหาย ปลอกหมอก"');
            $table->integer('qty')->default(1)
                ->comment('จำนวน');
            $table->string('unit')->nullable()
                ->comment('หน่วยนับ เช่น ชิ้น/ผืน/ดอก');
            $table->integer('price')
                ->comment('ราคารวมของรายการ (integer บาท)');
            $table->string('charge_type', 20)
                ->comment('ประเภท: damage (ค่าเสียหาย) | rental (ค่ายืม)');
            $table->uuid('recorded_by')->nullable()
                ->comment('ผู้บันทึก (admin/staff)');
            $table->timestamps();

            // ⚠️ restrict — ห้ามลบ booking ทิ้งเมื่อยังมี ledger ค่าปรับ/ค่ายืม (audit trail การเงิน)
            $table->foreign('booking_id')->references('id')->on('bookings')->restrictOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['booking_id', 'transaction_date']);
            $table->index('charge_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_charges');
    }
};
