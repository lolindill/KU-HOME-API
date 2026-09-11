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

    public function test_get_room_by_id_returns_room(): void
    {
        $room = $this->createRoom();
        $response = $this->getJson("/api/v1/rooms/{$room->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('room.id', $room->id);
    }

    public function test_get_room_by_invalid_uuid_returns_404(): void
    {
        $response = $this->getJson('/api/v1/rooms/not-a-uuid');
        $response->assertStatus(404);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('message', 'Room not found');
    }

    public function test_get_room_type_by_invalid_uuid_returns_404(): void
    {
        $response = $this->getJson('/api/v1/room-types/not-a-uuid');
        $response->assertStatus(404);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('message', 'Room type not found');
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

    public function test_get_room_type_by_id_returns_rates_object_and_integer_baht(): void
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe',
            'name_th' => 'ห้องดีลักซ์',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
            'max_extra_beds' => 1,
            'extra_bed_price' => 500, // 500 บาท (integer baht)
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'name_en' => 'Deluxe Daily',
            'default_price' => 1200,
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily_ku',
            'room_type_id' => $rt->id,
            'name_en' => 'Deluxe KU Daily',
            'default_price' => 1000,
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'group',
            'room_type_id' => $rt->id,
            'code' => 'min_5_rooms',
            'name_en' => 'Deluxe Group Min 5',
            'default_price' => 900,
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'group',
            'room_type_id' => $rt->id,
            'code' => 'min_10_rooms',
            'name_en' => 'Deluxe Group Min 10',
            'default_price' => 750,
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'month',
            'room_type_id' => $rt->id,
            'name_en' => 'Deluxe Monthly',
            'default_price' => 18000,
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/v1/room-types/{$rt->id}");
        $response->assertStatus(200);

        $data = $response->json('room_type');
        $this->assertSame(500, $data['extra_bed_price']);
        $this->assertEquals([
            'daily' => [
                'general' => 1200,
                'ku_member' => 1000,
            ],
            'group' => [
                'min_5_rooms' => 900,
                'min_10_rooms' => 750,
            ],
            'monthly' => 18000,
        ], $data['rates']);

        // 🛡️ Invariants: daily_rate dropped, relations hidden
        $this->assertArrayNotHasKey('daily_rate', $data);
        $this->assertArrayNotHasKey('rate_rows', $data);
        $this->assertArrayNotHasKey('rateRows', $data);
        $this->assertArrayNotHasKey('daily_rate_row', $data);
        $this->assertArrayNotHasKey('dailyRateRow', $data);
    }

    public function test_get_room_type_by_id_falls_back_to_zero_for_missing_or_inactive_rates(): void
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Basic Room',
            'name_th' => 'ห้องพื้นฐาน',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
            'extra_bed_price' => 0,
        ]);

        // inactive rate
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'name_en' => 'Basic Daily Inactive',
            'default_price' => 1500,
            'is_active' => false,
        ]);

        $response = $this->getJson("/api/v1/room-types/{$rt->id}");
        $response->assertStatus(200);

        $data = $response->json('room_type');
        $this->assertSame(0, $data['extra_bed_price']);
        $this->assertEquals([
            'daily' => [
                'general' => 0,
                'ku_member' => 0,
            ],
            'group' => [
                'min_5_rooms' => 0,
                'min_10_rooms' => 0,
            ],
            'monthly' => 0,
        ], $data['rates']);
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

    public function test_availability_ranges_defaults_to_today_plus_six_months_when_no_dates_provided(): void
    {
        $this->createRoom();
        $response = $this->getJson('/api/v1/availability-ranges');
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
        ]);
    }

    // ============================================
    // 📅 unavailable-dates (flat list วันที่ sold-out — แยกราย room type)
    // ============================================

    public function test_unavailable_dates_lists_sold_out_days_per_room_type(): void
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

        // 🌟 วัน today+2: จองทั้งสอง type → sold-out ทั้งคู่
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

        // 🌟 วัน today+4: จองแค่ type A → sold-out เฉพาะ A, B ยังว่าง
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

        // 🌟 หาแถวของแต่ละ room type จาก room_types[] ใน response
        $rowByType = collect($response->json('room_types'))->keyBy('room_type_id');
        $datesA = $rowByType[(string) $roomTypeA->id]['unavailable_dates'];
        $datesB = $rowByType[(string) $roomTypeB->id]['unavailable_dates'];

        // type A: sold-out ทั้ง today+2 และ today+4 / type B: sold-out เฉพาะ today+2
        $this->assertContains(now()->addDays(2)->toDateString(), $datesA);
        $this->assertContains(now()->addDays(4)->toDateString(), $datesA);
        $this->assertContains(now()->addDays(2)->toDateString(), $datesB);
        $this->assertNotContains(now()->addDays(4)->toDateString(), $datesB);
    }

    public function test_unavailable_dates_empty_when_never_full(): void
    {
        // มีห้องว่างเยอะกว่า booking → ไม่มีวันไหน sold-out
        $roomType = $this->createRoomType();
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'available']);
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '102', 'status' => 'available']);

        $response = $this->getJson('/api/v1/unavailable-dates?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]));

        $response->assertStatus(200);
        $rowByType = collect($response->json('room_types'))->keyBy('room_type_id');
        $this->assertSame([], $rowByType[(string) $roomType->id]['unavailable_dates']);
    }

    public function test_unavailable_dates_type_with_no_sellable_rooms_is_never_sold_out(): void
    {
        // type ที่ห้อง maintenance ทั้งหมด (total=0) → ไม่ถือว่า sold-out ทุกวัน → คืน []
        $roomType = $this->createRoomType();
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'maintenance']);

        $response = $this->getJson('/api/v1/unavailable-dates?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ]));

        $response->assertStatus(200);
        $rowByType = collect($response->json('room_types'))->keyBy('room_type_id');
        $this->assertSame([], $rowByType[(string) $roomType->id]['unavailable_dates']);
    }

    public function test_unavailable_dates_defaults_to_today_plus_six_months_when_no_dates_provided(): void
    {
        $this->createRoom();
        $response = $this->getJson('/api/v1/unavailable-dates');
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
        ]);
    }

    // ============================================
    // 📅 unavailable-ranges (auto-window: today+3 → max checkout, intervals {start,end})
    // ============================================

    public function test_unavailable_ranges_groups_consecutive_sold_out_days(): void
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

        // BR แรก: today+4 .. today+6 (sold-out today+4, today+5 — 2 คืนติดกัน)
        // BR สอง: today+9 .. today+10 (sold-out today+9 — แยก interval จากอันแรก)
        // 🌟 window อัตโนมัติ = [today+3, max checkout=today+10]
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDays(4)->toDateString(),
            'check_out' => now()->addDays(6)->toDateString(),
            'status' => 'confirmed',
        ]);
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDays(9)->toDateString(),
            'check_out' => now()->addDays(10)->toDateString(),
            'status' => 'confirmed',
        ]);

        // 🌟 ไม่มี query string — endpoint คำนวณ window เอง
        $response = $this->getJson('/api/v1/unavailable-ranges');

        $response->assertStatus(200);
        $response->assertJsonPath('start', now()->addDays(3)->toDateString());
        $response->assertJsonPath('end', now()->addDays(10)->toDateString());

        $found = collect($response->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $intervals = $found['intervals'];

        // 🌟 expect 2 intervals: [today+4 .. today+5] และ [today+9 .. today+9]
        //    key เป็น {start, end} (ตาม spec — ต่างจาก availability-ranges ที่ใช้ start_date/end_date)
        $this->assertCount(2, $intervals);
        $this->assertEquals(now()->addDays(4)->toDateString(), $intervals[0]['start']);
        $this->assertEquals(now()->addDays(5)->toDateString(), $intervals[0]['end']);
        $this->assertEquals(now()->addDays(9)->toDateString(), $intervals[1]['start']);
        $this->assertEquals(now()->addDays(9)->toDateString(), $intervals[1]['end']);
    }

    public function test_unavailable_ranges_empty_when_no_bookings(): void
    {
        // มี room type แต่ไม่มี booking เลย → ไม่สามารถ lock end ของ window ได้
        $roomType = $this->createRoomType();
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'available']);
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '102', 'status' => 'available']);

        $response = $this->getJson('/api/v1/unavailable-ranges');

        $response->assertStatus(200);
        $response->assertJsonPath('start', null);
        $response->assertJsonPath('end', null);
        $found = collect($response->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $this->assertSame([], $found['intervals']);
    }

    public function test_unavailable_ranges_ignores_days_before_today_plus_3(): void
    {
        // BR ครอบตั้งแต่ today .. today+5 แต่ window เริ่มที่ today+3
        // → วัน today, today+1, today+2 ต้องถูกตัดออก (ไม่อยู่ใน interval)
        $roomType = $this->createRoomType();
        Room::create(['id' => Str::uuid(), 'room_type_id' => $roomType->id, 'room_number' => '101', 'status' => 'available']);

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
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
            'status' => 'confirmed',
        ]);

        $response = $this->getJson('/api/v1/unavailable-ranges');

        $response->assertStatus(200);
        $response->assertJsonPath('start', now()->addDays(3)->toDateString());

        $found = collect($response->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $intervals = $found['intervals'];

        // 🌟 expect 1 interval เริ่มที่ today+3 (auto-start ตัดวันก่อนหน้าออก): [today+3 .. today+4]
        $this->assertCount(1, $intervals);
        $this->assertEquals(now()->addDays(3)->toDateString(), $intervals[0]['start']);
        $this->assertEquals(now()->addDays(4)->toDateString(), $intervals[0]['end']);
    }

    // ============================================
    // 📅 availability-per-day (default window)
    // ============================================

    public function test_availability_per_day_defaults_to_today_plus_six_months_when_no_dates_provided(): void
    {
        $this->createRoom();
        $response = $this->getJson('/api/v1/availability-per-day');
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
        ]);
    }

    public function test_availability_per_day_defaults_end_date_when_only_start_date_provided(): void
    {
        $this->createRoom();
        $startDate = now()->addDays(5)->toDateString();
        $response = $this->getJson('/api/v1/availability-per-day?start_date='.$startDate);
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'start_date' => $startDate,
            'end_date' => now()->addDays(5)->addMonths(6)->toDateString(),
        ]);
    }

    // ============================================
    // 🚧 mock/availability-ranges (mock data for frontend test)
    // ============================================

    public function test_mock_availability_ranges_returns_static_relative_data(): void
    {
        $response = $this->getJson('/api/v1/mock/availability-ranges');
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Availability ranges fetched successfully',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);

        $roomTypes = $response->json('room_types');
        $this->assertCount(3, $roomTypes);

        $superior = collect($roomTypes)->firstWhere('name_en', 'Superior');
        $this->assertNotNull($superior);
        $this->assertEquals('00000000-0000-4000-8000-000000000001', $superior['room_type_id']);
        $this->assertEquals('ห้องซูพีเรียร์', $superior['name_th']);
        $this->assertCount(2, $superior['intervals']);
        $this->assertEquals(now()->addDays(5)->toDateString(), $superior['intervals'][0]['start_date']);
        $this->assertEquals(now()->addDays(8)->toDateString(), $superior['intervals'][0]['end_date']);
        $this->assertEquals(now()->addDays(15)->toDateString(), $superior['intervals'][1]['start_date']);
        $this->assertEquals(now()->addDays(18)->toDateString(), $superior['intervals'][1]['end_date']);

        $deluxe = collect($roomTypes)->firstWhere('name_en', 'Deluxe');
        $this->assertNotNull($deluxe);
        $this->assertEquals('00000000-0000-4000-8000-000000000002', $deluxe['room_type_id']);
        $this->assertEquals('ห้องดีลักซ์', $deluxe['name_th']);
        $this->assertCount(1, $deluxe['intervals']);
        $this->assertEquals(now()->addDays(10)->toDateString(), $deluxe['intervals'][0]['start_date']);
        $this->assertEquals(now()->addDays(12)->toDateString(), $deluxe['intervals'][0]['end_date']);

        $suite = collect($roomTypes)->firstWhere('name_en', 'Suite');
        $this->assertNotNull($suite);
        $this->assertEquals('00000000-0000-4000-8000-000000000003', $suite['room_type_id']);
        $this->assertEquals('ห้องสวีท', $suite['name_th']);
        $this->assertSame([], $suite['intervals']);
    }
}
