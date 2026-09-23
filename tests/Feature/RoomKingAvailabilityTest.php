<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🛏️ bed_type=king_size availability (09/09/26)
 *
 * GET /api/v1/availability?bed_type=king_size — นับ availability เฉพาะห้อง king:
 *   - king pool = sellable (status NOT IN maintenance/reserved_closed) — ตรงกับ createBooking
 *   - king occupied = hybrid ตาม lifecycle:
 *       ก่อน assign (room_id null) → BR bed_preference=king_size กินห้อง king แน่นอน
 *       หลัง assign (room_id มี)    → นับตาม bed_type ของห้องที่ assign จริง
 *   - BR states ที่นับ: draft, confirmed, checked_in (ตรงกับ availability/createBooking เดิม)
 *   - ไม่ส่ง bed_type → payload เหมือนเดิมทุกไบต์ (backward compatible)
 */
class RoomKingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function createRoomType(string $nameEn = 'Deluxe Suite'): RoomType
    {
        return RoomType::create([
            'id' => Str::uuid(),
            'name_en' => $nameEn,
            'name_th' => 'ห้องทดสอบ',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
    }

    private function createRoom(RoomType $roomType, string $bedType, string $number, string $status = 'available'): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => $number,
            'status' => $status,
            'bed_type' => $bedType,
        ]);
    }

    private function createBookingRoom(RoomType $roomType, ?Room $room, ?string $bedPreference, string $status): BookingRoom
    {
        $user = User::factory()->create();
        $booking = Booking::create([
            'user_id' => $user->id,
            'confirmation' => 'TEST-'.Str::uuid(),
            'source' => 'admin',
            'status' => 'confirmed',
            'total_amount' => 3000,
        ]);

        return BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => $room?->id,
            'bed_preference' => $bedPreference,
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => $status,
        ]);
    }

    /**
     * ยิง availability แล้วคืน row ของ room type ที่สร้างไว้
     */
    private function fetchRow(RoomType $roomType, array $params = []): array
    {
        $response = $this->getJson('/api/v1/availability?'.http_build_query(array_merge([
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
        ], $params)));

        $response->assertStatus(200);

        $found = collect($response->json('room_types'))
            ->firstWhere('room_type_id', $roomType->id);

        $this->assertNotNull($found, 'Created room type should appear in availability');

        return $found;
    }

    public function test_without_bed_type_param_response_has_no_king_fields(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, 'king_size', '801');
        $this->createRoom($roomType, 'twin', '101');

        $row = $this->fetchRow($roomType);

        // payload เดิม — ไม่มีฟิลด์ king, available_rooms นับทุกห้อง (2)
        $this->assertArrayNotHasKey('king_total_rooms', $row);
        $this->assertArrayNotHasKey('king_occupied', $row);
        $this->assertArrayNotHasKey('bed_type', $row['search_criteria']);
        $this->assertEquals(2, $row['available_rooms']);
    }

    public function test_king_pool_counts_only_sellable_king_rooms(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, 'king_size', '801');
        $this->createRoom($roomType, 'king_size', '802');
        $this->createRoom($roomType, 'king_size', '803', 'maintenance'); // ไม่นับใน pool
        $this->createRoom($roomType, 'twin', '101'); // ไม่ใช่ king

        $row = $this->fetchRow($roomType, ['bed_type' => 'king_size']);

        // pool = king sellable = 2 (maintenance โดนเขี่ย, twin ไม่นับ)
        // occupied ยังเป็น 0 → available = 2 (จำนวนห้อง status ปกติ = 3 ไม่เกี่ยวกับ king view)
        $this->assertEquals(2, $row['king_total_rooms']);
        $this->assertEquals(0, $row['king_occupied']);
        $this->assertEquals(2, $row['available_rooms']);
        $this->assertEquals('king_size', $row['search_criteria']['bed_type']);
    }

    public function test_unassigned_king_preference_reduces_king_availability(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, 'king_size', '801');
        $this->createRoom($roomType, 'king_size', '802');

        // BR king-pref ยังไม่ assign (room_id null) — เช่น draft booking — กินห้อง king แน่นอน
        $this->createBookingRoom($roomType, null, 'king_size', 'draft');

        $row = $this->fetchRow($roomType, ['bed_type' => 'king_size']);

        $this->assertEquals(2, $row['king_total_rooms']);
        $this->assertEquals(1, $row['king_occupied']);
        $this->assertEquals(1, $row['available_rooms']);
    }

    public function test_assigned_king_room_reduces_king_availability(): void
    {
        $roomType = $this->createRoomType();
        $kingA = $this->createRoom($roomType, 'king_size', '801');
        $this->createRoom($roomType, 'king_size', '802');

        // BR ไม่ได้ระบุ preference แต่ถูก assign เข้าห้อง king แล้ว → กิน pool king
        $this->createBookingRoom($roomType, $kingA, null, 'confirmed');

        $row = $this->fetchRow($roomType, ['bed_type' => 'king_size']);

        $this->assertEquals(2, $row['king_total_rooms']);
        $this->assertEquals(1, $row['king_occupied']);
        $this->assertEquals(1, $row['available_rooms']);
    }

    public function test_assigned_twin_room_does_not_reduce_king_availability(): void
    {
        $roomType = $this->createRoomType();
        $twin = $this->createRoom($roomType, 'twin', '101');
        $this->createRoom($roomType, 'king_size', '801');

        // BR assign เข้าห้อง twin → king pool ไม่ถูกกิน
        $this->createBookingRoom($roomType, $twin, null, 'confirmed');

        $row = $this->fetchRow($roomType, ['bed_type' => 'king_size']);

        $this->assertEquals(1, $row['king_total_rooms']);
        $this->assertEquals(0, $row['king_occupied']);
        $this->assertEquals(1, $row['available_rooms']);
    }

    public function test_floating_no_preference_booking_does_not_reduce_king_availability(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, 'king_size', '801');

        // BR ลอย (ไม่มี preference, ยังไม่ assign) — allocator อาจจัด twin ก็ได้ → ไม่หัก king
        $this->createBookingRoom($roomType, null, null, 'draft');

        $row = $this->fetchRow($roomType, ['bed_type' => 'king_size']);

        $this->assertEquals(1, $row['king_total_rooms']);
        $this->assertEquals(0, $row['king_occupied']);
        $this->assertEquals(1, $row['available_rooms']);
    }

    public function test_checked_out_booking_room_frees_king_room(): void
    {
        $roomType = $this->createRoomType();
        $king = $this->createRoom($roomType, 'king_size', '801');

        // checked_out ไม่นับลด availability (ตรงกับกฎเดิม)
        $this->createBookingRoom($roomType, $king, 'king_size', 'checked_out');

        $row = $this->fetchRow($roomType, ['bed_type' => 'king_size']);

        $this->assertEquals(0, $row['king_occupied']);
        $this->assertEquals(1, $row['available_rooms']);
    }

    public function test_non_overlapping_king_preference_does_not_reduce_king_availability(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, 'king_size', '801');

        // king-pref แต่ค้างคืนคนละช่วง (ไม่ overlap กับช่วงค้น) → ไม่หัก
        $user = User::factory()->create();
        $booking = Booking::create([
            'user_id' => $user->id,
            'confirmation' => 'TEST-'.Str::uuid(),
            'source' => 'admin',
            'status' => 'confirmed',
            'total_amount' => 3000,
        ]);
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'bed_preference' => 'king_size',
            'check_in' => now()->addDays(10)->toDateString(),
            'check_out' => now()->addDays(12)->toDateString(),
            'status' => 'confirmed',
        ]);

        $row = $this->fetchRow($roomType, ['bed_type' => 'king_size']);

        $this->assertEquals(0, $row['king_occupied']);
        $this->assertEquals(1, $row['available_rooms']);
    }

    public function test_king_filter_combines_with_max_guests(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType, 'king_size', '801');

        // max_guests=5 > type รองรับ 2 → type ถูกกรองออกทั้ง row (เงื่อนไข AND)
        $response = $this->getJson('/api/v1/availability?'.http_build_query([
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'bed_type' => 'king_size',
            'max_guests' => 5,
        ]));
        $response->assertStatus(200);

        $found = collect($response->json('room_types'))
            ->firstWhere('room_type_id', $roomType->id);
        $this->assertNull($found, 'Room type below max_guests should be filtered out');
    }

    public function test_invalid_bed_type_returns_422(): void
    {
        // รองรับ king_size เท่านั้น (ตามขอบเขตที่ตกลง)
        $this->getJson('/api/v1/availability?bed_type=twin')->assertStatus(422);
        $this->getJson('/api/v1/availability?bed_type=double')->assertStatus(422);
    }
}
