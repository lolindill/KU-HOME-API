<?php

namespace App\Services\RoomAllocator;

use App\Services\RoomAllocator\Dto\BookingRequestDto;
use App\Services\RoomAllocator\Dto\RoomDto;

/**
 * 💰 CostCalculator — 2 ชั้น cost เหมือน playground v3
 *
 *    Port จาก docs/algo_test/room-algorithm-playground-eav3.html:
 *      - costFunction() → cost()         : guide การค้นหาใน algorithm (multi-dimension)
 *      - walkCost()     → walkCost()     : 🏆 Final Judge สำหรับ rank cross-algo (Σ pairwise)
 *      - costBreakdown() → breakdown()   : debug info แยกแต่ละ dimension
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §5-6, §12
 *
 *    ทำใช้สองตัว?
 *      - cost() มีหลาย dimension (floor/side/pos/bed) เหมาะกับ guide การค้นหา
 *      - walkCost() วัด "ระยะเดินจริง" ซึ่งตรงกับเป้าหมายที่แท้จริงของผู้เข้าพัก
 */
final class CostCalculator
{
    public function __construct(
        private readonly Weights $weights,
    ) {}

    /**
     * ตัวเก็บคู่ {room, br} เพื่อส่งผ่านระหว่างเมธอด
     *
     * @var array<int, array{room: RoomDto|null, br: BookingRequestDto}>
     */
    public static function pair(?RoomDto $room, BookingRequestDto $br): array
    {
        return ['room' => $room, 'br' => $br];
    }

    /**
     * 🎯 cost() — guide การค้นหาภายใน algorithm
     *
     *    cost = floorSpread + sideMismatch + posSpread + bedWaste + bedPrefPenalty
     *
     *    - floorSpread    (จำนวนชั้น-1) × w.floor
     *    - sideMismatch   (จำนวนฝั่ง-1) × w.side
     *    - posSpread      (globalMax-globalMin)² × w.pos   ← ยกกำลังสอง
     *    - bedWaste       Σ max(0, room.beds - 1 - br.extraBeds) × w.bed
     *    - bedPrefPenalty +100 ต่อห้องที่ผิด bed preference
     *
     * @param  array<int, array{room: RoomDto|null, br: BookingRequestDto}>  $pairs
     */
    public function cost(array $pairs): float
    {
        $w = $this->weights;
        if (count($pairs) === 0) {
            return INF;
        }

        $validRooms = array_values(array_filter(array_map(fn ($p) => $p['room'], $pairs)));
        if (count($validRooms) === 0) {
            return 0.0;
        }

        // floor spread
        $floors = array_unique(array_map(fn ($r) => $r->floor, $validRooms));
        $floorSpread = (count($floors) - 1) * $w->floor;

        // side mismatch (V2A/V2B รวมเป็น V2)
        $sides = array_unique(array_map(fn ($r) => Topology::normalizeSide($r->side), $validRooms));
        $sideMismatch = (count($sides) - 1) * $w->side;

        // pos spread (exponential — ลงโทษ scatter หนัก)
        $globalPositions = array_map(fn ($r) => Topology::globalPos($r), $validRooms);
        $posSpread = 0.0;
        if (count($globalPositions) > 1) {
            $distance = max($globalPositions) - min($globalPositions);
            $posSpread += ($distance ** 2) * $w->pos;
        }

        // bed waste (ห้องมีเตียงเกินความต้องการ)
        $bedWaste = 0.0;
        foreach ($pairs as $p) {
            if ($p['room'] !== null) {
                $waste = max(0, $p['room']->beds - 1 - $p['br']->extraBeds);
                $bedWaste += $waste * $w->bed;
            }
        }

        // bed preference penalty (ผิด preference = +100)
        $bedPrefPenalty = 0.0;
        foreach ($pairs as $p) {
            if ($p['room'] !== null && $p['br']->bedPreference !== null && $p['br']->bedPreference !== 'any') {
                if ($p['room']->bedType !== $p['br']->bedPreference) {
                    $bedPrefPenalty += 100;
                }
            }
        }

        return (float) ($floorSpread + $sideMismatch + $posSpread + $bedWaste + $bedPrefPenalty);
    }

