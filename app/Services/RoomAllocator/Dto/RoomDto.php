<?php

namespace App\Services\RoomAllocator\Dto;

use App\Models\Room;
use App\Services\RoomAllocator\Weights;

/**
 * 🚪 RoomDto — snapshot ของห้องที่ใช้ใน algorithm
 *
 *    แยกออกจาก Eloquent Model เพื่อให้ algorithm ทำงานแบบ pure function
 *    (เร็ว, ทดสอบง่าย, ไม่มี side-effect)
 *
 *    ตรงกับ JS object ใน playground buildRooms():
 *      { num, type, side, pos, beds, floor, bed_type, reservations }
 *
 *    mapping สำคัญ:
 *      - playground room.beds (จำนวนเตียงรวม) → backend (builtin_extra_beds + 1)
 *      - reservations[] = รายการจองที่ทับซ้อน (pre-booked / booking อื่นที่ commit แล้ว)
 */
final class RoomDto
{
    /**
     * @param  string  $id  UUID ของ room (ใช้ commit กลับ DB)
     * @param  string  $num  เลขห้อง เช่น "508"
     * @param  string  $type  Suite | Deluxe | Superior
     * @param  string  $side  V1 | V2A | V2B
     * @param  int  $pos  ตำแหน่งในฝั่ง
     * @param  int  $beds  จำนวนเตียงรวม = builtin_extra_beds + 1
     * @param  int  $floor  ชั้น 5-9
     * @param  string  $bedType  twin | king_size
     * @param  array<array{checkIn: string, checkOut: string}>  $reservations
     */
    public function __construct(
        public readonly string $id,
        public readonly string $num,
        public readonly string $type,
        public readonly string $side,
        public readonly int $pos,
        public readonly int $beds,
        public readonly int $floor,
        public readonly string $bedType,
        public array $reservations = [],
    ) {}

    /**
     * สร้างจาก Eloquent Room model
     */
    public static function fromModel(Room $room): self
    {
        return new self(
            id: $room->id,
            num: $room->room_number,
            type: $room->roomType->name_en ?? 'Unknown',
            side: $room->side ?? 'V1',
            pos: $room->pos ?? 1,
            beds: ($room->builtin_extra_beds ?? 0) + 1,
            floor: $room->floor ?? 5,
            bedType: $room->bed_type ?? 'twin',
        );
    }

    /**
     * ตรวจว่าห้องนี้คือห้อง Deluxe 3-bed builtin (X09) หรือไม่
     */
    public function isX09(Weights $w): bool
    {
        return $this->type === 'Deluxe'
            && ($this->beds - 1) >= $w->x09MinBuiltinBeds;
    }

    /**
     * ตรวจ overlap ว่าห้องนี้ถูกจองในช่วงวันที่หรือไม่
     *
     * @param  string  $checkIn  Y-m-d
     * @param  string  $checkOut  Y-m-d
     */
    public function hasOverlap(string $checkIn, string $checkOut): bool
    {
        foreach ($this->reservations as $res) {
            // overlap: aIn < bOut && bIn < aOut
            if ($checkIn < $res['checkOut'] && $res['checkIn'] < $checkOut) {
                return true;
            }
        }

        return false;
    }
}
