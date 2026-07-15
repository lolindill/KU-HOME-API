<?php

namespace App\Services\RoomAllocator\Algorithms;

use App\Services\RoomAllocator\BipartiteMatcher;
use App\Services\RoomAllocator\CostCalculator;
use App\Services\RoomAllocator\Dto\AllocationResult;
use App\Services\RoomAllocator\Weights;

/**
 * 🅰️ Algorithm A — Block Contiguous
 *
 *    เป้าหมาย: หาห้องติดกันจริง (contiguous block) บนฝั่งเดียวกัน
 *
 *    วิธี:
 *      1. รวม candidates (ห้องที่ type ตรง + ไม่ถูกจอง)
 *      2. จัดกลุ่มตาม (floor, side) → เรียงตาม pos
 *      3. สไลด์ window ขนาด n ในแต่ละกลุ่ม:
 *         a. ตรวจ contiguous: pos[j] === pos[j-1]+1 ทุกตัว
 *         b. bipartite matching (slot ↔ window position) — ต้อง perfect
 *         c. คำนวณ costFunction → เก็บ block ที่ cost ต่ำสุด
 *      4. cap MAX_WINDOWS = 5000 (กัน combinatorial explosion)
 *
 *    จุดเด่น: ได้ cluster แน่นที่สุด
 *    จุดอ่อน: ถ้าไม่เจอ block ติดกัน → ok:false → ถูกตัดออกจาก Hybrid+
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §9 Algorithm A
 */
final class BlockContiguous implements Algorithm
{
    public function __construct(
        private readonly CostCalculator $cost,
        private readonly Weights $weights,
    ) {}

    public function run(array $brs, array $rooms, array $preAssignedPairs): AllocationResult
    {
        $steps = [];
        $n = count($brs);
        $assignments = array_fill(0, $n, null);

        if ($n === 0) {
            return new AllocationResult('Block Contiguous', $assignments, true, 0.0, 'Block Contiguous', $steps);
        }

        $neededTypes = array_values(array_unique(array_map(fn ($br) => $br->type, $brs)));
        $candidates = array_values(array_filter($rooms, function ($r) use ($neededTypes) {
            return in_array($r->type, $neededTypes, true);
        }));

        if (count($candidates) < $n) {
            return $this->finish($brs, $assignments, false, $preAssignedPairs, $steps);
        }

        // จัดกลุ่มตาม (floor, side) → เรียงตาม pos
        $byFloorSide = [];
        foreach ($candidates as $r) {
            $key = $r->floor.'_'.$r->side;
            $byFloorSide[$key][] = $r;
        }
        foreach ($byFloorSide as &$arr) {
            usort($arr, fn ($a, $b) => $a->pos <=> $b->pos);
        }
        unset($arr);

        $bestBlock = null;
        $windowsTried = 0;

        foreach ($byFloorSide as $key => $groupRooms) {
            if ($windowsTried >= $this->weights->maxWindows) {
                break;
            }

            $groupCount = count($groupRooms);
            for ($start = 0; $start <= $groupCount - $n; $start++) {
                if ($windowsTried >= $this->weights->maxWindows) {
                    break;
                }

                $window = array_slice($groupRooms, $start, $n);
                $windowsTried++;

                // ตรวจ contiguous: pos[j] === pos[j-1]+1
                $contiguous = true;
                for ($j = 1; $j < $n; $j++) {
                    if ($window[$j]->pos !== $window[$j - 1]->pos + 1) {
                        $contiguous = false;
                        break;
                    }
                }
                if (! $contiguous) {
                    continue;
                }

                // bipartite matching slot ↔ window position
                $adj = [];
                foreach ($brs as $br) {
                    $list = [];
                    for ($j = 0; $j < count($window); $j++) {
                        if ($window[$j]->type === $br->type
                            && $br->matchesBedPreference($window[$j])
                            && ! $window[$j]->hasOverlap($br->checkIn, $br->checkOut)) {
                            $list[] = $j;
                        }
                    }
                    $adj[] = $list;
                }
                $bm = BipartiteMatcher::match($adj);
                if (! BipartiteMatcher::isPerfectMatch($bm, $n)) {
                    continue;
                }

                // คำนวณ cost รวม preAssignedPairs
                $pairs = [];
                foreach ($brs as $i => $br) {
                    $pairs[] = CostCalculator::pair($window[$bm['matchPos'][$i]], $br);
                }
                $c = $this->cost->cost(array_merge($preAssignedPairs, $pairs));

                if ($bestBlock === null || $c < $bestBlock['cost']) {
                    $bestBlock = ['pairs' => $pairs, 'cost' => $c, 'window' => $window];
                }
            }
        }

        if ($bestBlock) {
            foreach ($bestBlock['pairs'] as $i => $p) {
                $assignments[$i] = $p['room'];
            }
        }
        // ไม่มี best-effort ปิด — A ต้องเป็น contiguous จริง ไม่งั้น ok:false

        return $this->finish($brs, $assignments, $bestBlock !== null, $preAssignedPairs, $steps);
    }

    private function finish(array $brs, array $assignments, bool $ok, array $preAssignedPairs, array $steps): AllocationResult
    {
        $pairs = [];
        for ($i = 0; $i < count($brs); $i++) {
            if ($assignments[$i]) {
                $pairs[] = CostCalculator::pair($assignments[$i], $brs[$i]);
            }
        }
        $allPairs = array_merge($preAssignedPairs, $pairs);
        $cost = $ok ? $this->cost->walkCost($allPairs) : INF;

        return new AllocationResult(
            algo: 'Block Contiguous',
            assignments: $assignments,
            ok: $ok,
            cost: $cost,
            winner: $ok ? 'Block Contiguous' : null,
            steps: $steps,
        );
    }
}
