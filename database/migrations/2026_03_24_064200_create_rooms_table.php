<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->uuid('id')->primary(); 
            $table->foreignUuid('room_type_id')->constrained('room_types')->onDelete('cascade'); 
            $table->string('room_number')->unique(); 
            $table->string('status')->default('available'); 
            $table->timestamp('status_updated_at')->nullable();
            $table->uuid('status_updated_by')->nullable();
            $table->timestamps(); 
        });
    }
};