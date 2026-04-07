<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary(); 
            $table->foreignUuid('room_id')->constrained('rooms')->onDelete('cascade'); 
            $table->foreignUuid('assigned_to')->nullable()->constrained('users'); 
            $table->string('status')->default('pending'); // pending | in_progress | done 
            $table->text('notes')->nullable(); 
            $table->timestamp('checked_out_at')->nullable(); 
            $table->timestamp('completed_at')->nullable(); 
            $table->timestamps(); 
        });
    }
};