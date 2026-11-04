<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🌟 Add (24/07/26): เพิ่ม column description ใน room_types
 *
 * Frontend ต้องการแสดงคำอธิบายประกอบของแต่ละ RoomType
 * เก็บเป็น string nullable (ถ้ายังไม่กรอกจะเป็น null)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name_th');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
