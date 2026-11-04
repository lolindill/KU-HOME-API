<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 🗓️ wayfinder/room-state-periods (2026-09-24): "ห้องสำรอง" และ "ซ่อมแซม" เป็นช่วงเวลาแบบ booking
 *
 *    - สร้างตาราง room_state_periods (kind reserved|maintenance, start/end เปิดปลายได้)
 *      — single source of truth · derived ตอน query ไม่มี sweep
 *    - แปลงข้อมูลเดิม (ticket 05):
 *        rooms.is_reserved = true  → reserved period เปิดปลาย (start = วัน deploy, end = NULL, created_by = NULL)
 *        rooms.status = maintenance → maintenance period เปิดปลาย + flip lifecycle → available
 *                                    + audit log (ให้ประวัติ REQ-039 ไม่มีรู)
 *    - drop column rooms.is_reserved พร้อม release เดียว — ไม่มี dual source (rollback ทั้งก้อนพร้อมกัน)
 *      (แทนที่ migration 2026_09_24_091500_add_is_reserved_to_rooms ของ map reserved-room-pool — decision 90 ถูก override)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_state_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('room_id');
            // kind = reserved | maintenance — string + validate ที่ FormRequest ตามธรรมเนียม status ของโปรเจกต์
            $table->string('kind');
            // start ย้อนอดีตได้ ("ท่อระเบิดคืนวานบันทึกเช้านี้") · end เปิดปลายได้ทั้งสอง kind (ticket 05)
            // end เป็น exclusive เหมือน check_out — "ถึงวันที่ 30" = คืน 29 คืนสุดท้ายที่ห้องหาย
            $table->date('start_date');
            $table->date('end_date')->nullable();
            // ใครเปิด period — NULL = row จากระบบ/migration (เหตุการณ์ทั้งหมดดู status_change_logs เสริม)
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('room_id')->references('id')->on('rooms')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['room_id', 'start_date', 'end_date']);
            $table->index('kind');
        });

        $today = now()->toDateString();
        $now = now();

        // 2a. ห้องสำรองค้าง → reserved period เปิดปลาย — คงพฤติกรรมที่มองเห็นอยู่
        //     (ห้องยังถูกกันต่อ ไม่มีอะไรหายฉับพลัน) แต่ย้ายเข้าร่าง period ที่ admin ปลดได้ผ่าน DELETE/PATCH
        $reservedRoomIds = DB::table('rooms')->whereRaw('is_reserved = TRUE')->pluck('id');
        foreach ($reservedRoomIds as $roomId) {
            DB::table('room_state_periods')->insert([
                'id' => Str::uuid(),
                'room_id' => $roomId,
                'kind' => 'reserved',
                'start_date' => $today,
                'end_date' => null,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 2b. ห้อง maintenance ค้าง → maintenance period เปิดปลาย + flip lifecycle → available
        //     (owner override 2026-09-24: ช่วง period active การกันห้องทำงานจาก period อยู่แล้ว
        //      status เป็นแค่ lifecycle — ระบบไม่เดาวันจบแทนความจริง เจ้าหน้าที่ PATCH/DELETE เองเมื่อซ่อมเสร็จ)
        $maintenanceRoomIds = DB::table('rooms')->where('status', 'maintenance')->pluck('id');
        foreach ($maintenanceRoomIds as $roomId) {
            DB::table('room_state_periods')->insert([
                'id' => Str::uuid(),
                'room_id' => $roomId,
                'kind' => 'maintenance',
                'start_date' => $today,
                'end_date' => null,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('rooms')->where('id', $roomId)->update([
                'status' => 'available',
                'status_updated_at' => $now,
            ]);

            // audit log ให้ flip — ประวัติสถานะห้อง 1 ปี (REQ-039 / ticket 03) ไม่มีรู
            DB::table('status_change_logs')->insert([
                'id' => Str::uuid(),
                'entity_type' => 'room',
                'entity_id' => $roomId,
                'from_status' => 'maintenance',
                'to_status' => 'available',
                'role' => 'system',
                'causer_id' => null,
                'note' => 'period migration: สถานะ maintenance ถูกถอดออกจาก machine — แทนด้วย room_state_periods (kind=maintenance เปิดปลาย)',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 3. drop พร้อม release เดียว — โค้ดทุกจุดสลับเป็น period-check ขึ้นพร้อมกัน
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('is_reserved');
        });
    }

    public function down(): void
    {
        // best-effort rollback: สร้าง column คืน แล้วให้ค่าเดิมกับห้องที่มี period active ณ ตอนนั้น
        Schema::table('rooms', function (Blueprint $table) {
            $table->boolean('is_reserved')->default(false)->after('status');
        });

        $today = now()->toDateString();

        // PostgreSQL strict boolean — เขียนด้วย SQL literal ตาม gotcha (มิใช่ bind int 0/1)
        $activeReserved = DB::table('room_state_periods')
            ->where('kind', 'reserved')
            ->where('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>', $today);
            })
            ->pluck('room_id');
        foreach ($activeReserved as $roomId) {
            DB::table('rooms')->where('id', $roomId)->update(['is_reserved' => DB::raw('TRUE')]);
        }

        $activeMaintenance = DB::table('room_state_periods')
            ->where('kind', 'maintenance')
            ->where('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>', $today);
            })
            ->pluck('room_id');
        foreach ($activeMaintenance as $roomId) {
            DB::table('rooms')->where('id', $roomId)->update(['status' => 'maintenance']);
        }

        Schema::dropIfExists('room_state_periods');
    }
};
