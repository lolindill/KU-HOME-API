<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎫 KU SSO — split user ต่อ provider (wayfinder/ku-sso, decision ticket 02)
 *
 *    users.email เดิม unique ทั้งตาราง → เปลี่ยนเป็น composite unique (email, auth_provider)
 *    เพื่อให้ email เดียวมีได้หลาย account ตาม provider (password | ku_sso | google ในอนาคต)
 *    users เดิมทุกคน = 'password' ผ่าน column default — non-destructive, migrate ปกติได้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_provider')->default('password');

            $table->dropUnique('users_email_unique');
            $table->unique(['email', 'auth_provider']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email', 'auth_provider']);
            $table->unique('email');
            $table->dropColumn('auth_provider');
        });
    }
};
