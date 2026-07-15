<?php

namespace App\Services\RoomAllocator\Algorithms;

use App\Services\RoomAllocator\BipartiteMatcher;
use App\Services\RoomAllocator\CostCalculator;
use App\Services\RoomAllocator\Dto\AllocationResult;
use App\Services\RoomAllocator\Dto\BookingRequestDto;
use App\Services\RoomAllocator\Dto\RoomDto;
use App\Services\RoomAllocator\Topology;
use App\Services\RoomAllocator\Weights;

/**
 * 🆕 Algorithm FF — Floor-First Greedy
 *
 *    เป้าหมาย: เก็บห้องไว้ในชั้นเดียวด้วย "โครงสร้าง" ไม่ใช่พึ่ง floor weight
 *
 *    Phase A — คำนวณ available pool (เหมือน D)
 *    Phase B — หาชั้นที่ "จุได้ครบทุก slot" (bipartite perfect match ในแต่ละชั้น)
 *    Phase C — Edge multi-seed + in-floor expand:
 *              seed เริ่มที่ปลายซ้าย/ขวาของฝั่ง slot 0 ในชั้นนั้น
 *              → expand เฉพาะในชั้นเดียวกัน (ห้ามข้ามชั้น)
 *    Phase D — Overflow fallback (ไม่มีชั้นไหนจุครบ):
 *              เติมชั้นที่จุได้เยอะสุด แล้วเอาห้องที่เหลือ → ชั้นข้างเคียงใกล้สุด (±1, ±2...)
 *
 *    จุดเด่น: รักษาชั้นเดียวกันได้แม้ floor weight ต่ำ
 *    จุดอ่อน: ถ้าไม่มีชั้นจุครบ → กระจายข้ามชั้น (Phase D)
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §9 Algorithm FF
 */
