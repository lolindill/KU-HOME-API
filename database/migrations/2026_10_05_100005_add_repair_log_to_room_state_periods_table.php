<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📊 (05/10/26) excel-reports spec §2.5 — repair log บน room_state_periods (ticket 11)
 *
 *    ไม่มีตารางใหม่ — เพิ่ม 2 column ใช้เฉพาะ kind=maintenance:
 *    - work_type     — ประเภทงานซ่อม: ไฟฟ้า | ประปา | งานระบบ (ตามชีต truth)
 *    - repair_detail — รายละเอียดงานซ่อม (note OOO ของรายงาน out-of-service-room)
 *
 *    นิยามรายงาน: วันแจ้งซ่อม = start_date · เสร็จ = end_date (เปิดปลาย = ยังไม่เสร็จ แสดง "-")
 *    · duration derive จากทั้งสอง · CRUD ใช้ route periods ที่ landed แล้ว ไม่แตะ chokepoint ใหม่
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_state_periods', function (Blueprint $table) {
            $table->string('work_type')->nullable()
                ->comment('ประเภทงานซ่อม: ไฟฟ้า | ประปา | งานระบบ — ใช้เฉพาะ kind=maintenance')
                ->after('created_by');
            $table->text('repair_detail')->nullable()
                ->comment('รายละเอียดงานซ่อม/note OOO — ใช้เฉพาะ kind=maintenance')
                ->after('work_type');
        });
    }

    public function down(): void
    {
        Schema::table('room_state_periods', function (Blueprint $table) {
            $table->dropColumn(['work_type', 'repair_detail']);
        });
    }
};
