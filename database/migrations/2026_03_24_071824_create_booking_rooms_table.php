<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->onDelete('cascade');
            
            // จองด้วย Room Type ก่อน
            $table->foreignUuid('room_type_id')->constrained('room_types'); 
            
            // Room ID ให้เป็น nullable เพราะจะใส่ค่าตอน Check-in 
            $table->foreignUuid('room_id')->nullable()->constrained('rooms'); 
            
            $table->integer('extra_beds')->default(0);
            $table->integer('room_price');
            $table->integer('extra_bed_price')->default(0);
            $table->integer('breakfast_price')->default(0);
            $table->integer('subtotal');
            $table->timestamps();
        });
    }
};