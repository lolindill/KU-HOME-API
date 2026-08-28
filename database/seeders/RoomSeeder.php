<?php

namespace Database\Seeders;

use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 🏨 KU HOME Topology Seeder (100 rooms)
 *
 *    แทนที่ topology เดิม 8 ห้อง ด้วย 100 ห้องตาม playground
 *    docs/algo_test/room-algorithm-playground-eav3.html (buildRooms())
 *
 *    Layout แต่ละชั้น (5-9) × 20 ห้อง:
 *
 *      V1  (ฝั่งวิว 1, pos 1-12, 12 ห้อง):
 *          {f}07 Suite · {f}08 Deluxe · {f}09 Deluxe(3-bed) · {f}10-17 Deluxe · {f}18 Suite
 *
 *      V2A (ฝั่งวิว 2 ซีก A, pos 1-6, 6 ห้อง):
 *          {f}06..{f}01 Superior   (นับจากใกล้บันไดซ้าย → ออก)
 *
 *      V2B (ฝั่งวิว 2 ซีก B, pos 1-2, 2 ห้อง):
 *          {f}20, {f}19 Superior   (หลังลิฟต์ 2 ตัว)
 *
 *    X09 พิเศษ: {f}09 = Deluxe 3-bed builtin (builtin_extra_beds=2)
 *    ชั้น 8: bed_type='king_size' ทุกห้อง (อื่นๆ bed_type='twin')
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §2 (Topology)
 */
class RoomSeeder extends Seeder
{
    /** ชั้นทั้งหมดในอาคาร */
    private const FLOORS = [5, 6, 7, 8, 9];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 🏨 1. สร้าง Room Types 3 แบบ และเก็บ UUID ไว้เชื่อมกับห้องค่ะ
        $standardId = Str::uuid();
        $deluxeId = Str::uuid();
        $suiteId = Str::uuid();

        // 🌟 Refactor (22/07/26): rate_daily_general ย้ายไป global_rates แล้ว
        // เก็บ daily rate แยกไว้สร้าง GlobalRate rows ทีหลัง (ด้านล่าง)
        $roomTypeSpecs = [
            [
                'id' => $standardId,
                'name_en' => 'Superior',
                'name_th' => 'ห้องซูพีเรียร์',
                'max_guests' => 2,
                'extra_bed_enabled' => false,
                'max_extra_beds' => 0,
                'extra_bed_price' => 0,
                'daily_rate' => 1000,
            ],
            [
                'id' => $deluxeId,
                'name_en' => 'Deluxe',
                'name_th' => 'ห้องดีลักซ์',
                'max_guests' => 2,
                'extra_bed_enabled' => true,
                'max_extra_beds' => 1,
                'extra_bed_price' => 500,
                'daily_rate' => 1800,
            ],
            [
                'id' => $suiteId,
                'name_en' => 'Suite',
                'name_th' => 'ห้องสวีท',
                'max_guests' => 4,
                'extra_bed_enabled' => true,
                'max_extra_beds' => 2,
                'extra_bed_price' => 600,
                'daily_rate' => 3500,
            ],
        ];

        foreach ($roomTypeSpecs as $spec) {
            $dailyRate = $spec['daily_rate'];
            unset($spec['daily_rate']);
            RoomType::create($spec);

            // 🌟 Seed room rate row ใน global_rates (rate_type='daily')
            GlobalRate::firstOrCreate(
                ['room_type_id' => $spec['id'], 'rate_type' => 'daily'],
                [
                    'code' => null,
                    'name_en' => $spec['name_en'].' Daily',
                    'name_th' => $spec['name_en'].' (ราคารายวัน)',
                    'default_price' => $dailyRate,
                    'is_active' => true,
                ]
            );
        }

        // 🚪 2. สร้าง 100 ห้องตาม topology KU HOME
        $created = 0;

