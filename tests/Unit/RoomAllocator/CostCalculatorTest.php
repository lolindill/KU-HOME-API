<?php

namespace Tests\Unit\RoomAllocator;

use App\Services\RoomAllocator\CostCalculator;
use App\Services\RoomAllocator\Dto\BookingRequestDto;
use App\Services\RoomAllocator\Dto\RoomDto;
use App\Services\RoomAllocator\Weights;
use PHPUnit\Framework\TestCase;

/**
 * 🧪 CostCalculatorTest — ทดสอบ costFunction + walkCost (Final Judge)
 *
 *    pure unit (ไม่ใช้ DB) — ใช้ Weights เริ่มต้นเหมือน playground:
 *      floor=50, side=8, pos=3, bed=5, walk=1
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §5-6, §12
 */
final class CostCalculatorTest extends TestCase
{
    private CostCalculator $cost;

    protected function setUp(): void
    {
        // weights default ตรงกับ config/allocation.php + playground
        $w = new Weights(
            floor: 50, side: 8, pos: 3, bed: 5, walk: 1,
            x09Priority: true, x09MinBuiltinBeds: 2,
            maxWindows: 5000, maxSeedTrials: 15,
            bruteComboCaps: [1 => PHP_INT_MAX, 2 => PHP_INT_MAX, 3 => 80, 4 => 40, 5 => 25, 6 => 20],
        );
        $this->cost = new CostCalculator($w);
    }

    /** helper: สร้าง RoomDto อย่างง่าย */
    private function room(string $num, string $type, string $side, int $pos, int $floor, int $beds = 1, string $bedType = 'twin'): RoomDto
    {
        return new RoomDto(
            id: 'test-'.$num,
            num: $num,
            type: $type,
            side: $side,
            pos: $pos,
            beds: $beds,
            floor: $floor,
            bedType: $bedType,
        );
    }

    /** helper: สร้าง BookingRequestDto อย่างง่าย */
    private function br(string $type, int $extraBeds = 0, ?string $bedPref = null): BookingRequestDto
    {
        return new BookingRequestDto(
            brId: 'br-'.uniqid(),
            type: $type,
            checkIn: '2026-07-14',
            checkOut: '2026-07-16',
            extraBeds: $extraBeds,
            bedPreference: $bedPref,
        );
    }

    // ============================================
    // 💰 cost() — guide cost (multi-dimension)
    // ============================================

    public function test_cost_empty_pairs_is_infinity(): void
    {
        $this->assertSame(INF, $this->cost->cost([]));
    }

    public function test_cost_single_room_no_spread(): void
    {
        // ห้องเดียว → floorSpread=0, sideMismatch=0, posSpread=0, bedWaste=0, pref=0
        $pairs = [CostCalculator::pair($this->room('508', 'Deluxe', 'V1', 2, 5), $this->br('Deluxe'))];
        $this->assertSame(0.0, $this->cost->cost($pairs));
    }

    public function test_cost_floor_spread_penalty(): void
    {
        // 2 ห้องต่างชั้น → floorSpread = (2-1) × 50 = 50
        $pairs = [
            CostCalculator::pair($this->room('508', 'Deluxe', 'V1', 2, 5), $this->br('Deluxe')),
            CostCalculator::pair($this->room('608', 'Deluxe', 'V1', 2, 6), $this->br('Deluxe')),
        ];
        $cost = $this->cost->cost($pairs);
        // floor=50, side=0 (both V1), posSpread=0 (both global 2), bed=0, pref=0
        $this->assertSame(50.0, $cost);
    }

    public function test_cost_side_mismatch_penalty(): void
    {
        // 2 ห้อง ชั้นเดียวกัน ต่างฝั่ง V1↔V2A
        $pairs = [
            CostCalculator::pair($this->room('508', 'Deluxe', 'V1', 2, 5), $this->br('Deluxe')),
            CostCalculator::pair($this->room('506', 'Deluxe', 'V2A', 1, 5), $this->br('Deluxe')),
        ];
        $cost = $this->cost->cost($pairs);
        // floor=0, side=8, posSpread = (3-2)² × 3 = 3 (global 508=2, 506=2 นี่ผิด!
        // แก้: 508 global=2, 506 (V2A p1) global=2 → distance=0 → posSpread=0
        // → total = 0 + 8 + 0 + 0 + 0 = 8
        $this->assertSame(8.0, $cost);
    }

    public function test_cost_pos_spread_quadratic(): void
    {
        // 2 ห้องกระจายไกล: 507 (V1 p1 global 1) ↔ 518 (V1 p12 global 12)
        // posSpread = (12-1)² × 3 = 121 × 3 = 363
        $pairs = [
            CostCalculator::pair($this->room('507', 'Suite', 'V1', 1, 5), $this->br('Suite')),
            CostCalculator::pair($this->room('518', 'Suite', 'V1', 12, 5), $this->br('Suite')),
        ];
        $cost = $this->cost->cost($pairs);
        // floor=0, side=0, posSpread=363, bed=0, pref=0
        $this->assertSame(363.0, $cost);
    }

