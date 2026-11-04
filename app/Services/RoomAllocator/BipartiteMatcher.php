<?php

namespace App\Services\RoomAllocator;

/**
 * 🧩 Bipartite Matching (Kuhn's algorithm)
 *
 *    Port ตรงจาก playground bipartiteMatch() — ใช้ใน algorithm A (Block), C (Coordinate),
 *    FF (Floor-First) เพื่อหา matching แบบ 1-to-1 ระหว่าง slot ↔ ตำแหน่งห้อง
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §9 (Algorithm A/C/FF)
 */
final class BipartiteMatcher
{
    /**
     * หา maximum bipartite matching
     *
     * @param  array<array<int>>  $adj  adj[slot] = [position indices] ที่ slot นี้สามารถจับคู่ได้
     * @return array{matchPos: array<int>, matched: int}
     *                                                   matchPos[slot] = position ที่จับคู่ได้ หรือ -1 ถ้าไม่ match
     *                                                   matched = จำนวน slot ที่ match สำเร็จ
     */
    public static function match(array $adj): array
    {
        $nSlots = count($adj);
        $nPos = 0;

        // หา position สูงสุด
        foreach ($adj as $list) {
            foreach ($list as $p) {
                if ($p + 1 > $nPos) {
                    $nPos = $p + 1;
                }
            }
        }

        $matchSlot = array_fill(0, $nPos, -1); // position → slot
        $matchPos = array_fill(0, $nSlots, -1); // slot → position

        $matched = 0;
        for ($slot = 0; $slot < $nSlots; $slot++) {
            $visited = array_fill(0, $nPos, false);
            if (self::tryAugment($slot, $adj, $matchSlot, $matchPos, $visited)) {
                $matched++;
            } else {
                break; // ตรงกับ playground — หยุดทันทีเมื่อ slot ไหน match ไม่ได้
            }
        }

        return ['matchPos' => $matchPos, 'matched' => $matched];
    }

    /**
     * ตรวจว่า matching เป็น perfect match หรือไม่ (ทุก slot ได้คู่)
     *
     * @param  array{matchPos: array<int>, matched: int}  $bm
     * @param  int  $n  จำนวน slot ที่ต้องการ
     */
    public static function isPerfectMatch(array $bm, int $n): bool
    {
        return $bm['matched'] === $n;
    }

    /**
     * DFS augmenting path (Kuhn's algorithm)
     */
    private static function tryAugment(
        int $slot,
        array $adj,
        array &$matchSlot,
        array &$matchPos,
        array &$visited,
    ): bool {
        foreach ($adj[$slot] as $pos) {
            if ($visited[$pos]) {
                continue;
            }
            $visited[$pos] = true;

            if ($matchSlot[$pos] === -1
                || self::tryAugment($matchSlot[$pos], $adj, $matchSlot, $matchPos, $visited)) {
                $matchSlot[$pos] = $slot;
                $matchPos[$slot] = $pos;

                return true;
            }
        }

        return false;
    }
}
