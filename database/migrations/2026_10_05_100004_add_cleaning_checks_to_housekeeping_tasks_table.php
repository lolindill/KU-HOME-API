<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📊 (05/10/26) excel-reports spec §2.4 — checklist แม่บ้าน 3 หัวข้อ (ticket 10)
 *
 *    - cleaning_check_1/2/3 — nullable boolean (PgBoolean) แม่บ้าน tick ผ่าน API update task เดิม
 *      · tick เป็นบันทึกประกอบ — ไม่ผูกเงื่อนไขกับ done ใน v1
 *    - หัวข้อ label เก็บ config (reporting.cleaning_checks — default ① ห้องน้ำ ② เครื่องนอน/ผ้า ③ พื้น+ขยะ)
 *      แก้ได้ไม่ต้อง migrate · flow unassigned→accepted→in_progress→done ไม่เปลี่ยน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->boolean('cleaning_check_1')->nullable()
                ->comment('แม่บ้าน tick — หัวข้อ 1 (config reporting.cleaning_checks.0)')
                ->after('scheduled_for');
            $table->boolean('cleaning_check_2')->nullable()
                ->comment('แม่บ้าน tick — หัวข้อ 2 (config reporting.cleaning_checks.1)')
                ->after('cleaning_check_1');
            $table->boolean('cleaning_check_3')->nullable()
                ->comment('แม่บ้าน tick — หัวข้อ 3 (config reporting.cleaning_checks.2)')
                ->after('cleaning_check_2');
        });
    }

    public function down(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->dropColumn(['cleaning_check_1', 'cleaning_check_2', 'cleaning_check_3']);
        });
    }
};
