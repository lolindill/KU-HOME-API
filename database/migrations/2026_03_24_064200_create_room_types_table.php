<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->uuid('id')->primary(); 
            $table->string('name_en'); 
            $table->string('name_th'); 
            $table->integer('max_guests'); 
            $table->boolean('extra_bed_enabled')->default(false); 
            $table->integer('max_extra_beds')->default(0); 
            $table->integer('extra_bed_price')->default(0);
            $table->integer('rate_daily_general'); 
            $table->timestamps(); 
        });
    }
};