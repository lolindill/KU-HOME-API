<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🧹 Drop checked_out_at + updated_at จาก housekeeping_tasks
 *
 *    - checked_out_at: ใช้ created_at แทน (task ถูกสร้างตอน checkout)
 *    - updated_at:     ปิด timestamp อัตโนมัติ (model set UPDATED_AT = null)
 *
 *    ย้อนกลับได้ (down) — คืนทั้งสอง column พร้อม nullable
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('housekeeping_tasks', 'checked_out_at')) {
                $table->dropColumn('checked_out_at');
            }
            if (Schema::hasColumn('housekeeping_tasks', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->timestamp('checked_out_at')->nullable()->after('notes');
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });
    }
};
