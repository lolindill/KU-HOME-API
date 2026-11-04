<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🖼️ (REQ-033 / REQ-048): เพิ่ม metadata (JSON) ใน images table
     * สำหรับเก็บประเภทหลักฐาน (id_card, registration, guest_register, pdpa, other)
     * หรือหมวดรูปภาพและหมายเหตุเพิ่มเติม
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('imageable_type');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
