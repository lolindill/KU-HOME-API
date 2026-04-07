<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // เชื่อมกับงานทำความสะอาด
            $table->foreignUuid('task_id')->constrained('housekeeping_tasks')->cascadeOnDelete();
            
            $table->string('photo_path')->comment('พาร์ทเก็บรูปภาพใน Storage');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_photos');
    }
};