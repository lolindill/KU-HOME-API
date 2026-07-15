<?php

namespace App\Services\RoomAllocator\Algorithms;

use App\Services\RoomAllocator\CostCalculator;
use App\Services\RoomAllocator\Dto\AllocationResult;
use App\Services\RoomAllocator\Dto\RoomDto;
use App\Services\RoomAllocator\Topology;
use App\Services\RoomAllocator\Weights;

/**
 * 🅳 Algorithm D — Greedy Seed + Expand
 *
 *    เป้าหมาย: เร็ว + กระจุกตัว โดยเลือก seed แล้วขยายไปหาห้องใกล้สุด
 *
 *    วิธี:
 *      1. seedCandidates = ห้องที่ type ตรง slot 0, เรียงตาม (floor, pos)
 *      2. Multi-seed (cap maxSeedTrials):
 *         สำหรับแต่ละ seedRoom:
 *           a. chosen = [seedRoom]
 *           b. สำหรับ slot j = 1..n-1:
 *              - candidates = ห้องที่ type ตรง + ว่าง + ยังไม่ถูกใช้
 *              - เรียงตาม proximity: |Δfloor| + |ΔglobalPos| × 0.5 + (ฝั่งต่าง ? 10 : 0)
 *              - หยิบ candidate[0] (ใกล้สุด)
 *           c. คำนวณ costFunction → เก็บชุดที่ cost ต่ำสุด
 *
 *    จุดเด่น: เร็วมาก, ทน n ขนาดใหญ่
 *    จุดอ่อน: local optimum — อาจไม่ใช่ global min
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §9 Algorithm D
 */
final class GreedySeedExpand implements Algorithm
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
            return new AllocationResult('Greedy Seed + Expand', $assignments, true, 0.0, 'Greedy Seed + Expand');
        }

        $neededTypes = array_values(array_unique(array_map(fn ($br) => $br->type, $brs)));
        $allAvailable = array_values(array_filter($rooms, fn ($r) => in_array($r->type, $neededTypes, true)
        ));

        if (count($allAvailable) < $n) {
            return $this->finish($brs, $assignments, false, $preAssignedPairs);
        }

        // seed candidates: slot 0 type, เรียงตาม (floor, pos)
        $seedCandidates = array_values(array_filter($allAvailable, fn ($r) => $r->type === $brs[0]->type
            && $brs[0]->matchesBedPreference($r)
            && ! $r->hasOverlap($brs[0]->checkIn, $brs[0]->checkOut)
        ));
        usort($seedCandidates, fn ($a, $b) => ($a->floor <=> $b->floor) ?: ($a->pos <=> $b->pos));

        if (empty($seedCandidates)) {
            return $this->finish($brs, $assignments, false, $preAssignedPairs);
        }

        $seedTrials = array_slice($seedCandidates, 0, min($this->weights->maxSeedTrials, count($seedCandidates)));

        $bestChosen = null;
        $bestCost = INF;

        foreach ($seedTrials as $seedRoom) {
            $chosen = $this->expandFromSeed($seedRoom, $brs, $allAvailable);
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

        if ($bestChosen) {
            foreach ($bestChosen as $i => $room) {
                $assignments[$i] = $room;
            }
        }

        return $this->finish($brs, $assignments, $bestChosen !== null, $preAssignedPairs);
    }

    /**
     * Expand from seed using proximity
     *
     * @return RoomDto[]|null
     */
    private function expandFromSeed(RoomDto $seed, array $brs, array $allAvailable): ?array
    {
        $n = count($brs);
        $usedNums = [$seed->num => true];
        $chosen = [$seed];
        $seedNormSide = Topology::normalizeSide($seed->side);

        for ($j = 1; $j < $n; $j++) {
            $cands = array_values(array_filter($allAvailable, fn ($r) => ! isset($usedNums[$r->num])
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
            algo: 'Greedy Seed + Expand',
            assignments: $assignments,
            ok: $ok,
            cost: $cost,
            winner: $ok ? 'Greedy Seed + Expand' : null,
        );
    }
}
