<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🧹 (14/10/26, REQ-048 / ticket 06):
     * 1. Drop dead scaffold table `housekeeping_photos` (ตาม AGENTS.md ให้ใช้ images table morph HousekeepingTask แทน)
     * 2. Add `category` to `images` table (before, after, forgotten_item, damage)
     */
    public function up(): void
    {
        Schema::dropIfExists('housekeeping_photos');

        Schema::table('images', function (Blueprint $table) {
            $table->string('category')->nullable()->index()->after('original_name')
                ->comment('หมวดรูปภาพ เช่น before, after, forgotten_item, damage สำหรับ HousekeepingTask');
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });

        Schema::create('housekeeping_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('task_id')->constrained('housekeeping_tasks')->cascadeOnDelete();
            $table->string('photo_path')->comment('พาร์ทเก็บรูปภาพใน Storage');
            $table->timestamps();
        });
    }
};
