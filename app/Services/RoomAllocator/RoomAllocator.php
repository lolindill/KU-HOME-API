<?php

namespace App\Services\RoomAllocator;

use App\Models\BookingRoom;
use App\Models\Room;
use App\Services\RoomAllocator\Algorithms\HybridPlus;
use App\Services\RoomAllocator\Dto\AllocationResult;
use App\Services\RoomAllocator\Dto\BookingRequestDto;
use App\Services\RoomAllocator\Dto\RoomDto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🏨 RoomAllocator — entry point สำหรับจัดห้องให้ booking แบบ cluster
 *
 *    ทำหน้าที่: รับ BookingRoom collection → จัดห้อง cluster ที่ดีที่สุด → คืน AllocationResult
 *
 *    Flow:
 *      1. แปลง BR collection → BookingRequestDto[]
 *      2. โหลด room pool (type ที่เกี่ยวข้อง) + reservations ที่ overlap → RoomDto[]
 *      3. รัน X09 priority seed (pin X09 ถ้ามี)
 *      4. รัน HybridPlus (C+D+A+FF) → เลือก cost ต่ำสุด
 *      5. คืน AllocationResult (caller ต้อง commit room_id เองใน transaction)
 *
 *    ⚠️ Concurrency: caller ต้องห่อ DB::beginTransaction() เอง
 *       Service จะ lockForUpdate() room pool เพื่อกัน race (skip ใน SQLite)
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §11 (Flow เต็ม)
 */
final class RoomAllocator
{
    public function __construct(
        private readonly Weights $weights,
    ) {}

    /**
     * จัดห้อง cluster ให้ BookingRoom collection ของ booking เดียว
     *
     * @param  Collection<int, BookingRoom>  $bookingRooms  BR ที่ยังไม่มี room_id
     * @return AllocationResult {assignments: [brId => roomId], ok, cost, winner}
     */
    public function allocate(Collection $bookingRooms): AllocationResult
    {
        if ($bookingRooms->isEmpty()) {
            return AllocationResult::failed(0);
        }

        // eager-load ที่จำเป็น (defensive — caller ควร load มาแล้ว)
        $bookingRooms->loadMissing(['addon', 'roomType']);

        // ---- 1. แปลง BR → BookingRequestDto ----
        $brs = $bookingRooms->map(fn ($br) => BookingRequestDto::fromModel($br))->values()->all();

        // ---- 2. โหลด room pool + reservations ที่ overlap ----
        $rooms = $this->loadRoomPool($brs);

        // ---- 3. X09 priority seed ----
        $x09Seeder = new X09Seeder($this->weights);
        $x09Pin = $x09Seeder->find($brs, $rooms);

        $assignments = array_fill(0, count($brs), null);
        $preAssignedPairs = []; // [{room, br}, ...]
        $pinnedNums = [];

        if ($x09Pin !== null) {
            $idx = $x09Pin['idx'];
            $assignments[$idx] = $x09Pin['room'];
            $preAssignedPairs[] = CostCalculator::pair($x09Pin['room'], $brs[$idx]);
            $pinnedNums[$x09Pin['room']->num] = true;
        }

        // ---- 4. แยก slot ที่ยังไม่ถูก X09 pin (remaining) ----
        $remainingBrs = [];
        foreach ($brs as $i => $br) {
            if ($assignments[$i] === null) {
                $remainingBrs[] = $br;
            }
        }

        // room pool ที่เหลือ = เอา X09 ที่ pin แล้วออก
        $roomsForAlgo = array_values(array_filter($rooms, fn ($r) => ! isset($pinnedNums[$r->num])));

        // ---- 5. รัน Hybrid+ กับ remaining slots ----
        if (! empty($remainingBrs)) {
            $hybrid = new HybridPlus(new CostCalculator($this->weights), $this->weights);
            $hybridResult = $hybrid->run($remainingBrs, $roomsForAlgo, $preAssignedPairs);
            $winner = $hybridResult->winner;

            // map ผลกลับไปยัง assignments หลัก (index ของ hybridResult = index ใน remaining)
            $rIdx = 0;
            foreach ($brs as $i => $br) {
                if ($assignments[$i] !== null) {
                    continue;
                } // ถูก pin ไปแล้ว
                if (isset($hybridResult->assignments[$rIdx])) {
                    $assignments[$i] = $hybridResult->assignments[$rIdx];
                }
                $rIdx++;
            }
        } else {
            // ทุก slot ถูก X09 pin แล้ว — ไม่ต้องรัน algo
            $winner = 'X09 Priority';
        }

        // ตรวจ ok รวม X09 และ algo
        $ok = ! in_array(null, $assignments, true);

        // แปลง assignments เป็น [brId => roomId] สำหรับ caller commit
        $brIdAssignments = [];
        foreach ($assignments as $i => $room) {
            $brIdAssignments[$brs[$i]->brId] = $room?->id;
        }

        // คำนวณ cost รวมทุกคู่
        $finalPairs = [];
        foreach ($brs as $i => $br) {
            if ($assignments[$i]) {
                $finalPairs[] = CostCalculator::pair($assignments[$i], $br);
            }
        }
        $finalCost = $ok ? (new CostCalculator($this->weights))->walkCost($finalPairs) : INF;

        return new AllocationResult(
            algo: 'Hybrid+ (C+D+A+FF)',
            assignments: $brIdAssignments, // [brId => roomId]
            ok: $ok,
            cost: $finalCost,
            winner: $winner,
        );
    }

    /**
     * โหลด room pool ที่ type ตรงกับที่ BRs ต้องการ + reservations ที่ overlap ทุกช่วงวันที่
     *
     * @param  BookingRequestDto[]  $brs
     * @return RoomDto[]
     */
    private function loadRoomPool(array $brs): array
    {
        $neededTypes = array_values(array_unique(array_map(fn ($br) => $br->type, $brs)));

        $query = Room::with('roomType')
            ->whereHas('roomType', fn ($q) => $q->whereIn('name_en', $neededTypes))
            ->whereNotIn('status', ['maintenance', 'reserved_closed']);

        // lockForUpdate เพื่อกัน race (skip ใน SQLite ที่ไม่ support)
        if (config('database.default') !== 'sqlite' && DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $rooms = $query->get();

        // เก็บช่วงวันที่ที่ต้องเช็ค overlap (union ของทุก BR)
        $checkIns = array_map(fn ($br) => $br->checkIn, $brs);
        $checkOuts = array_map(fn ($br) => $br->checkOut, $brs);
        $minCheckIn = min($checkIns);
        $maxCheckOut = max($checkOuts);

        // แปลงเป็น RoomDto + โหลด reservations ที่ overlap
        $dtos = [];
        foreach ($rooms as $room) {
            $dto = RoomDto::fromModel($room);

            // โหลด reservation ที่ overlap ช่วง [min, max] (กินวงกว้างเพื่อความถูกต้อง)
            $overlaps = $room->bookingRooms()
                ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
                ->where('check_in', '<', $maxCheckOut)
                ->where('check_out', '>', $minCheckIn)
                ->get(['check_in', 'check_out']);

            foreach ($overlaps as $br) {
                $dto->reservations[] = [
                    'checkIn' => $br->check_in->format('Y-m-d'),
                    'checkOut' => $br->check_out->format('Y-m-d'),
                ];
            }
            $dtos[] = $dto;
        }

        return $dtos;
    }
}
