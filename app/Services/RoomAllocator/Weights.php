<?php

namespace App\Services\RoomAllocator;

/**
 * ⚖️ Weights — value object โหลดจาก config/allocation.php
 *
 *    ใช้ทั้งใน CostCalculator (costFunction + walkCost) และ algorithms
 *    immutable — สร้างครั้งเดียวจาก config แล้วใช้ตลอด allocation run
 */
final class Weights
{
    public function __construct(
        public readonly int $floor,
        public readonly int $side,
        public readonly int $pos,
        public readonly int $bed,
        public readonly int $walk,
        public readonly bool $x09Priority,
        public readonly int $x09MinBuiltinBeds,
        public readonly int $maxWindows,
        public readonly int $maxSeedTrials,
        /** @var array<int, int> key=n (rooms needed), value=max candidates */
        public readonly array $bruteComboCaps,
    ) {}

    /**
     * Factory: โหลดจาก config/allocation.php
     */
    public static function fromConfig(): self
    {
        return new self(
            floor: (int) config('allocation.weights.floor', 50),
            side: (int) config('allocation.weights.side', 8),
            pos: (int) config('allocation.weights.pos', 3),
            bed: (int) config('allocation.weights.bed', 5),
            walk: (int) config('allocation.weights.walk', 1),
            x09Priority: (bool) config('allocation.x09_priority', true),
            x09MinBuiltinBeds: (int) config('allocation.x09_min_builtin_beds', 2),
            maxWindows: (int) config('allocation.max_windows', 5000),
            maxSeedTrials: (int) config('allocation.max_seed_trials', 15),
            bruteComboCaps: (array) config('allocation.brute_combo_caps', []),
        );
    }

    /**
     * ตรวจสอบว่าควร brute-force combination หรือตก greedy fallback
     *
     * @param  int  $n  จำนวนห้องที่ต้องการ
     * @param  int  $len  จำนวน candidate ที่ว่าง
     */
    public function bruteComboFeasible(int $n, int $len): bool
    {
        if ($n <= 0 || $len < $n) {
            return false;
        }
        if ($n === 1) {
            return true;
        } // len candidates
        if ($n === 2) {
            return true;
        } // C(100,2)=4950

        $cap = $this->bruteComboCaps[$n] ?? 0;

        return $len <= $cap;
    }
}
