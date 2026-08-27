<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🎟️ Discount System v2.1 (27/08/26)
     *
     * สร้างตาราง discounts, discount_redemptions
     * และเพิ่ม discount_code ใน bookings รวมถึง room_amount, discount_amount ใน booking_rooms
     */
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('type'); // 'percent' | 'fixed' | 'set_room_price'
            $table->integer('value'); // percent -> 1-100, fixed/set_room_price -> satang
            $table->json('room_type_ids')->nullable(); // null = all room types
            $table->dateTime('usable_from')->nullable();
            $table->dateTime('usable_until')->nullable();
            $table->date('stay_from')->nullable();
            $table->date('stay_until')->nullable();
            $table->integer('max_uses')->nullable(); // null = unlimited (global)
            $table->integer('max_uses_per_user')->nullable(); // null = unlimited (per user)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('discount_id');
            $table->uuid('booking_room_id');
            $table->uuid('user_id');
            $table->string('status')->default('held'); // 'held' | 'used'
            $table->timestamps();

            // ⚡ Foreign keys
            $table->foreign('booking_room_id')->references('id')->on('booking_rooms')->cascadeOnDelete();
            $table->foreign('discount_id')->references('id')->on('discounts');
            $table->foreign('user_id')->references('id')->on('users');

            $table->index(['discount_id', 'status']);
            $table->index(['discount_id', 'user_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('discount_code', 50)->nullable();
        });

        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->integer('room_amount')->default(0);
            $table->integer('discount_amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropColumn(['room_amount', 'discount_amount']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('discount_code');
        });

        Schema::dropIfExists('discount_redemptions');
        Schema::dropIfExists('discounts');
    }
};