    public function test_cost_bed_preference_mismatch(): void
    {
        // ห้อง twin (ชั้นอื่น) แต่ booking อยากได้ king_size → +100
        $pairs = [CostCalculator::pair(
            $this->room('508', 'Deluxe', 'V1', 2, 5), // default bed_type=twin
            $this->br('Deluxe', bedPref: 'king_size'),
        )];
        $cost = $this->cost->cost($pairs);
        $this->assertSame(100.0, $cost);
    }

    public function test_cost_bed_preference_match_no_penalty(): void
    {
        $pairs = [CostCalculator::pair(
            $this->room('808', 'Deluxe', 'V1', 2, 8, 1, 'king_size'),
            $this->br('Deluxe', bedPref: 'king_size'),
        )];
        $this->assertSame(0.0, $this->cost->cost($pairs));
    }

    public function test_cost_bed_waste_when_room_has_too_many_beds(): void
    {
        // ห้อง 3 เตียง (builtin_extra_beds=2) แต่ booking ขอแค่ 0 extra
        // bedWaste = max(0, 3 - 1 - 0) × 5 = 2 × 5 = 10
        $pairs = [CostCalculator::pair(
            $this->room('509', 'Deluxe', 'V1', 3, 5, 3, 'twin'), // X09 = 3 beds
            $this->br('Deluxe', extraBeds: 0),
        )];
        $cost = $this->cost->cost($pairs);
        $this->assertSame(10.0, $cost);
    }

    // ============================================
    // 🚶 walkCost() — Final Judge (Σ pairwise)
    // ============================================

    public function test_walk_cost_single_room_no_floor_penalty(): void
    {
        // ห้องเดียว → sumWalk=0, floorPenalty=0 → total=0
        $pairs = [CostCalculator::pair($this->room('508', 'Deluxe', 'V1', 2, 5), $this->br('Deluxe'))];
        $this->assertSame(0.0, $this->cost->walkCost($pairs));
    }

    public function test_walk_cost_same_floor_no_floor_penalty(): void
    {
        // 2 ห้องชั้นเดียวกัน 508↔510 → walkingDist=2
        // floorPenalty = 0 (distinct floors = 1 → penalty = 0)
        // walkCost = 2 × 1 + 0 = 2
        $pairs = [
            CostCalculator::pair($this->room('508', 'Deluxe', 'V1', 2, 5), $this->br('Deluxe')),
            CostCalculator::pair($this->room('510', 'Deluxe', 'V1', 4, 5), $this->br('Deluxe')),
        ];
        $this->assertSame(2.0, $this->cost->walkCost($pairs));
    }

    public function test_walk_cost_diff_floor_includes_floor_penalty(): void
    {
        // 2 ห้องต่างชั้น 508↔608 → walkingDist=2 (viaLeft)
        // floorPenalty = (2-1) × 50 = 50
        // walkCost = 2 × 1 + 50 = 52
        $pairs = [
            CostCalculator::pair($this->room('508', 'Deluxe', 'V1', 2, 5), $this->br('Deluxe')),
            CostCalculator::pair($this->room('608', 'Deluxe', 'V1', 2, 6), $this->br('Deluxe')),
        ];
        $this->assertSame(52.0, $this->cost->walkCost($pairs));
    }

    public function test_walk_cost_3_rooms_sum_all_pairs(): void
    {
        // 3 ห้องชั้นเดียวกัน V1 p2,p4,p6
        // pairs: (2,4)=2 + (2,6)=4 + (4,6)=2 = 8
        // walkCost = 8 × 1 + 0 = 8
        $pairs = [
            CostCalculator::pair($this->room('508', 'Deluxe', 'V1', 2, 5), $this->br('Deluxe')),
            CostCalculator::pair($this->room('510', 'Deluxe', 'V1', 4, 5), $this->br('Deluxe')),
            CostCalculator::pair($this->room('512', 'Deluxe', 'V1', 6, 5), $this->br('Deluxe')),
        ];
        $this->assertSame(8.0, $this->cost->walkCost($pairs));
    }

    // ============================================
    // 📊 breakdown() — แยก dimension
    // ============================================

    public function test_breakdown_total_equals_walk_cost(): void
    {
        $pairs = [
            CostCalculator::pair($this->room('508', 'Deluxe', 'V1', 2, 5), $this->br('Deluxe')),
            CostCalculator::pair($this->room('608', 'Deluxe', 'V1', 2, 6), $this->br('Deluxe')),
        ];
        $bd = $this->cost->breakdown($pairs);
        $this->assertSame($bd['walk'], $bd['total'], 'total must equal walk (v3 Final Judge)');
        $this->assertSame(50.0, $bd['floor']); // floor penalty = (2-1) × 50
    }
}