        foreach (self::FLOORS as $floor) {
            $bedType = $floor === 8 ? 'king_size' : 'twin';

            // ---- V1 (ฝั่งวิว 1, pos 1-12) ----
            // {f}07 Suite (pos 1), {f}08-17 Deluxe (pos 2-11), {f}18 Suite (pos 12)
            // {f}09 = Deluxe 3-bed builtin (pos 3) — X09 special
            $v1 = [
                ['num' => "{$floor}07", 'type' => 'Suite',    'side' => 'V1', 'pos' => 1,  'builtin' => 0],
                ['num' => "{$floor}08", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 2,  'builtin' => 0],
                ['num' => "{$floor}09", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 3,  'builtin' => 2], // 🛏️ X09 3-bed builtin
                ['num' => "{$floor}10", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 4,  'builtin' => 0],
                ['num' => "{$floor}11", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 5,  'builtin' => 0],
                ['num' => "{$floor}12", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 6,  'builtin' => 0],
                ['num' => "{$floor}13", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 7,  'builtin' => 0],
                ['num' => "{$floor}14", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 8,  'builtin' => 0],
                ['num' => "{$floor}15", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 9,  'builtin' => 0],
                ['num' => "{$floor}16", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 10, 'builtin' => 0],
                ['num' => "{$floor}17", 'type' => 'Deluxe',   'side' => 'V1', 'pos' => 11, 'builtin' => 0],
                ['num' => "{$floor}18", 'type' => 'Suite',    'side' => 'V1', 'pos' => 12, 'builtin' => 0],
            ];

            // ---- V2A (ฝั่งวิว 2 ซีก A, pos 1-6) ----
            // {f}06..{f}01 Superior (นับจากใกล้บันไดซ้าย → ออก)
            $v2a = [
                ['num' => "{$floor}06", 'type' => 'Superior', 'side' => 'V2A', 'pos' => 1, 'builtin' => 0],
                ['num' => "{$floor}05", 'type' => 'Superior', 'side' => 'V2A', 'pos' => 2, 'builtin' => 0],
                ['num' => "{$floor}04", 'type' => 'Superior', 'side' => 'V2A', 'pos' => 3, 'builtin' => 0],
                ['num' => "{$floor}03", 'type' => 'Superior', 'side' => 'V2A', 'pos' => 4, 'builtin' => 0],
                ['num' => "{$floor}02", 'type' => 'Superior', 'side' => 'V2A', 'pos' => 5, 'builtin' => 0],
                ['num' => "{$floor}01", 'type' => 'Superior', 'side' => 'V2A', 'pos' => 6, 'builtin' => 0],
            ];

            // ---- V2B (ฝั่งวิว 2 ซีก B, pos 1-2) ----
            // {f}20, {f}19 Superior (หลังลิฟต์ 2 ตัว)
            $v2b = [
                ['num' => "{$floor}20", 'type' => 'Superior', 'side' => 'V2B', 'pos' => 1, 'builtin' => 0],
                ['num' => "{$floor}19", 'type' => 'Superior', 'side' => 'V2B', 'pos' => 2, 'builtin' => 0],
            ];

            foreach (array_merge($v1, $v2a, $v2b) as $r) {
                $typeId = $r['type'] === 'Suite' ? $suiteId
                        : ($r['type'] === 'Deluxe' ? $deluxeId : $standardId);

                Room::create([
                    'id' => Str::uuid(),
                    'room_type_id' => $typeId,
                    'room_number' => $r['num'],
                    'status' => 'available',
                    'builtin_extra_beds' => $r['builtin'],
                    // 🌟 Phase 1 topology columns
                    'floor' => $floor,
                    'side' => $r['side'],
                    'pos' => $r['pos'],
                    'bed_type' => $bedType,
                ]);
                $created++;
            }
        }

        $this->command->info("เย้! น้องเมดสร้างประเภทห้องพัก 3 แบบ และห้องพัก {$created} ห้อง (5 ชั้น × 20) ให้นายท่านเรียบร้อยแล้วค่ะ! 🎉");
    }
}
