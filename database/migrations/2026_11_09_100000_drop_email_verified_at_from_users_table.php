<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🪦 Drop users.email_verified_at — legacy จาก Laravel base scaffold ล้วน ๆ
 *    (owner decision 2026-11-09)
 *
 *    ไม่เคยถูกใช้ใน flow ใดเลย: ไม่มี email verification flow, ไม่ implement
 *    MustVerifyEmail, ไม่อยู่ใน $fillable, ไม่มี controller/service/test/seeder
 *    อ่านหรือเขียน — ค่าเป็น null ตลอดชีวิต production
 *
 *    การ verify email ที่แท้จริงเกิดที่ claim ฝั่ง SSO provider
 *    (Google email_verified claim — fail-closed ใน GoogleSsoService) ไม่ใช่คอลัมน์นี้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable();
        });
    }
};
