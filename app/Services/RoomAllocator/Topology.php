<?php

namespace App\Services\RoomAllocator;

/**
 * 🏢 KU HOME Topology — ศูนย์กลางการแปลงพิกัดแบบเดียวกับ playground
 *
 *    Port จาก docs/algo_test/room-algorithm-playground-eav3.html:
 *      - getGlobalPos(), getWalkingDist()
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §3-4
 *
 *    ห้ามแก้ค่าคงที่เด็ดขาด — topology ตรงกับ seeder (RoomSeeder) ทั้งหมด
 */
final class Topology
{
    /** ชั้นทั้งหมดในอาคาร (5-9) */
    public const FLOORS = [5, 6, 7, 8, 9];

    /** ตำแหน่ง global ของบันไดทั้ง 2 ข้าง */
    public const STAIR_LEFT = 1;

    public const STAIR_RIGHT = 12;

    /**
     * 💖 Global Position System — แปลงพิกัด 3 ฝั่ง (V1, V2A, V2B) เป็นเส้นตรง 1D
     *
     *    - V1:  pos 1-12  → global 1-12
     *    - V2A: pos 1-6   → global 2-7  (เว้น 1 ให้บันไดซ้าย)
     *    - V2B: pos 1-2   → global 10-11 (ข้ามลิฟต์ 2 ตัว)
     */
    public static function globalPos(Dto\RoomDto $room): int
    {
        switch ($room->side) {
            case 'V1':  return $room->pos;
            case 'V2A': return $room->pos + 1;
            case 'V2B': return $room->pos + 9;
            default:    return $room->pos;
        }
    }

    /**
     * 🚶 Walking Distance — ระยะเดินจริงผ่านบันได 2 ข้าง
     *
     *    วัดเฉพาะแนวนอน (vertical คุมด้วย floor penalty แยกใน walkCost)
     *      - ชั้นเดียวกัน: |gA - gB|
     *      - ต่างชั้น: ลงบันไดฝั่งหนึ่ง → ข้ามชั้น → เดินเข้าห้อง = min(viaLeft, viaRight)
     *
     *    ไม่พับตัว U เหมือน v2 (pos1 กับ pos12 = 11 ไม่ใช่ 0)
     */
    public static function walkingDist(Dto\RoomDto $a, Dto\RoomDto $b): int
    {
        $gA = self::globalPos($a);
        $gB = self::globalPos($b);

        if ($a->floor === $b->floor) {
            return abs($gA - $gB);
        }

        $viaLeft = abs($gA - self::STAIR_LEFT) + abs($gB - self::STAIR_LEFT);
        $viaRight = abs($gA - self::STAIR_RIGHT) + abs($gB - self::STAIR_RIGHT);

        return min($viaLeft, $viaRight);
    }

    /**
     * Normalized side — V1 คืน 'V1', V2A/V2B คืน 'V2' (รวมฝั่งวิว 2 เป็นฝั่งเดียวกัน)
     */
    public static function normalizeSide(string $side): string
    {
        return str_starts_with($side, 'V2') ? 'V2' : $side;
    }
}
