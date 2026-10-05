<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 📊 (05/10/26) excel-reports spec §2.2 — reshape addons ให้รองรับรายคืน/แยกชุด (ticket 10)
 *
 *    เดิม → ใหม่:
 *    - breakfast (int เดียวทั้ง stay)   → breakfast_set_100 + breakfast_set_200 (int, default 0)
 *      data migration: breakfast เดิม → breakfast_set_200 (เรทเดิม 200 ตรงกันพอดี)
 *    - extra_bed (int เดียวทั้ง stay)   → extra_beds_by_night (JSON) เช่น {"2026-09-11": 1}
 *      data migration: int เดิม → flat map ทุกคืนของ stay [check_in, check_out) — คงยอดเงินเดิม
 *      (ยอด = qty × เรท × คืน เท่าเดิมพอดีเมื่อ qty ทุกคืนเท่ากัน)
 *
 *    column snapshot breakfast_price / extra_bed_price / early_* / late_* คงเดิม (reprice คำนวณใหม่)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addons', function (Blueprint $table) {
            $table->integer('breakfast_set_100')->default(0)
                ->comment('จำนวนชุดอาหารเช้า 100 บาท (คิดเงิน × คืน)')
                ->after('booking_room_id');
            $table->integer('breakfast_set_200')->default(0)
                ->comment('จำนวนชุดอาหารเช้า 200 บาท (คิดเงิน × คืน)')
                ->after('breakfast_set_100');
            $table->json('extra_beds_by_night')->nullable()
                ->comment('เตียงเสริมรายคืน เช่น {"2026-09-11": 1, "2026-09-12": 2} — คีย์ = คืนใน [check_in, check_out)')
                ->after('breakfast_set_200');
        });

        // ── data migration ───────────────────────────────────────────────
        // breakfast เดิม → set_200 (เรท 200 ตรงกัน) · extra_bed int → flat map ทุกคืน
        $addons = DB::table('addons')
            ->join('booking_rooms', 'booking_rooms.id', '=', 'addons.booking_room_id')
            ->get([
                'addons.id as addon_id',
                'addons.breakfast as breakfast',
                'addons.extra_bed as extra_bed',
                'booking_rooms.check_in as check_in',
                'booking_rooms.check_out as check_out',
            ]);

        foreach ($addons as $row) {
            $nights = $this->nightsBetween($row->check_in, $row->check_out);

            $byNight = null;
            if ((int) $row->extra_bed > 0 && $nights !== []) {
                $byNight = [];
                foreach ($nights as $night) {
                    $byNight[$night] = (int) $row->extra_bed;
                }
            }

            DB::table('addons')->where('id', $row->addon_id)->update([
                'breakfast_set_200' => (int) $row->breakfast,
                'extra_beds_by_night' => $byNight !== null ? json_encode($byNight) : null,
            ]);
        }

        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn(['breakfast', 'extra_bed']);
        });
    }

    public function down(): void
    {
        // best-effort rollback: set_200 กลับเป็น breakfast · flat map → int (ค่าคืนแรก —
        // การ migrate ตั้งต้นจาก int เดียวทั้ง stay จึง restore ได้ตรงเว้นแต่ถูกแก้รายคืนหลัง deploy)
        Schema::table('addons', function (Blueprint $table) {
            $table->integer('breakfast')->default(0);
            $table->integer('extra_bed')->default(0);
        });

        $addons = DB::table('addons')->get(['id', 'breakfast_set_200', 'extra_beds_by_night']);
        foreach ($addons as $row) {
            $byNight = $row->extra_beds_by_night !== null ? json_decode($row->extra_beds_by_night, true) : [];
            $flat = is_array($byNight) && $byNight !== [] ? (int) max($byNight) : 0;

            DB::table('addons')->where('id', $row->id)->update([
                'breakfast' => (int) $row->breakfast_set_200,
                'extra_bed' => $flat,
            ]);
        }

        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn(['breakfast_set_100', 'breakfast_set_200', 'extra_beds_by_night']);
        });
    }

    /**
     * รายการคืน (คืนนอน) ของ stay เป็น YYYY-MM-DD — ช่วง [check_in, check_out) แบบ half-open
     * เหมือนนิยามคืนของ booking (walk-in เป็น datetime — startOfDay ก่อนนับ)
     *
     * @return list<string>
     */
    private function nightsBetween(?string $checkIn, ?string $checkOut): array
    {
        if ($checkIn === null || $checkOut === null) {
            return [];
        }

        try {
            $in = \Carbon\Carbon::parse($checkIn)->startOfDay();
            $out = \Carbon\Carbon::parse($checkOut)->startOfDay();
        } catch (\Exception) {
            return [];
        }

        $nights = [];
        for ($d = $in->copy(); $d->lt($out); $d->addDay()) {
            $nights[] = $d->toDateString();
        }

        return $nights;
    }
};
