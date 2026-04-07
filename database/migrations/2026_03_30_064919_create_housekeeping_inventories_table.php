<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_inventories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // เชื่อมกับงานทำความสะอาด
            $table->foreignUuid('task_id')->constrained('housekeeping_tasks')->cascadeOnDelete();
            
            $table->string('item_name')->comment('ชื่อสิ่งของ เช่น ผ้าเช็ดตัว, น้ำดื่มมินิบาร์');
            $table->integer('actual_quantity')->comment('จำนวนที่นับได้จริง');
            $table->string('condition')->default('good')->comment('สภาพ: good (ดี), damaged (ชำรุด), missing (สูญหาย)');
            $table->string('notes')->nullable()->comment('หมายเหตุเพิ่มเติมจากแม่บ้าน');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_inventories');
    }
};