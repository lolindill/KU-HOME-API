<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🧹 Housekeeping Refactor — Phase A (15/07/26)
 *
 *    1. ลบ housekeeping_inventories (leaf table, cascade safe) → แทนด้วย stock_inventories แบบ master
 *    2. เพิ่ม task_type / accepted_at / scheduled_for ใน housekeeping_tasks
 *    3. Migrate status 'pending' → 'unassigned' + เปลี่ยน default
 *    4. สร้าง stock_inventories (master stock ลอย — D3: ไม่ผูก task)
 *
 *    Breaking: status enum เปลี่ยน + drop table → ใช้ migrate:fresh --seed ใน dev
 */
return new class extends Migration
{
    public function up(): void
    {
        // (a) Drop housekeeping_inventories — Fix S-B3 (relation จะถูกลบใน model)
        Schema::dropIfExists('housekeeping_inventories');

        // (b) Add task_type / accepted_at / scheduled_for
        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            // pre_checkin | checkout | checkout_then_in | daily | monthly | group
            $table->string('task_type')->default('checkout')->after('assigned_to');
            $table->timestamp('accepted_at')->nullable()->after('checked_out_at');
            $table->date('scheduled_for')->nullable()->after('accepted_at');
        });

        // (c) Migrate status: pending → unassigned + ปรับ default
        DB::table('housekeeping_tasks')->where('status', 'pending')->update(['status' => 'unassigned']);
        DB::table('housekeeping_tasks')->whereNull('task_type')->orWhere('task_type', '')->update(['task_type' => 'checkout']);

        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->string('status')->default('unassigned')->change();
        });

        // (d) Create stock_inventories (master stock ลอย — D3)
        Schema::create('stock_inventories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('item_name');
            $table->integer('quantity')->default(0);
            $table->string('unit')->default('unit')->comment('หน่วย เช่น bottle, roll, pack');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_inventories');

        // Revert status default + rollback migration mapping
        DB::table('housekeeping_tasks')->where('status', 'unassigned')->update(['status' => 'pending']);

        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
            $table->dropColumn(['task_type', 'accepted_at', 'scheduled_for']);
        });

        // Recreate housekeeping_inventories (restore original schema)
        Schema::create('housekeeping_inventories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('housekeeping_tasks')->cascadeOnDelete();
            $table->string('item_name');
            $table->integer('actual_quantity');
            $table->string('condition')->default('good');
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }
};
