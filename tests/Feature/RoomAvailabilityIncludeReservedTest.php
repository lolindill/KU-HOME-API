<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🏨 include_reserved บน availability summary — king counters + silent-ignore (24/09/26)
 *
 *    (wayfinder/reserved-room-pool ticket 01 — tracer bullet เสร็จบน period model)
 *    Contract พื้นฐาน (admin flag → breakdown / non-admin silent / maintenance absolute)
 *    ครอบแล้วใน RoomStatePeriodTest — ไฟล์นี้เก็บส่วนที่เหลือ:
 *    - king counters เดินตาม extended pool เมื่อ admin ส่ง flag คู่กับ bed_type=king_size
 *    - anonymous ส่ง flag → เมยายีเงียบ ๆ (payload เหมือนไม่ส่ง — ห้าม 403)
 *    - ไม่ส่ง flag → ไม่มีฟิลด์ reserved ปรากฏ (payload backward-compatible)
 *    "ห้องสำรอง" = ห้องติด reserved period ช่วงทับ (rooms.is_reserved ถูก drop แล้ว —
 *    wayfinder/room-state-periods) · maintenance period ถูกตัดเสมอ ไม่ว่าส่ง flag หรือไม่
 */
class RoomAvailabilityIncludeReservedTest extends TestCase
{
    use RefreshDatabase;

    // counter แทน rand() — กันเลขห้องชนกันเอง (precedent FrontDeskTest)
    private static int $roomSeq = 0;

    private function createRoomType(): RoomType
    {
        return RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe Suite',
            'name_th' => 'ห้องทดสอบ',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
    }

    private function createRoom(RoomType $roomType, string $bedType = 'twin', string $status = 'available'): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '7'.(++self::$roomSeq),
            'status' => $status,
            'bed_type' => $bedType,
        ]);
    }

    private function createReservedPeriod(Room $room, string $startDate, ?string $endDate = null): RoomStatePeriod
    {
        return RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'reserved',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    private function createMaintenancePeriod(Room $room, string $startDate, ?string $endDate = null): RoomStatePeriod
    {
        return RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    /**
     * ยิง availability แล้วคืน row ของ room type ที่สร้างไว้
     */
    private function fetchRow(RoomType $roomType, array $params = []): array
    {
        $response = $this->getJson('/api/v1/availability?'.http_build_query(array_merge([
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
        ], $params)));

        $response->assertStatus(200);

        $found = collect($response->json('room_types'))
            ->firstWhere('room_type_id', $roomType->id);

        $this->assertNotNull($found, 'Created room type should appear in availability');

        return $found;
    }

    public function test_king_counters_follow_extended_pool_under_admin_flag(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();

        // king ปกติ 1 + king ติด reserved period 1 + twin ติด reserved period 1
        $this->createRoom($roomType, 'king_size');
        $reservedKing = $this->createRoom($roomType, 'king_size');
        $reservedTwin = $this->createRoom($roomType, 'twin');
        $this->createReservedPeriod(
            $reservedKing,
            now()->addDays(5)->toDateString(),
            now()->addDays(8)->toDateString()
        );
        $this->createReservedPeriod(
            $reservedTwin,
            now()->addDays(5)->toDateString(),
            now()->addDays(8)->toDateString()
        );

        $window = [
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
        ];

        // ไม่ส่ง flag → king pool เฉพาะห้องขายปกติ (reserved king ถูกตัด) — twin ไม่เคยนับ
        $plain = $this->fetchRow($roomType, $window + ['bed_type' => 'king_size']);
        $this->assertEquals(1, $plain['king_total_rooms']);
        $this->assertEquals(1, $plain['available_rooms']);

        // admin ส่ง flag → king pool รวม king ติด reserved period (twin ยังไม่เข้า king นับ)
        $flagged = $this->fetchRow($roomType, $window + ['bed_type' => 'king_size', 'include_reserved' => 'true']);
        $this->assertEquals(2, $flagged['king_total_rooms']);
        $this->assertEquals(2, $flagged['available_rooms']);
        $this->assertEquals(0, $flagged['king_occupied']);
    }

    public function test_king_counters_exclude_maintenance_period_even_with_flag(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();

        $this->createRoom($roomType, 'king_size');
        $maintKing = $this->createRoom($roomType, 'king_size');
        $this->createMaintenancePeriod(
            $maintKing,
            now()->addDays(5)->toDateString(),
            now()->addDays(8)->toDateString()
        );

        // กฎเหล็ก absolute — king ติด maintenance period ไม่กลับมาแม้ admin ส่ง flag
        $flagged = $this->fetchRow($roomType, [
            'bed_type' => 'king_size',
            'include_reserved' => 'true',
        ]);
        $this->assertEquals(1, $flagged['king_total_rooms']);
        $this->assertEquals(1, $flagged['available_rooms']);
    }

    public function test_anonymous_flag_is_silently_ignored(): void
    {
        $roomType = $this->createRoomType();
        $reservedRoom = $this->createRoom($roomType);
        $this->createRoom($roomType);
        $this->createReservedPeriod($reservedRoom, now()->addDays(5)->toDateString(), null);

        // anonymous (ไม่ล็อกอิน) ส่ง flag — payload เท่าไม่ส่ง (ห้าม 403 / ห้ามขยาย pool)
        $row = $this->fetchRow($roomType, ['include_reserved' => 'true']);
        $this->assertEquals(1, $row['available_rooms']);
        $this->assertArrayNotHasKey('reserved_rooms', $row);
        $this->assertArrayNotHasKey('sellable_rooms', $row);
        $this->assertArrayNotHasKey('include_reserved', $row['search_criteria']);
    }

    public function test_no_flag_response_has_no_reserved_fields(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $reservedRoom = $this->createRoom($roomType);
        $this->createRoom($roomType);
        $this->createReservedPeriod($reservedRoom, now()->addDays(5)->toDateString(), null);

        // regression — แม้แต่ admin ไม่ส่ง flag: payload ต้องเป็นรูปแบบเดิมทุกไบต์
        // (available_rooms นับ sellable pool เท่านั้น, ไม่มีฟิลด์ reserved โผล่)
        $row = $this->fetchRow($roomType);
        $this->assertEquals(1, $row['available_rooms']);
        $this->assertArrayNotHasKey('reserved_rooms', $row);
        $this->assertArrayNotHasKey('sellable_rooms', $row);
        $this->assertArrayNotHasKey('include_reserved', $row['search_criteria']);
    }
}
