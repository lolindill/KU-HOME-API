<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // เชื่อมกับใบจองหลัก
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            
            // ข้อมูลการชำระเงิน
            $table->decimal('amount', 10, 2)->comment('ยอดเงินที่ชำระ');
            $table->string('payment_method')->comment('ช่องทางชำระ เช่น cash, credit_card, transfer');
            $table->string('status')->default('completed')->comment('สถานะการจ่ายเงิน เช่น pending, completed, failed');
            $table->string('reference_number')->nullable()->comment('เลขที่อ้างอิง เช่น สลิปโอนเงิน หรือ Ref บัตรเครดิต');
            
            // พนักงานที่รับเงิน
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete()->comment('พนักงานหน้าฟรอนต์ที่รับชำระ');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};