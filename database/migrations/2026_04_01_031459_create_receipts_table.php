<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('receipt_no')->unique()->comment('เลขที่ใบเสร็จรับเงิน');
            
            // เชื่อมโยงกับใบจองและการชำระเงิน
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->cascadeOnDelete();
            
            $table->decimal('amount', 10, 2)->comment('ยอดเงินในใบเสร็จ');
            $table->string('billing_name')->nullable()->comment('ชื่อผู้เสียภาษี/ผู้จอง');
            $table->text('billing_address')->nullable()->comment('ที่อยู่ออกใบเสร็จ');
            
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};