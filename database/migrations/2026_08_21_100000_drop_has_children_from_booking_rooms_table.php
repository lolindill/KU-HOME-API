<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🧒 Refactor (21/08/26): ลบ `has_children` ออกจาก `booking_rooms`
     *
     * - drop column `has_children` (boolean flag เดิม)
     * - ข้อมูลเด็กไม่ถูกจัดเก็บแยกในระดับห้องอีกต่อไป
     */
    public function up(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->dropColumn('has_children');
        });
    }

    public function down(): void
    {
        Schema::table('booking_rooms', function (Blueprint $table) {
            $table->boolean('has_children')->default(false)->after('guests');
        });
    }
};
