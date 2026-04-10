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
           
            // 📅 ข้อมูลการเข้าพัก
            $table->string('source')->default('online'); // online | admin | line 
            $table->date('check_in');
            $table->date('check_out');
            
            // 🧍‍♂️ ข้อมูลผู้เข้าพัก (Snapshot)
            $table->string('guest_title')->nullable();
            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('guest_phone');
            $table->string('guest_id_number')->nullable();
            $table->string('guest_nationality')->default('Thai');
            $table->boolean('is_ku_member')->default(false);
            
            
            $table->integer('total_amount');
            $table->boolean('is_paid')->default(false);
            $table->timestamp('payment_deadline')->nullable();

            $table->string('status')->default('draft'); // draft | confirmed | checked_in | checked_out | cancelled

            
            $table->timestamps();
        });
    }
};