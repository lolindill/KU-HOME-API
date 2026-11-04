<?php

namespace Tests\Unit\RoomAllocator;

use App\Services\RoomAllocator\Dto\RoomDto;
use App\Services\RoomAllocator\Topology;
use PHPUnit\Framework\TestCase;

/**
 * 🧪 TopologyTest — ทดสอบการแปลงพิกัดและระยะเดิน (pure unit, ไม่ใช้ DB)
 *
 *    ตรวจสอบการ port จาก playground:
 *      - getGlobalPos() (V1/V2A/V2B)
 *      - getWalkingDist() (เดินผ่านบันได 2 ข้าง)
 *      - normalizeSide()
 *
 *    อ้างอิงตัวอย่างจาก docs/algo_test/room-algorithm-flow-explained.md §4
 */
final class TopologyTest extends TestCase
{
    /** helper: สร้าง RoomDto อย่างง่าย */
    private function room(string $num, string $type, string $side, int $pos, int $floor): RoomDto
    {
        return new RoomDto(
            id: 'test-'.$num,
            num: $num,
            type: $type,
            side: $side,
            pos: $pos,
            beds: 1,
            floor: $floor,
            bedType: 'twin',
        );
    }

    // ============================================
    // 🌍 getGlobalPos — แปลง 3 ฝั่ง → เส้นตรง 1D
    // ============================================

    public function test_global_pos_v1(): void
    {
        $r = $this->room('507', 'Suite', 'V1', 1, 5);
        $this->assertSame(1, Topology::globalPos($r));

        $r = $this->room('518', 'Suite', 'V1', 12, 5);
        $this->assertSame(12, Topology::globalPos($r));
    }

    public function test_global_pos_v2a(): void
    {
        // V2A: pos 1-6 → global 2-7 (เว้น 1 ให้บันไดซ้าย)
        $r = $this->room('506', 'Superior', 'V2A', 1, 5);
        $this->assertSame(2, Topology::globalPos($r));

        $r = $this->room('501', 'Superior', 'V2A', 6, 5);
        $this->assertSame(7, Topology::globalPos($r));
    }

    public function test_global_pos_v2b(): void
    {
        // V2B: pos 1-2 → global 10-11 (ข้ามลิฟต์ 2 ตัว)
        $r = $this->room('520', 'Superior', 'V2B', 1, 5);
        $this->assertSame(10, Topology::globalPos($r));

        $r = $this->room('519', 'Superior', 'V2B', 2, 5);
        $this->assertSame(11, Topology::globalPos($r));
    }

    // ============================================
    // 🚶 getWalkingDist — ระยะเดินจริงผ่านบันได
    // ============================================

    public function test_walking_dist_same_floor(): void
    {
        // 508 (V1 p2) → 510 (V1 p4): |2-4| = 2
        $a = $this->room('508', 'Deluxe', 'V1', 2, 5);
        $b = $this->room('510', 'Deluxe', 'V1', 4, 5);
        $this->assertSame(2, Topology::walkingDist($a, $b));
    }

    public function test_walking_dist_same_floor_cross_side(): void
    {
        // 508 (V1 p2 global 2) → 501 (V2A p6 global 7): |2-7| = 5
        $a = $this->room('508', 'Deluxe', 'V1', 2, 5);
        $b = $this->room('501', 'Superior', 'V2A', 6, 5);
        $this->assertSame(5, Topology::walkingDist($a, $b));
    }

    public function test_walking_dist_diff_floor_via_left_stair(): void
    {
        // 508 (V1 p2 global 2) → 608 (V1 p2 global 2) ต่างชั้น
        // viaLeft  = |2-1| + |2-1| = 1 + 1 = 2
        // viaRight = |2-12| + |2-12| = 10 + 10 = 20
        // → min = 2
        $a = $this->room('508', 'Deluxe', 'V1', 2, 5);
        $b = $this->room('608', 'Deluxe', 'V1', 2, 6);
        $this->assertSame(2, Topology::walkingDist($a, $b));
    }

    public function test_walking_dist_diff_floor_via_right_stair(): void
    {
        // 518 (V1 p12 global 12) → 618 (V1 p12 global 12) ต่างชั้น
        // viaLeft  = |12-1| + |12-1| = 11 + 11 = 22
        // viaRight = |12-12| + |12-12| = 0 + 0 = 0
        // → min = 0
        $a = $this->room('518', 'Suite', 'V1', 12, 5);
        $b = $this->room('618', 'Suite', 'V1', 12, 6);
        $this->assertSame(0, Topology::walkingDist($a, $b));
    }

    public function test_walking_dist_diff_floor_v2b(): void
    {
        // 508 (V1 p2 global 2) → 620 (V2B p1 global 10) ต่างชั้น
        // viaLeft  = |2-1| + |10-1| = 1 + 9 = 10
        // viaRight = |2-12| + |10-12| = 10 + 2 = 12
        // → min = 10
        $a = $this->room('508', 'Deluxe', 'V1', 2, 5);
        $b = $this->room('620', 'Superior', 'V2B', 1, 6);
        $this->assertSame(10, Topology::walkingDist($a, $b));
    }

    public function test_walking_dist_symmetric(): void
    {
        // ระยะ A→B เท่ากับ B→A
        $a = $this->room('508', 'Deluxe', 'V1', 2, 5);
        $b = $this->room('620', 'Superior', 'V2B', 1, 6);
        $this->assertSame(Topology::walkingDist($a, $b), Topology::walkingDist($b, $a));
    }

    public function test_walking_dist_same_room_zero(): void
    {
        $a = $this->room('508', 'Deluxe', 'V1', 2, 5);
        $this->assertSame(0, Topology::walkingDist($a, $a));
    }

    // ============================================
    // 🔧 normalizeSide
    // ============================================

    public function test_normalize_side_v1(): void
    {
        $this->assertSame('V1', Topology::normalizeSide('V1'));
    }

    public function test_normalize_side_v2a_and_v2b(): void
    {
        $this->assertSame('V2', Topology::normalizeSide('V2A'));
        $this->assertSame('V2', Topology::normalizeSide('V2B'));
    }
}
