<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoomTest extends TestCase
{
    use RefreshDatabase;

    private function createRoomType(): RoomType
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Double',
            'name_th' => 'สแตนดาร์ด ดับเบิล',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        // 🌟 Refactor (22/07/26): rate_daily_general ย้ายไป global_rates แล้ว
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'code' => null,
            'name_en' => 'Standard Double Daily',
            'default_price' => 1500,
            'is_active' => true,
        ]);

        return $rt;
    }

    private function createRoom(string $status = 'available'): Room
    {
        $roomType = $this->createRoomType();

        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '10'.rand(1, 99),
            'status' => $status,
        ]);
    }

    // ============================================
    // 🔓 Public: Room listing
    // ============================================

    public function test_anyone_can_list_rooms(): void
    {
        $roomA = $this->createRoom();
        $roomB = $this->createRoom();
        $response = $this->getJson('/api/v1/rooms');
        $response->assertStatus(200);

        // 🛡️ Scrutinize: Verify rooms actually returned (not just empty 200)
        $rooms = $response->json('rooms');
        $roomIds = collect($rooms)->pluck('id');
        $this->assertContains($roomA->id, $roomIds, 'Room A should be in the listing');
        $this->assertContains($roomB->id, $roomIds, 'Room B should be in the listing');
    }

    public function test_anyone_can_list_room_types(): void
    {
        $roomType = $this->createRoomType();
        $response = $this->getJson('/api/v1/room-types');
        $response->assertStatus(200);

        // 🛡️ Scrutinize: Verify room type actually returned
        $roomTypes = $response->json('room_types') ?? $response->json('data') ?? [];
        $ids = collect($roomTypes)->pluck('id')->map(fn ($id) => (string) $id);
        $this->assertContains((string) $roomType->id, $ids, 'Created room type should be in the listing');
    }

    public function test_anyone_can_check_availability(): void
    {
        $roomType = $this->createRoomType();
        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '201',
            'status' => 'available',
        ]);

        $response = $this->getJson('/api/v1/availability?'.http_build_query([
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
        ]));
        $response->assertStatus(200);

        // 🛡️ Scrutinize: Verify availability payload reflects the room we created
        $roomTypes = $response->json('room_types') ?? [];
        $found = collect($roomTypes)->firstWhere('room_type_id', $roomType->id);
        $this->assertNotNull($found, 'Created room type should appear in availability');
        $this->assertGreaterThanOrEqual(1, $found['available_rooms'] ?? 0,
            'Available rooms should reflect the room we just created');
    }

    // ============================================
    // 🔐 Admin: Room status update
    // ============================================

    public function test_admin_can_update_room_status(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom('available');

        $response = $this->putJson("/api/v1/rooms/{$room->id}/status", [
            'status' => 'dirty',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('dirty', $room->fresh()->status);
    }

    public function test_admin_cannot_do_invalid_room_status_transition(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom('available');

        $response = $this->putJson("/api/v1/rooms/{$room->id}/status", [
            'status' => 'checkout_makeup', // available → checkout_makeup is invalid
        ]);
        $response->assertStatus(422);
    }

    // ============================================
    // 🔒 Non-admin rejected
    // ============================================

    public function test_non_admin_cannot_update_room_status(): void
    {
        $this->actingAsUser();
        $room = $this->createRoom('available');

        $response = $this->putJson("/api/v1/rooms/{$room->id}/status", [
            'status' => 'dirty',
        ]);
        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_update_room_status(): void
    {
        $room = $this->createRoom('available');
        $response = $this->putJson("/api/v1/rooms/{$room->id}/status", [
            'status' => 'dirty',
        ]);
        $response->assertStatus(401);
    }

    // ============================================
    // ✅ #31: Availability query consistency
    // ============================================

    public function test_cancelled_booking_room_does_not_reduce_availability(): void
    {
        $roomType = $this->createRoomType();
        $room = Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'status' => 'available',
        ]);

        // 🌟 Refactor (29/06/26): container ไม่มี 'cancelled' แล้ว — draft หมดอายุถูก hard delete
        // แต่ BR-level ยังมี 'cancelled' อยู่ (BR states: draft/confirmed/checked_in/checked_out/cancelled/no_show)
        // การทดสอบนี้ยืนยันว่า BR status='cancelled' จะไม่นับลด availability
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
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => 'cancelled', // BR-level cancelled — ไม่นับลด availability
        ]);

        $response = $this->getJson('/api/v1/availability?'.http_build_query([
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
        ]));

        $response->assertStatus(200);
        $roomTypes = $response->json('room_types');
        $found = collect($roomTypes)->firstWhere('room_type_id', $roomType->id);

        // BR-level cancelled should NOT reduce available rooms
        $this->assertEquals(1, $found['available_rooms']);
    }

    public function test_confirmed_booking_reduces_availability(): void
    {
        $roomType = $this->createRoomType();
        $room = Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'status' => 'available',
        ]);

        // 🌟 Refactor (25/06/26): guest fields + dates ย้ายไป BR-level
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
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
        ]);

        $response = $this->getJson('/api/v1/availability?'.http_build_query([
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
        ]));

        $response->assertStatus(200);
        $roomTypes = $response->json('room_types');
        $found = collect($roomTypes)->firstWhere('room_type_id', $roomType->id);

        // confirmed booking should reduce available rooms to 0
        $this->assertEquals(0, $found['available_rooms']);
    }

    // ============================================
    // 📅 availability-ranges (intervals of sold-out days)
    // ============================================

    public function test_availability_ranges_groups_consecutive_sold_out_days(): void
    {
        // room_type ที่มีห้องเดียว → ทุก BR overlap = sold-out วันนั้น
        $roomType = $this->createRoomType();
        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'status' => 'available',
        ]);

        $user = User::factory()->create();
        $booking = Booking::create([
            'user_id' => $user->id,
            'confirmation' => 'TEST-'.Str::uuid(),
            'source' => 'admin',
            'status' => 'confirmed',
            'total_amount' => 3000,
        ]);

        // BR แรก: today+1 .. today+3 (sold-out 2 คืนติด)
        // BR สอง: today+6 .. today+7 (sold-out 1 คืน — แยก interval จากอันแรก)
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
        ]);
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
            'status' => 'confirmed',
        ]);

        $response = $this->getJson('/api/v1/availability-ranges?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]));

        $response->assertStatus(200);
        $found = collect($response->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $intervals = $found['intervals'];

        // 🌟 expect 2 intervals: [today+1 .. today+2] และ [today+6 .. today+6]
        //    (end_date = วันสุดท้ายที่ sold-out, คืน check_out ไม่นับเพราะ check-out วันนั้นว่างแล้ว)
        $this->assertCount(2, $intervals);
        $this->assertEquals(now()->addDays(1)->toDateString(), $intervals[0]['start_date']);
        $this->assertEquals(now()->addDays(2)->toDateString(), $intervals[0]['end_date']);
        $this->assertEquals(now()->addDays(6)->toDateString(), $intervals[1]['start_date']);
        $this->assertEquals(now()->addDays(6)->toDateString(), $intervals[1]['end_date']);
    }

    public function test_availability_ranges_empty_when_never_sold_out(): void
    {
        $roomType = $this->createRoomType();
        // 2 ห้อง แต่ไม่มี booking → ไม่มีวัน sold-out
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'available']);
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '102', 'status' => 'available']);

        $response = $this->getJson('/api/v1/availability-ranges?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]));

        $response->assertStatus(200);
        $found = collect($response->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $this->assertSame([], $found['intervals']);
    }

    public function test_availability_ranges_validates_required_dates(): void
    {
        $response = $this->getJson('/api/v1/availability-ranges');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    // ============================================
    // 📅 unavailable-dates (flat list วันที่จองไม่ได้เลย — ทุก room type เต็ม)
    // ============================================

    public function test_unavailable_dates_lists_days_where_all_room_types_sold_out(): void
    {
        // 2 room types, แต่ละ type มี 1 ห้อง → sold-out เมื่อมี BR overlap
        $roomTypeA = $this->createRoomType();
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomTypeA->id, 'room_number' => '101', 'status' => 'available']);
        $roomTypeB = $this->createRoomType();
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomTypeB->id, 'room_number' => '201', 'status' => 'available']);

        $user = User::factory()->create();
        $booking = Booking::create([
            'user_id' => $user->id,
            'confirmation' => 'TEST-'.Str::uuid(),
            'source' => 'admin',
            'status' => 'confirmed',
            'total_amount' => 3000,
        ]);

        // 🌟 วัน today+2: จองทั้งสอง type → จองไม่ได้เลย (อยู่ใน list)
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomTypeA->id,
            'check_in' => now()->addDays(2)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
        ]);
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomTypeB->id,
            'check_in' => now()->addDays(2)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
        ]);

        // 🌟 วัน today+4: จองแค่ type A → type B ยังว่าง → ยังจองได้ (ไม่อยู่ใน list)
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomTypeA->id,
            'check_in' => now()->addDays(4)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed',
        ]);

        $response = $this->getJson('/api/v1/unavailable-dates?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]));

        $response->assertStatus(200);
        $dates = $response->json('unavailable_dates');

        // 🌟 expect เฉพาะ today+2 (ทั้งสอง type เต็ม). today+4 มี type B ว่าง → ไม่อยู่ใน list
        $this->assertContains(now()->addDays(2)->toDateString(), $dates);
        $this->assertNotContains(now()->addDays(4)->toDateString(), $dates);
    }

    public function test_unavailable_dates_empty_when_never_full(): void
    {
        // มีห้องว่างเยอะกว่า booking → ไม่มีวันไหนเต็มทุก type
        $roomType = $this->createRoomType();
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'available']);
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '102', 'status' => 'available']);

        $response = $this->getJson('/api/v1/unavailable-dates?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]));

        $response->assertStatus(200);
        $this->assertSame([], $response->json('unavailable_dates'));
    }

    public function test_unavailable_dates_validates_required_dates(): void
    {
        $response = $this->getJson('/api/v1/unavailable-dates');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_date', 'end_date']);
    }
}
