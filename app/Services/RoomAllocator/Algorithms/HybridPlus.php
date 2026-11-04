<?php

namespace App\Services\RoomAllocator\Algorithms;

use App\Services\RoomAllocator\CostCalculator;
use App\Services\RoomAllocator\Dto\AllocationResult;
use App\Services\RoomAllocator\Weights;

/**
 * ⭐ Algorithm EA (J) — Hybrid+ = C + D + A + FF tie-breaker
 *
 *    นี่คือตัวที่ใช้ผลิตจริง (production)
 *
 *    Flow:
 *      1. รัน C, D, A, FF ขนาน (A เข้าร่วมเฉพาะเมื่อหา contiguous block ได้ = ok)
 *      2. กรองเฉพาะ ok → A ที่ไม่ contiguous จะถูกตัดออก
 *      3. เลือก cost ต่ำสุด (cost = walkCost Final Judge)
 *         tie → candidate แรก (C) ชนะ
 *      4. ถ้าทั้งหมดล้มเหลว → default C (จะ ok=false)
 *
 *    เหตุผลที่ใช้หลาย algorithm:
 *      - C เก่งเรื่อง global min (brute-force)  → ชนะเมื่อ n เล็ก
 *      - D เก่งเรื่องรอบด้าน, ทน n ใหญ่           → ชนะเมื่อ n ใหญ่ตก greedy
 *      - A เก่งเรื่อง contiguous สุด              → ชนะเมื่อมี block ว่างพอดี
 *      - FF เก่งเรื่องรักษาชั้นเดียว              → ชนะเมื่อ floor weight ต่ำ/มีชั้นจุครบ
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §10 Algorithm EA
 */
final class HybridPlus implements Algorithm
{
    public function __construct(
        private readonly CostCalculator $cost,
        private readonly Weights $weights,
    ) {}

    public function run(array $brs, array $rooms, array $preAssignedPairs): AllocationResult
    {
        // สร้าง algorithm instances (pure — ไม่มี shared state)
        $coordResult = (new CoordinateCost($this->cost, $this->weights))->run($brs, $rooms, $preAssignedPairs);
        $greedyResult = (new GreedySeedExpand($this->cost, $this->weights))->run($brs, $rooms, $preAssignedPairs);
        $blockResult = (new BlockContiguous($this->cost, $this->weights))->run($brs, $rooms, $preAssignedPairs);
        $ffResult = (new FloorFirst($this->cost, $this->weights))->run($brs, $rooms, $preAssignedPairs);

        // กรองเฉพาะ ok — A ที่หา block ไม่ได้จะถูกตัดออก
        $candidates = [
            $coordResult,
            $greedyResult,
            $blockResult,
            $ffResult,
        ];
        $okCandidates = array_values(array_filter($candidates, fn ($r) => $r->ok));

        // เลือก cost ต่ำสุด (tie → candidate แรก = C ชนะ เพราะเรียงลำดับไว้)
        $best = null;
        foreach ($okCandidates as $r) {
            if ($best === null || $r->cost < $best->cost) {
                $best = $r;
            }
        }
        if ($best === null) {
            // ทั้งหมดล้มเหลว → default coordinate (จะ ok=false)
            $best = $coordResult;
        }

        return new AllocationResult(
            algo: 'Hybrid+ (C+D+A+FF)',
            assignments: $best->assignments,
            ok: $best->ok,
            cost: $best->cost,
            winner: $best->winner,
        );
    }
}
