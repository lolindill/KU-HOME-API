<?php

namespace App\Services\RoomAllocator\Dto;

/**
 * 🏆 AllocationResult — ผลลัพธ์จาก algorithm
 *
 *    ตรงกับ JS object ใน playground algoXxx() return:
 *      { algo, assignments, seed, ok, cost, winner, steps }
 *
 *    steps ไม่ใช้ใน production (เก็บไว้ทดสอบ/debug เท่านั้น) เพราะ backend ไม่มี animation
 */
final class AllocationResult
{
    /**
     * @param  string  $algo  ชื่อ algorithm ที่ชนะ
     * @param  array<string|null>  $assignments  index = BR index → room_id | null
     * @param  bool  $ok  assign ครบทุก slot หรือไม่
     * @param  float  $cost  walkCost (Final Judge), Infinity ถ้า !ok
     * @param  string|null  $winner  algorithm ที่ชนะ tie-breaker
     * @param  array<int, array{type: string, thought: string}>  $steps  log สำหรับ debug
     */
    public function __construct(
        public string $algo,
        public array $assignments,
        public bool $ok,
        public float $cost,
        public ?string $winner = null,
        public array $steps = [],
    ) {}

    /**
     * สร้าง result ที่ล้มเหลว (ไม่มีห้องเพียงพอ)
     */
    public static function failed(int $slotCount): self
    {
        return new self(
            algo: 'Failed',
            assignments: array_fill(0, $slotCount, null),
            ok: false,
            cost: INF,
        );
    }
}
