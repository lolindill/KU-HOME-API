<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();

            $table->integer('extra_bed')->default(0);
            $table->integer('breakfast')->default(0); //quality 

            $table->integer('early_checkIn_price')->default(0);
            $table->integer('late_checkOut_price')->default(0); //admin set by opinion case

            $table->integer('extra_bed_price')->default(0);
            $table->integer('breakfast_price')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};