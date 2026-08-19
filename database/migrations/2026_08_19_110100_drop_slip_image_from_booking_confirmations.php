<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🖼️ Refactor (19/08/26): สลิปย้ายไปอยู่ใน images table (polymorphic) แล้ว
     *
     * - drop column `slip_image` (path string บน public disk แบบเก่า)
     * - ตัวเชื่อมเดียว: images.imageable_* → booking_confirmation (morphOne slipImage)
     * - ไม่ backfill ข้อมูลเก่า — สลิปเดิมเป็น dev-only (โฟลเดอร์ storage/app/public/slips ว่าง)
     *   ใช้ php artisan migrate:fresh --seed ตาม convention (จดใน cline.md แล้ว)
     */
    public function up(): void
    {
        Schema::table('booking_confirmations', function (Blueprint $table) {
            $table->dropColumn('slip_image');
        });
    }

    public function down(): void
    {
        Schema::table('booking_confirmations', function (Blueprint $table) {
            $table->string('slip_image')->nullable()->comment('path ของไฟล์สลิป (legacy — ใช้ images table แทนแล้ว)');
        });
    }
};
