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
            $table->string('confirmation')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            // 📅 แหล่งที่มาของการจอง (dates + guests ย้ายไป booking_rooms แล้ว)
            $table->string('source')->default('online'); // online | admin | line

            $table->integer('total_amount')->default(0);
            $table->boolean('is_paid')->default(false);
            $table->timestamp('payment_deadline')->nullable();

            // 🚦 State Machine (container): draft → paid → confirmed → complete
            // (walk-in เข้า confirmed ตรงๆ — skip draft→paid)
            $table->string('status')->default('draft');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};