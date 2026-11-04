<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🖼️ Refactor (19/08/26): ปลุก images table จาก 🚧 DRAFT → ใช้งานจริง
     *
     * ตารางเดิม (2026_04_27) เป็น draft หลวมๆ (url + morph ไม่มี index) และไม่เคยมีข้อมูล production
     * → สร้างใหม่ทั้งตารางสะอาดกว่า alter (หลีกเลี่ยง SQLite alter-FK quirks)
     *
     * การใช้งานจริง:
     *   - ไฟล์อยู่บน disk ที่ระบุใน column `disk` (default: local = storage/app/private — เว็บเปิดตรงๆ ไม่ได้)
     *   - ผูกกับเจ้าของรูปผ่าน polymorphic imageable (ปัจจุบัน: BookingConfirmation slip,
     *     อนาคต: HousekeepingTask รูปก่อน/หลังเก็บห้อง ฯลฯ)
     *   - ดูรูปผ่าน signed URL อายุ 15 นาที (route images.file)
     */
    public function up(): void
    {
        Schema::dropIfExists('images');

        Schema::create('images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('path')->comment('ตำแหน่งไฟล์บน disk เช่น slips/abc123.jpg');
            $table->string('disk')->default('local')->comment('filesystem disk config เช่น local|public|s3');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable()->comment('ขนาดไฟล์ (bytes)');
            $table->string('original_name')->nullable()->comment('ชื่อไฟล์ตอนผู้ใช้อัปโหลด');
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('imageable_id')->nullable();
            $table->string('imageable_type')->nullable();
            $table->timestamps();

            $table->index(['imageable_type', 'imageable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
