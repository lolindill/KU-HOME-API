<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('confirmation_no')->unique();
            $table->uuid('user_id')->nullable(); // ใส่ nullable ไว้ก่อนเผื่อเป็น Walk-in
            $table->string('booking_type')->default('individual'); // individual | group | monthly
            $table->string('source')->default('online'); // online | admin | line
            $table->string('status')->default('draft'); // draft | confirmed | checked_in | checked_out | cancelled
            $table->date('check_in');
            $table->date('check_out');
            $table->integer('adults');
            $table->integer('children')->default(0);
            $table->boolean('breakfast_included')->default(false);
            $table->uuid('discount_code_id')->nullable();
            $table->integer('discount_amount')->default(0);
            $table->integer('total_amount');
            $table->boolean('is_paid')->default(false);
            $table->timestamp('payment_deadline')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
        });
    }
};