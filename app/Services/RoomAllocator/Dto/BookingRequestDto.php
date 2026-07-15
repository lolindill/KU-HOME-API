<?php

namespace App\Services\RoomAllocator\Dto;

use App\Models\BookingRoom;

/**
 * 📦 BookingRequestDto — slot เดียวใน booking ที่ต้องการห้อง
 *
 *    ตรงกับ JS object ใน playground booking.rooms[i]:
 *      { type, beds, bed_preference, checkIn, checkOut }
 *
 *    mapping สำคัญ:
 *      - playground br.beds (extra requested) → backend $br->addon?->extra_bed ?? 0
 *      - bed_preference → ฟิลด์ใหม่ใน booking_rooms (Phase 1 migration)
 *      - checkIn/checkOut → BR-level dates (refactor 25/06/26)
 */
final class BookingRequestDto
{
    /**
     * @param  string  $brId  UUID ของ BookingRoom (ใช้ commit กลับ DB)
     * @param  string  $type  Suite | Deluxe | Superior
     * @param  string  $checkIn  Y-m-d
     * @param  string  $checkOut  Y-m-d
     * @param  int  $extraBeds  จำนวน extra bed ที่ขอเพิ่ม (จาก addon)
     * @param  string|null  $bedPreference  'twin' | null (=any)
     */
    public function __construct(
        public readonly string $brId,
        public readonly string $type,
        public readonly string $checkIn,
        public readonly string $checkOut,
        public readonly int $extraBeds,
        public readonly ?string $bedPreference,
    ) {}

    /**
     * ตรวจว่าห้องนี้ตรงกับ bed_preference ของ BR หรือไม่ (hard constraint)
     *    - null/any → รับได้ทุกห้อง
     *    - 'twin'   → ต้องเป็น bed_type='twin' เท่านั้น
     */
    public function matchesBedPreference(RoomDto $room): bool
    {
        if ($this->bedPreference === null || $this->bedPreference === 'any') {
            return true;
        }

        return $room->bedType === $this->bedPreference;
    }

    /**
     * สร้างจาก Eloquent BookingRoom model (ต้อง eager-load addon + roomType)
     */
    public static function fromModel(BookingRoom $br): self
    {
        return new self(
            brId: $br->id,
            type: $br->roomType->name_en ?? 'Unknown',
            checkIn: $br->check_in->format('Y-m-d'),
            checkOut: $br->check_out->format('Y-m-d'),
            extraBeds: $br->addon?->extra_bed ?? 0,
            bedPreference: $br->bed_preference,
        );
    }
}
