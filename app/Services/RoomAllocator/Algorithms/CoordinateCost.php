<?php

namespace App\Services\RoomAllocator\Algorithms;

use App\Services\RoomAllocator\BipartiteMatcher;
use App\Services\RoomAllocator\CostCalculator;
use App\Services\RoomAllocator\Dto\AllocationResult;
use App\Services\RoomAllocator\Dto\RoomDto;
use App\Services\RoomAllocator\Topology;
use App\Services\RoomAllocator\Weights;

/**
 * 🅲 Algorithm C — Coordinate + Cost (brute-force global minimum)
 *
 *    เป้าหมาย: หา global minimum cost จาก combination ทั้งหมด
 *
 *    วิธี:
 *      1. เก็บ slotAvail = ห้องที่ type ตรง + ว่างตาม date ของ slot
 *      2. ตัดสินใจว่าจะ brute-force หรือ greedy (bruteComboFeasible):
 *         n=1,2 brute เสมอ · n=3 len≤80 · n=4 len≤40 · n=5 len≤25 · n=6 len≤20 · n>6 greedy
 *      3. brute-force path:
 *         - generate combinations(slotAvail, n)
 *         - แต่ละ combo: bipartite match slot↔type → คำนวณ costFunction รวม preAssignedPairs
 *         - เก็บชุดที่ cost ต่ำสุด → global minimum แน่นอน
 *      4. greedy fallback (multi-seed):
 *         - ลอง seed แต่ละตัวของ slot 0 (cap maxSeedTrials)
 *         - expand แบบ proximity (เหมือน D)
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §9 Algorithm C
 */
final class CoordinateCost implements Algorithm
{
    public function __construct(
        private readonly CostCalculator $cost,
        private readonly Weights $weights,
    ) {}

    public function run(array $brs, array $rooms, array $preAssignedPairs): AllocationResult
    {
        $n = count($brs);
        $assignments = array_fill(0, $n, null);

        if ($n === 0) {
            return new AllocationResult('Coordinate + Cost', $assignments, true, 0.0, 'Coordinate + Cost');
        }

        $neededTypes = array_values(array_unique(array_map(fn ($br) => $br->type, $brs)));

        // slotAvail = ห้องที่ type ตรง + ว่าง + มี slot ที่ match ได้
        $slotAvail = array_values(array_filter($rooms, function ($r) use ($neededTypes, $brs) {
            if (! in_array($r->type, $neededTypes, true)) {
                return false;
            }
            foreach ($brs as $br) {
                if ($r->type === $br->type
                    && $br->matchesBedPreference($r)
                    && ! $r->hasOverlap($br->checkIn, $br->checkOut)) {
                    return true;
                }
            }

            return false;
        }));

        if (count($slotAvail) < $n) {
            return $this->finish($brs, $assignments, false, $preAssignedPairs);
        }

        $bestChosen = null;
        $bestCost = INF;

        if ($n === 1) {
            foreach ($slotAvail as $r) {
                if ($r->type !== $brs[0]->type
                    || ! $brs[0]->matchesBedPreference($r)
                    || $r->hasOverlap($brs[0]->checkIn, $brs[0]->checkOut)) {
                    continue;
                }
                $testPairs = array_merge($preAssignedPairs, [CostCalculator::pair($r, $brs[0])]);
                $c = $this->cost->cost($testPairs);
                if ($c < $bestCost) {
                    $bestCost = $c;
                    $bestChosen = [$r];
                }
            }
        } elseif ($this->weights->bruteComboFeasible($n, count($slotAvail))) {
            // brute-force: try all combinations
            $combos = $this->combinations($slotAvail, $n);
            foreach ($combos as $combo) {
                $adj = [];
                foreach ($brs as $br) {
                    $list = [];
                    for ($j = 0; $j < count($combo); $j++) {
                        if ($combo[$j]->type === $br->type
                            && $br->matchesBedPreference($combo[$j])
                            && ! $combo[$j]->hasOverlap($br->checkIn, $br->checkOut)) {
                            $list[] = $j;
                        }
                    }
                    $adj[] = $list;
                }
                $bm = BipartiteMatcher::match($adj);
                if (! BipartiteMatcher::isPerfectMatch($bm, $n)) {
                    continue;
                }

                $comboPairs = [];
                foreach ($brs as $i => $br) {
                    $comboPairs[] = CostCalculator::pair($combo[$bm['matchPos'][$i]], $br);
                }
                $testPairs = array_merge($preAssignedPairs, $comboPairs);
                $c = $this->cost->cost($testPairs);
                if ($c < $bestCost) {
                    $bestCost = $c;
                    $bestChosen = array_map(fn ($p) => $p['room'], $comboPairs);
                }
            }
        } else {
            // greedy multi-seed fallback (proximity expand — เหมือน D)
            $bestChosen = $this->greedyMultiSeed($brs, $slotAvail, $preAssignedPairs, $bestCost);
        }

        if ($bestChosen) {
            foreach ($bestChosen as $i => $room) {
                $assignments[$i] = $room;
            }
        }

        return $this->finish($brs, $assignments, $bestChosen !== null, $preAssignedPairs);
    }

