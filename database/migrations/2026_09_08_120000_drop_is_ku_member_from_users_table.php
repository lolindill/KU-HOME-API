<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🪦 Drop users.is_ku_member — legacy boolean, role เป็น source of truth ของสถานะสมาชิก
 *    (wayfinder/ku-sso decision ticket 08 + owner decision 2026-09-08: "drop")
 *
 *    ตั้งแต่ 2026-09-07 ราคา member + สิทธิ์ต่างๆ ใช้ role='ku_member' แทน flag นี้หมดแล้ว
 *    column ไม่ถูกเขียนเพิ่มนับตั้งแต่ ticket 08 — drop ทิ้งเพื่อไม่ให้สับสน
 *    (is_ku_member ใน guests JSON ของ booking_rooms เป็นคนละตัว — key นั้นไม่เคยจัดเก็บอยู่แล้ว)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_ku_member');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_ku_member')->default(false);
        });
    }
};
