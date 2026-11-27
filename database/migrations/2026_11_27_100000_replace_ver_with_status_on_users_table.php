<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔐 users: drop legacy `ver` → เพิ่ม `status` (active | inactive) — owner decision 2026-11-27
 *
 *    `ver` ไม่เคยมี flow ใดอ่านค่าไปตัดสินอะไรเลย (มีแค่ PUT /users/{id}/verify toggle เก็บไว้เฉย ๆ)
 *    → แทนที่ด้วย `status` ที่มีผลจริง: inactive = login ไม่ได้ทุกช่องทาง + token เดิมใช้ไม่ได้
 *
 *    ✅ user เดิมทุกคน = 'active' (ผ่าน column default) — ห้าม map ver=false → inactive
 *       เพราะ ver ไม่เคยถูกใช้ จะล็อกทุกคนออกจากระบบ
 *    🗂️ string ไม่ใช่ boolean — ไม่ต้องพึ่ง PgBoolean + ขยายสถานะเพิ่มได้ในอนาคต
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ver');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ver')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
