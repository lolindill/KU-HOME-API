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
            $table->string('name_th')->comment('ชื่อบริการเสริม (ภาษาไทย)');
            $table->string('name_en')->comment('ชื่อบริการเสริม (ภาษาอังกฤษ)');
            $table->text('description')->nullable()->comment('รายละเอียดเพิ่มเติม');
            $table->decimal('price', 10, 2)->comment('ราคาต่อหน่วย/ต่อครั้ง');
            $table->boolean('is_active')->default(true)->comment('สถานะเปิด/ปิดการใช้งาน');
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