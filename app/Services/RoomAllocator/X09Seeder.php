<?php

namespace App\Services\RoomAllocator;

use App\Services\RoomAllocator\Dto\BookingRequestDto;
use App\Services\RoomAllocator\Dto\RoomDto;

/**
 * 🛏️ X09 Priority Seed — กฎพิเศษเรื่อง extra bed
 *
 *    ห้องที่ builtin_extra_beds >= x09_min_builtin_beds (เช่น X09 = Deluxe 3-bed builtin)
 *    เป็นห้องเดียวที่รองรับ extra bed โดยไม่ต้องเพิ่มเตียงเสริม
 *
 *    กฎ: ถ้า booking มี slot ที่ type='Deluxe' และ extraBeds > 0 (ต้องการ extra bed)
 *         และมีห้อง X09 ว่าง → เลือก X09 ทันทีก่อนรัน algorithm
 *
 *    ผล: X09 ถูก pin ลงใน assignments[idx] → algorithm ที่เหลือทำงานกับ slot อื่น ๆ เท่านั้น
 *         และ X09 จะถูกนำมารวมในการคำนวณ cost (preAssignedPairs)
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §8
 */
final class X09Seeder
{
    public function __construct(
        private readonly Weights $weights,
    ) {}

    /**
     * หา X09 ที่ควร pin ถ้า booking มี slot ต้องการ extra bed
     *
     * @param  array<BookingRequestDto>  $brs
     * @param  array<RoomDto>  $rooms  room pool ที่จะ assign (X09 ต้องอยู่ในนี้)
     * @return array{room: RoomDto, idx: int}|null idx = index ของ BR ที่จับกับ X09
     */
    public function find(array $brs, array $rooms): ?array
    {
        if (! $this->weights->x09Priority) {
            return null;
        }

        // หา BR แรกที่ต้องการ extra bed
        $idx = null;
        foreach ($brs as $i => $br) {
            if ($br->type === 'Deluxe' && $br->extraBeds > 0) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return null;
        }

        // หา X09 ที่ว่างตามวันที่ของ BR นั้น — เรียงตามชั้นต่ำสุดก่อน (ตรงกับ playground)
        $candidates = [];
        foreach ($rooms as $room) {
            if (! $room->isX09($this->weights)) {
                continue;
            }
            if ($room->hasOverlap($brs[$idx]->checkIn, $brs[$idx]->checkOut)) {
                continue;
            }
            $candidates[] = $room;
        }
        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $a->floor <=> $b->floor);

        return ['room' => $candidates[0], 'idx' => $idx];
    }
}
