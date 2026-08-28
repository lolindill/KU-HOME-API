<?php

namespace Tests\Unit\RoomAllocator;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\RoomAllocator\RoomAllocator;
use App\Services\RoomAllocator\Weights;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🧪 RoomAllocatorIntegrationTest — end-to-end allocation ผ่าน service
 *
 *    ใช้ SQLite in-memory + RefreshDatabase → รัน migration + seeder logic เอง
 *    สร้าง topology 100 ห้องเหมือน production (port จาก RoomSeeder logic)
 *
 *    port cases จาก playground CASES registry:
 *      C-SOLO, C-MIX, C-FAM (X09), C-TWIN, C-D5, C-SD
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §11
 */
final class RoomAllocatorIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private RoomAllocator $allocator;

    private array $typeIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTopology();
        $this->allocator = new RoomAllocator(Weights::fromConfig());
    }

    /**
     * สร้าง topology 100 ห้องเหมือน RoomSeeder (port logic ตรงๆ)
     */
    private function seedTopology(): void
    {
        $this->typeIds = [
            'Superior' => Str::uuid()->toString(),
            'Deluxe' => Str::uuid()->toString(),
            'Suite' => Str::uuid()->toString(),
        ];

        foreach ([
            ['Superior', 'Superior', 'ห้องซูพีเรียร์', 2, false, 0, 0, 1000],
            ['Deluxe',   'Deluxe',   'ห้องดีลักซ์',     2, true,  1, 500, 1800],
            ['Suite',    'Suite',    'ห้องสวีท',        4, true,  2, 600, 3500],
        ] as [$key, $en, $th, $max, $extraEnabled, $maxExtra, $extraPrice, $rate]) {
            RoomType::create([
                'id' => $this->typeIds[$key],
                'name_en' => $en,
                'name_th' => $th,
                'max_guests' => $max,
                'extra_bed_enabled' => $extraEnabled,
                'max_extra_beds' => $maxExtra,
                'extra_bed_price' => $extraPrice,
            ]);
            // 🌟 Refactor (22/07/26): rate_daily_general ย้ายไป global_rates แล้ว
            GlobalRate::create([
                'rate_type' => 'daily',
                'room_type_id' => $this->typeIds[$key],
                'code' => null,
                'name_en' => $en.' Daily',
                'default_price' => $rate,
                'is_active' => true,
            ]);
        }

        $floors = [5, 6, 7, 8, 9];
        foreach ($floors as $floor) {
            $bedType = $floor === 8 ? 'king_size' : 'twin';
            // room_number suffix (2 หลักท้าย) → prepend floor ตอนสร้าง
            $v1 = [
                ['07', 'Suite',  'V1', 1, 0],
                ['08', 'Deluxe', 'V1', 2, 0],
                ['09', 'Deluxe', 'V1', 3, 2], // X09
                ['10', 'Deluxe', 'V1', 4, 0],
                ['11', 'Deluxe', 'V1', 5, 0],
                ['12', 'Deluxe', 'V1', 6, 0],
                ['13', 'Deluxe', 'V1', 7, 0],
                ['14', 'Deluxe', 'V1', 8, 0],
                ['15', 'Deluxe', 'V1', 9, 0],
                ['16', 'Deluxe', 'V1', 10, 0],
                ['17', 'Deluxe', 'V1', 11, 0],
                ['18', 'Suite',  'V1', 12, 0],
            ];
            $v2a = [
                ['06', 'Superior', 'V2A', 1, 0],
                ['05', 'Superior', 'V2A', 2, 0],
                ['04', 'Superior', 'V2A', 3, 0],
                ['03', 'Superior', 'V2A', 4, 0],
                ['02', 'Superior', 'V2A', 5, 0],
                ['01', 'Superior', 'V2A', 6, 0],
            ];
            $v2b = [
                ['20', 'Superior', 'V2B', 1, 0],
                ['19', 'Superior', 'V2B', 2, 0],
            ];
            foreach (array_merge($v1, $v2a, $v2b) as $r) {
                Room::create([
                    'id' => Str::uuid()->toString(),
                    'room_type_id' => $this->typeIds[$r[1]],
                    'room_number' => "{$floor}{$r[0]}",
                    'status' => 'available',
                    'builtin_extra_beds' => $r[4],
                    'floor' => $floor,
                    'side' => $r[2],
                    'pos' => $r[3],
                    'bed_type' => $bedType,
                ]);
            }
        }
    }

    /** helper: สร้าง BookingRoom ใหม่ */
    private function makeBr(string $type, string $checkIn, string $checkOut, int $extraBeds = 0, ?string $bedPref = null): BookingRoom
    {
        $br = BookingRoom::create([
            'id' => Str::uuid()->toString(),
            'booking_id' => Booking::create(['id' => Str::uuid()->toString(), 'status' => 'paid', 'is_paid' => true])->id,
            'room_type_id' => $this->typeIds[$type],
            'room_id' => null,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => [['title' => 'Mr', 'name' => 'Test']],
            'status' => 'draft',
            'bed_preference' => $bedPref,
        ]);
        if ($extraBeds > 0) {
            Addon::create([
                'id' => Str::uuid()->toString(),
                'booking_room_id' => $br->id,
                'extra_bed' => $extraBeds,
                'breakfast' => 0,
                'early_checkIn_price' => 0,
                'late_checkOut_price' => 0,
                'extra_bed_price' => 0,
                'breakfast_price' => 0,
            ]);
        }

        return $br->fresh('addon', 'roomType');
    }

    // ============================================
    // ✅ C-SOLO: Deluxe×1 → assign ได้
    // ============================================
    public function test_solo_deluxe_assigns_one_room(): void
    {
        $br = $this->makeBr('Deluxe', '2026-07-14', '2026-07-16');
        $result = $this->allocator->allocate($br->newCollection([$br]));

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->assignments);
        $this->assertNotNull($result->assignments[$br->id]);
    }

    // ============================================
    // ✅ C-MIX: Suite + Deluxe×2 + Superior → ทุก slot ได้ห้อง + ใกล้กัน
    // ============================================
    public function test_mixed_types_all_slots_assigned_same_floor(): void
    {
        $brs = new EloquentCollection([
            $this->makeBr('Suite', '2026-07-14', '2026-07-16'),
            $this->makeBr('Deluxe', '2026-07-14', '2026-07-16'),
            $this->makeBr('Deluxe', '2026-07-14', '2026-07-16'),
            $this->makeBr('Superior', '2026-07-14', '2026-07-16'),
        ]);

        $result = $this->allocator->allocate($brs);

        $this->assertTrue($result->ok, 'all slots should be assigned');

        // ทุกห้องที่ assign ต้องอยู่ชั้นเดียวกัน (cluster quality)
        $assignedRooms = Room::whereIn('id', array_values(array_filter($result->assignments)))->get();
        $floors = $assignedRooms->pluck('floor')->unique();
        $this->assertCount(1, $floors, "rooms should cluster on one floor, got: {$floors->implode(',')}");
    }

    // ============================================
    // ✅ C-FAM: Deluxe + Deluxe(1 extra bed) → X09 priority
    // ============================================
    public function test_x09_priority_seed_picks_3bed_room_for_extra_bed_request(): void
    {
        $brs = new EloquentCollection([
            $this->makeBr('Deluxe', '2026-07-14', '2026-07-16', extraBeds: 0),
            $this->makeBr('Deluxe', '2026-07-14', '2026-07-16', extraBeds: 1), // extra bed
        ]);

        $result = $this->allocator->allocate($brs);

        $this->assertTrue($result->ok);

        // อย่างน้อย 1 ห้องต้องเป็น X09 (builtin_extra_beds >= 2)
        $assignedRooms = Room::whereIn('id', array_values(array_filter($result->assignments)))->get();
        $hasX09 = $assignedRooms->contains(fn ($r) => $r->builtin_extra_beds >= 2);
        $this->assertTrue($hasX09, 'X09 (3-bed builtin) should be picked for extra-bed request');
    }

    // ============================================
    // ✅ C-TWIN (king_size): 2× Deluxe pref=king_size → ได้ห้องชั้น 8 (bed_type=king_size)
    // ============================================
    public function test_king_size_preference_picks_floor_8_rooms(): void
    {
        $brs = new EloquentCollection([
            $this->makeBr('Deluxe', '2026-07-14', '2026-07-16', bedPref: 'king_size'),
            $this->makeBr('Deluxe', '2026-07-14', '2026-07-16', bedPref: 'king_size'),
        ]);

        $result = $this->allocator->allocate($brs);

        $this->assertTrue($result->ok);

        // ทุกห้องต้องเป็น bed_type=king_size (อยู่ชั้น 8) — bed_preference เป็น hard constraint
        $assignedRooms = Room::whereIn('id', array_values(array_filter($result->assignments)))->get();
        foreach ($assignedRooms as $room) {
            $this->assertSame('king_size', $room->bed_type, "room {$room->room_number} should be king_size");
        }
    }

    // ============================================
    // ✅ C-D5: Deluxe×5 → cluster ชั้นเดียวกัน + ใกล้กัน
    // ============================================
    public function test_five_deluxe_cluster_on_one_floor(): void
    {
        $brs = new EloquentCollection(
            collect(range(0, 4))->map(fn () => $this->makeBr('Deluxe', '2026-07-14', '2026-07-16')
            )->all()
        );

        $result = $this->allocator->allocate($brs);

        $this->assertTrue($result->ok);

        $assignedRooms = Room::whereIn('id', array_values(array_filter($result->assignments)))->get();
        $floors = $assignedRooms->pluck('floor')->unique();
        $this->assertCount(1, $floors, "5 Deluxe should fit on one floor, got: {$floors->implode(',')}");

        // cost ควรต่ำ (< 20 เพราะอยู่ชั้นเดียวกัน + ใกล้กัน)
        $this->assertLessThan(50.0, $result->cost, "cluster cost should be low, got {$result->cost}");
    }

    // ============================================
    // ✅ C-SD: Suite + Deluxe → ได้ทั้งคู่
    // ============================================
    public function test_suite_plus_deluxe_both_assigned(): void
    {
        $brs = new EloquentCollection([
            $this->makeBr('Suite', '2026-07-14', '2026-07-16'),
            $this->makeBr('Deluxe', '2026-07-14', '2026-07-16'),
        ]);

        $result = $this->allocator->allocate($brs);

        $this->assertTrue($result->ok);
        $this->assertCount(2, $result->assignments);
        foreach ($result->assignments as $roomId) {
            $this->assertNotNull($roomId);
        }
    }

    // ============================================
    // ✅ Overlap detection: ห้องที่ถูกจองต้องไม่ถูกเลือกซ้ำ
    // ============================================
    public function test_already_booked_room_is_not_reassigned(): void
    {
        // จอง 508 ล่วงหน้า (overlap ช่วงเดียวกับ BR ที่จะ assign)
        $existingBr = $this->makeBr('Deluxe', '2026-07-14', '2026-07-16');
        $existingBr->update(['room_id' => Room::where('room_number', '508')->value('id'), 'status' => 'confirmed']);

        // สร้าง BR ใหม่ Deluxe 1 ห้อง ช่วงเดียวกัน
        $newBr = $this->makeBr('Deluxe', '2026-07-14', '2026-07-16');
        $result = $this->allocator->allocate($newBr->newCollection([$newBr]));

        $this->assertTrue($result->ok);
        $assignedRoomId = $result->assignments[$newBr->id];
        $this->assertNotEquals($existingBr->room_id, $assignedRoomId, 'must not pick 508 (already booked)');
    }

    // ============================================
    // ✅ ไม่มีห้องว่างเลย → ok=false
    // ============================================
    public function test_no_available_rooms_returns_failed(): void
    {
        // จอง Deluxe ทุกห้องในช่วงนี้
        $deluxeRooms = Room::whereHas('roomType', fn ($q) => $q->where('name_en', 'Deluxe'))->get();
        foreach ($deluxeRooms as $room) {
            $br = $this->makeBr('Deluxe', '2026-07-14', '2026-07-16');
            $br->update(['room_id' => $room->id, 'status' => 'confirmed']);
        }

        // สร้าง BR Deluxe ใหม่
        $newBr = $this->makeBr('Deluxe', '2026-07-14', '2026-07-16');
        $result = $this->allocator->allocate($newBr->newCollection([$newBr]));

        $this->assertFalse($result->ok);
    }
}