    /**
     * Multi-seed greedy fallback (proximity expand)
     *
     * @return RoomDto[]|null chosen rooms (length n) or null on failure
     */
    private function greedyMultiSeed(array $brs, array $slotAvail, array $preAssignedPairs, float &$bestCost): ?array
    {
        $seedType = $brs[0]->type;

        // seed pool: เรียงตาม (floor, pos)
        $seedPool = array_values(array_filter($slotAvail, fn ($r) => $r->type === $seedType
            && $brs[0]->matchesBedPreference($r)
            && ! $r->hasOverlap($brs[0]->checkIn, $brs[0]->checkOut)
        ));
        usort($seedPool, fn ($a, $b) => ($a->floor <=> $b->floor) ?: ($a->pos <=> $b->pos));

        $seedTrials = array_slice($seedPool, 0, min($this->weights->maxSeedTrials, count($seedPool)));

        $bestChosen = null;
        foreach ($seedTrials as $seedRoom) {
            $chosen = $this->expandFromSeed($seedRoom, $brs, $slotAvail);
            if ($chosen === null) {
                continue;
            }
            $comboPairs = [];
            foreach ($chosen as $j => $room) {
                $comboPairs[] = CostCalculator::pair($room, $brs[$j]);
            }
            $c = $this->cost->cost(array_merge($preAssignedPairs, $comboPairs));
            if ($c < $bestCost) {
                $bestCost = $c;
                $bestChosen = $chosen;
            }
        }

        return $bestChosen;
    }

    /**
     * Expand from seed using proximity (Δfloor + ΔglobalPos × 0.5 + side penalty)
     *
     * @return RoomDto[]|null
     */
    private function expandFromSeed(RoomDto $seed, array $brs, array $slotAvail): ?array
    {
        $n = count($brs);
        $usedNums = [$seed->num => true];
        $chosen = [$seed];
        $seedNormSide = Topology::normalizeSide($seed->side);

        for ($j = 1; $j < $n; $j++) {
            $cands = array_values(array_filter($slotAvail, fn ($r) => ! isset($usedNums[$r->num])
                && $r->type === $brs[$j]->type
                && $brs[$j]->matchesBedPreference($r)
                && ! $r->hasOverlap($brs[$j]->checkIn, $brs[$j]->checkOut)
            ));
            if (empty($cands)) {
                return null;
            }

            usort($cands, function ($a, $b) use ($seed, $seedNormSide) {
                $da = abs($a->floor - $seed->floor)
                    + abs(Topology::globalPos($a) - Topology::globalPos($seed)) * 0.5
                    + (Topology::normalizeSide($a->side) !== $seedNormSide ? 10 : 0);
                $db = abs($b->floor - $seed->floor)
                    + abs(Topology::globalPos($b) - Topology::globalPos($seed)) * 0.5
                    + (Topology::normalizeSide($b->side) !== $seedNormSide ? 10 : 0);

                return $da <=> $db;
            });

            $chosen[] = $cands[0];
            $usedNums[$cands[0]->num] = true;
        }

        return $chosen;
    }

    /**
     * Generate all combinations C(arr, k)
     *
     * @param  array<RoomDto>  $arr
     * @return array<int, array<RoomDto>>
     */
    private function combinations(array $arr, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        if (count($arr) < $k) {
            return [];
        }

        $first = $arr[0];
        $rest = array_slice($arr, 1);

        $withFirst = array_map(fn ($c) => array_merge([$first], $c), $this->combinations($rest, $k - 1));
        $withoutFirst = $this->combinations($rest, $k);

        return array_merge($withFirst, $withoutFirst);
    }

    private function finish(array $brs, array $assignments, bool $ok, array $preAssignedPairs): AllocationResult
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
            algo: 'Coordinate + Cost',
            assignments: $assignments,
            ok: $ok,
            cost: $cost,
            winner: $ok ? 'Coordinate + Cost' : null,
        );
    }
}
