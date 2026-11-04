<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📦 Ticket 07 (REQ-051/052) — สต๊อกครบชุด
 *
 *    1. เพิ่มคอลัมน์ใน stock_inventories:
 *       - item_code, category, reorder_point, max_stock, deleted_at (soft delete)
 *    2. สร้างตาราง stock_movements (audit log การเคลื่อนไหวของสต๊อก ย้อนหลัง 1 ปี):
 *       - delta, quantity_before, quantity_after, reason, housekeeping_task_id, user_id, notes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_inventories', function (Blueprint $table) {
            $table->string('item_code')->nullable()->after('id');
            $table->string('category')->nullable()->after('item_name');
            $table->integer('reorder_point')->default(0)->after('unit');
            $table->integer('max_stock')->nullable()->after('reorder_point');
            $table->softDeletes()->after('notes');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_inventory_id')->constrained('stock_inventories')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('housekeeping_task_id')->nullable()->constrained('housekeeping_tasks')->nullOnDelete();
            $table->integer('delta');
            $table->integer('quantity_before')->default(0);
            $table->integer('quantity_after')->default(0);
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['stock_inventory_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');

        Schema::table('stock_inventories', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'item_code',
                'category',
                'reorder_point',
                'max_stock',
            ]);
        });
    }
};