    /**
     * 🏆 walkCost() — Final Judge สำหรับ rank cross-algorithm
     *
     *    = (Σ pairwise walking dist) × w.walk + (จำนวนชั้น-1) × w.floor
     *      └──── horizontal (Σ ทุกคู่) ────┘     └─ vertical (floor penalty) ─┘
     *
     *    ทำไมใช้ "Σ ทุกคู่" ไม่ใช่ "max-min"?
     *      - cost() posSpread ใช้ max-min = ดูแค่ขอบเขต
     *      - walkCost ใช้ Σ ทุกคู่ = จับ scatter ของกลุ่มทั้งหมด
     *        (ถ้ามีห้อง 1 ตัวหลุดออกไปไกล จะถูกลงโทษทุกคู่ที่เกี่ยวข้อง)
     *
     * @param  array<int, array{room: RoomDto|null, br: BookingRequestDto}>  $pairs
     */
    public function walkCost(array $pairs): float
    {
        if (count($pairs) === 0) {
            return INF;
        }

        $rooms = array_values(array_filter(array_map(fn ($p) => $p['room'], $pairs)));
        if (count($rooms) === 0) {
            return 0.0;
        }

        $w = $this->weights;

        // horizontal: sum pairwise walking distance
        $sumWalk = 0;
        $n = count($rooms);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $sumWalk += Topology::walkingDist($rooms[$i], $rooms[$j]);
            }
        }

        // vertical: floor penalty (แยก เพราะ walkingDist ไม่มี vertical)
        $floors = array_unique(array_map(fn ($r) => $r->floor, $rooms));
        $floorPenalty = (count($floors) - 1) * $w->floor;

        return (float) ($sumWalk * $w->walk + $floorPenalty);
    }

    /**
     * 📊 breakdown() — แยกค่าทุก dimension เพื่อ debug (total = walkCost เสมอ ใน v3)
     *
     * @param  array<int, array{room: RoomDto|null, br: BookingRequestDto}>  $pairs
     * @return array{floor: float, side: float, pos: float, bed: float, pref: float, walk: float, total: float}
     */
    public function breakdown(array $pairs): array
    {
        $w = $this->weights;
        $validRooms = array_values(array_filter(array_map(fn ($p) => $p['room'], $pairs)));
        if (count($validRooms) === 0) {
            return ['floor' => 0, 'side' => 0, 'pos' => 0, 'bed' => 0, 'pref' => 0, 'walk' => 0, 'total' => 0];
        }

        $floors = array_unique(array_map(fn ($r) => $r->floor, $validRooms));
        $floor = (float) ((count($floors) - 1) * $w->floor);

        $sides = array_unique(array_map(fn ($r) => Topology::normalizeSide($r->side), $validRooms));
        $side = (float) ((count($sides) - 1) * $w->side);

        $globalPositions = array_map(fn ($r) => Topology::globalPos($r), $validRooms);
        $pos = 0.0;
        if (count($globalPositions) > 1) {
            $distance = max($globalPositions) - min($globalPositions);
            $pos = (float) (($distance ** 2) * $w->pos);
        }

        $bed = 0.0;
        foreach ($pairs as $p) {
            if ($p['room'] !== null) {
                $bed += max(0, $p['room']->beds - 1 - $p['br']->extraBeds) * $w->bed;
            }
        }

        $pref = 0.0;
        foreach ($pairs as $p) {
            if ($p['room'] !== null && $p['br']->bedPreference !== null && $p['br']->bedPreference !== 'any') {
                if ($p['room']->bedType !== $p['br']->bedPreference) {
                    $pref += 100;
                }
            }
        }

        $walk = $this->walkCost($pairs);

        return [
            'floor' => $floor,
            'side' => $side,
            'pos' => $pos,
            'bed' => $bed,
            'pref' => $pref,
            'walk' => $walk,
            'total' => $walk, // v3: total = walk เพราะเป็น final judge
        ];
    }
}
