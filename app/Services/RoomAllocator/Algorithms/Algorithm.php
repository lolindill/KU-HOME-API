<?php

namespace App\Services\RoomAllocator\Algorithms;

use App\Services\RoomAllocator\Dto\AllocationResult;
use App\Services\RoomAllocator\Dto\BookingRequestDto;
use App\Services\RoomAllocator\Dto\RoomDto;

/**
 * 🧠 Algorithm interface — contract สำหรับ algorithm ทั้งหมด (A/C/D/FF)
 *
 *    ตรงกับ JS function ใน playground algoXxx(booking):
 *      รับ booking (array ของ BR) + room pool → คืน AllocationResult
 *
 *    อ้างอิง: docs/algo_test/room-algorithm-flow-explained.md §9
 */
interface Algorithm
{
    /**
     * @param  array<BookingRequestDto>  $brs  slots ที่ต้องการห้อง (remaining = ไม่รวมที่ X09 pin แล้ว)
     * @param  array<RoomDto>  $rooms  room pool ทั้งหมด (X09 ที่ pin แล้วถูกตัดออก)
     * @param  array<int, array{room: RoomDto|null, br: BookingRequestDto}>  $preAssignedPairs  คู่ที่ pin แล้ว (X09)
     */
    public function run(array $brs, array $rooms, array $preAssignedPairs): AllocationResult;
}