final class FloorFirst implements Algorithm
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
            return new AllocationResult('Floor-First Greedy', $assignments, true, 0.0, 'Floor-First Greedy');
        }

        $neededTypes = array_values(array_unique(array_map(fn ($br) => $br->type, $brs)));
        $allAvailable = array_values(array_filter($rooms, fn ($r) => in_array($r->type, $neededTypes, true)
        ));

        if (count($allAvailable) < $n) {
            return $this->finish($brs, $assignments, false, $preAssignedPairs);
        }

        // ---- Phase B: หาชั้นที่จุได้ครบทุก slot (bipartite perfect match) ----
        $floorsFit = [];
        foreach (Topology::FLOORS as $floor) {
            $floorRooms = array_values(array_filter($allAvailable, fn ($r) => $r->floor === $floor));
            $adj = [];
            foreach ($brs as $br) {
                $list = [];
                for ($j = 0; $j < count($floorRooms); $j++) {
                    if ($floorRooms[$j]->type === $br->type
                        && $br->matchesBedPreference($floorRooms[$j])
                        && ! $floorRooms[$j]->hasOverlap($br->checkIn, $br->checkOut)) {
                        $list[] = $j;
                    }
                }
                $adj[] = $list;
            }
            $bm = BipartiteMatcher::match($adj);
            if (BipartiteMatcher::isPerfectMatch($bm, $n)) {
                $floorsFit[] = $floor;
            }
        }

        $bestChosen = null;
        $bestCost = INF;

        // ---- Phase C: edge seed + in-floor expand ในชั้นที่จุครบ ----
        if (! empty($floorsFit)) {
            foreach ($floorsFit as $floor) {
                $edgeSeeds = $this->collectEdgeSeeds($brs[0], $allAvailable, $floor);
                if (empty($edgeSeeds)) {
                    continue;
                }

                foreach ($edgeSeeds as $seedRoom) {
                    $chosen = $this->expandInFloor($seedRoom, $floor, $brs, $allAvailable);
                    if ($chosen === null) {
                        continue;
                    }
                    $pairs = [];
                    foreach ($chosen as $j => $room) {
                        $pairs[] = CostCalculator::pair($room, $brs[$j]);
                    }
                    $c = $this->cost->cost(array_merge($preAssignedPairs, $pairs));
                    if ($c < $bestCost) {
                        $bestCost = $c;
                        $bestChosen = $chosen;
                    }
                }
            }
        }

        // ---- Phase D: Overflow fallback (ไม่มีชั้นจุครบ) ----
        if ($bestChosen === null) {
            $bestChosen = $this->overflowFallback($brs, $allAvailable, $preAssignedPairs, $bestCost);
        }

        if ($bestChosen) {
            foreach ($bestChosen as $i => $room) {
                $assignments[$i] = $room;
            }
        }

        return $this->finish($brs, $assignments, $bestChosen !== null, $preAssignedPairs);
    }

    /**
     * รวม edge seed = pos ต่ำสุด + สูงสุดของแต่ละฝั่งของ slot 0 type ในชั้นนั้น
     *
     * @return RoomDto[]
     */
    private function collectEdgeSeeds(BookingRequestDto $slot0, array $allAvailable, int $floor): array
    {
        $slot0Rooms = array_values(array_filter($allAvailable, fn ($r) => $r->floor === $floor
            && $r->type === $slot0->type
            && $slot0->matchesBedPreference($r)
            && ! $r->hasOverlap($slot0->checkIn, $slot0->checkOut)
        ));
        if (empty($slot0Rooms)) {
            return [];
        }

        // จัดกลุ่มตามฝั่ง → เลือก edge (pos ต่ำสุด + สูงสุด)
        $bySide = [];
        foreach ($slot0Rooms as $r) {
            $bySide[$r->side][] = $r;
        }
        $edgeSeeds = [];
        foreach ($bySide as &$arr) {
            usort($arr, fn ($a, $b) => $a->pos <=> $b->pos);
            $edgeSeeds[] = $arr[0]; // ปลายซ้าย
            if (count($arr) > 1) {
                $edgeSeeds[] = $arr[count($arr) - 1]; // ปลายขวา
            }
        }

        return $edgeSeeds;
    }

    /**
     * Expand เฉพาะในชั้นเดียวกับ seed (ห้ามข้ามชั้น)
     *
     * @return RoomDto[]|null
     */
    private function expandInFloor(RoomDto $seed, int $floor, array $brs, array $allAvailable): ?array
    {
        $n = count($brs);
        $usedNums = [$seed->num => true];
        $chosen = [$seed];
        $seedGlobal = Topology::globalPos($seed);
        $seedNormSide = Topology::normalizeSide($seed->side);

        for ($j = 1; $j < $n; $j++) {
            $cands = array_values(array_filter($allAvailable, fn ($r) => ! isset($usedNums[$r->num])
                && $r->floor === $floor
                && $r->type === $brs[$j]->type
                && $brs[$j]->matchesBedPreference($r)
                && ! $r->hasOverlap($brs[$j]->checkIn, $brs[$j]->checkOut)
            ));
            if (empty($cands)) {
                return null;
            }

            usort($cands, function ($a, $b) use ($seedGlobal, $seedNormSide) {
                $da = abs(Topology::globalPos($a) - $seedGlobal) * 0.5
                    + (Topology::normalizeSide($a->side) !== $seedNormSide ? 10 : 0);
                $db = abs(Topology::globalPos($b) - $seedGlobal) * 0.5
                    + (Topology::normalizeSide($b->side) !== $seedNormSide ? 10 : 0);

                return $da <=> $db;
            });

            $chosen[] = $cands[0];
            $usedNums[$cands[0]->num] = true;
        }

        return $chosen;
    }

    /**
     * Phase D overflow — เติมชั้นที่จุได้เยอะสุด แล้วเอาห้องที่เหลือไปชั้นข้างเคียงใกล้สุด
     *
     * @return RoomDto[]|null
     */
    private function overflowFallback(array $brs, array $allAvailable, array $preAssignedPairs, float &$bestCost): ?array
    {
        $n = count($brs);

        // หาชั้นที่จุได้เยอะสุด (max matching per floor)
        $bestFloor = null;
        $bestFloorFit = -1;
        $bestFloorMatch = null;

        foreach (Topology::FLOORS as $floor) {
            $floorRooms = array_values(array_filter($allAvailable, fn ($r) => $r->floor === $floor));
            $adj = [];
            foreach ($brs as $br) {
                $list = [];
                for ($j = 0; $j < count($floorRooms); $j++) {
                    if ($floorRooms[$j]->type === $br->type
                        && $br->matchesBedPreference($floorRooms[$j])
                        && ! $floorRooms[$j]->hasOverlap($br->checkIn, $br->checkOut)) {
                        $list[] = $j;
                    }
                }
                $adj[] = $list;
            }
            $bm = BipartiteMatcher::match($adj);
            if ($bm['matched'] > $bestFloorFit) {
                $bestFloorFit = $bm['matched'];
                $bestFloor = $floor;
                $bestFloorMatch = $bm['matchPos'];
            }
        }

        if ($bestFloorFit <= 0) {
            return null;
        }

        // เติม slot ที่ match ใน bestFloor แล้วหาที่เหลือในชั้นข้างเคียง
        $floorRooms = array_values(array_filter($allAvailable, fn ($r) => $r->floor === $bestFloor));
        $chosen = array_fill(0, $n, null);
        $usedNums = [];

        for ($s = 0; $s < $n; $s++) {
            if ($bestFloorMatch[$s] !== -1 && isset($floorRooms[$bestFloorMatch[$s]])) {
                $room = $floorRooms[$bestFloorMatch[$s]];
                $chosen[$s] = $room;
                $usedNums[$room->num] = true;
            }
        }

        // เติม slot ที่ยังไม่ match → หาในชั้นข้างเคียงเรียงตาม |Δfloor|
        $otherFloors = array_values(array_filter(Topology::FLOORS, fn ($f) => $f !== $bestFloor));
        usort($otherFloors, fn ($a, $b) => abs($a - $bestFloor) <=> abs($b - $bestFloor));

        for ($s = 0; $s < $n; $s++) {
            if ($chosen[$s] !== null) {
                continue;
            }
            $placed = false;
            foreach ($otherFloors as $f) {
                $cands = array_values(array_filter($allAvailable, fn ($r) => ! isset($usedNums[$r->num])
                    && $r->floor === $f
                    && $r->type === $brs[$s]->type
                    && $brs[$s]->matchesBedPreference($r)
                    && ! $r->hasOverlap($brs[$s]->checkIn, $brs[$s]->checkOut)
                ));
                if (! empty($cands)) {
                    usort($cands, fn ($a, $b) => $a->pos <=> $b->pos); // เอาปลายซ้าย (edge)
                    $chosen[$s] = $cands[0];
                    $usedNums[$cands[0]->num] = true;
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                return null;
            } // ไม่สามารถจุ slot นี้ได้เลย
        }

        $pairs = [];
        foreach ($chosen as $j => $room) {
            $pairs[] = CostCalculator::pair($room, $brs[$j]);
        }
        $bestCost = $this->cost->cost(array_merge($preAssignedPairs, $pairs));

        return $chosen;
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
            algo: 'Floor-First Greedy',
            assignments: $assignments,
            ok: $ok,
            cost: $cost,
            winner: $ok ? 'Floor-First Greedy' : null,
        );
    }
}
