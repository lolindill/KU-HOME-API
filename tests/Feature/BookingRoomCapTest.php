<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingRoomCapTest extends TestCase
{
    use RefreshDatabase;

    private function createRoomType(): RoomType
    {
        $roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe Suite',
            'name_th' => 'ดีลักซ์ สวีท',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $roomType->id,
            'code' => null,
            'name_en' => 'Deluxe Daily',
            'default_price' => 1500,
            'is_active' => true,
        ]);

        return $roomType;
    }

    private function createRooms(RoomType $roomType, int $count = 10): void
    {
        for ($i = 0; $i < $count; $i++) {
            Room::create([
                'room_type_id' => $roomType->id,
                'room_number' => (string) (100 + $i),
                'status' => 'available',
            ]);
        }
    }

    private function buildRoomPayload(string $roomTypeId, string $checkIn, string $checkOut, int $count): array
    {
        $rooms = [];
        for ($i = 0; $i < $count; $i++) {
            $rooms[] = [
                'room_type_id' => $roomTypeId,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'guests' => [
                    ['title' => 'mr', 'name' => 'Guest '.$i, 'nationality' => 'TH'],
                ],
            ];
        }

        return $rooms;
    }

    private function createDraftBooking(User $user, RoomType $roomType, int $roomCount = 3): Booking
    {
        $booking = Booking::create([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 3000 * $roomCount,
            'payment_deadline' => now()->addHours(24),
        ]);

        for ($i = 0; $i < $roomCount; $i++) {
            $br = BookingRoom::create([
                'booking_id' => $booking->id,
                'room_type_id' => $roomType->id,
                'room_id' => null,
                'check_in' => Carbon::now('Asia/Bangkok')->addDays(2)->toDateString(),
                'check_out' => Carbon::now('Asia/Bangkok')->addDays(4)->toDateString(),
                'status' => 'draft',
                'guests' => [
                    ['title' => 'mr', 'name' => 'Initial Guest '.$i, 'nationality' => 'TH'],
                ],
            ]);

            Addon::create([
                'booking_room_id' => $br->id,
                'extra_bed' => 0,
                'extra_bed_price' => 0,
                'breakfast' => 0,
                'breakfast_price' => 0,
                'early_checkIn_price' => 0,
                'lateCheckOut_price' => 0,
            ]);
        }

        return $booking->fresh(['bookingRooms']);
    }

    // ============================================
    // Path 1: POST /api/v1/bookings (Create booking)
    // ============================================

    public function test_non_admin_cannot_create_booking_with_5_rooms(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 10);

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $payload = [
            'source' => 'online',
            'booking_rooms' => $this->buildRoomPayload($roomType->id, $checkIn, $checkOut, 5),
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms']);
        $this->assertEquals(
            'สามารถจองได้สูงสุด 4 ห้องต่อการจอง หากต้องการจองมากกว่านี้ กรุณาติดต่อผู้ดูแลค่ะ',
            $response->json('errors')['booking_rooms'][0]
        );
    }

    public function test_non_admin_can_create_booking_with_exactly_4_rooms(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 10);

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $payload = [
            'source' => 'online',
            'booking_rooms' => $this->buildRoomPayload($roomType->id, $checkIn, $checkOut, 4),
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $this->assertCount(4, $response->json('booking_rooms'));
    }

    public function test_admin_can_create_booking_with_6_rooms(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 10);

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $payload = [
            'source' => 'admin',
            'booking_rooms' => $this->buildRoomPayload($roomType->id, $checkIn, $checkOut, 6),
        ];

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $this->assertCount(6, $response->json('booking_rooms'));
    }

    public function test_non_admin_cannot_spoof_admin_exemption_via_source_field_on_create(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 10);

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $payload = [
            'source' => 'admin', // spoof attempt
            'booking_rooms' => $this->buildRoomPayload($roomType->id, $checkIn, $checkOut, 5),
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms']);
        $this->assertEquals(
            'สามารถจองได้สูงสุด 4 ห้องต่อการจอง หากต้องการจองมากกว่านี้ กรุณาติดต่อผู้ดูแลค่ะ',
            $response->json('errors')['booking_rooms'][0]
        );
    }

    // ============================================
    // Path 2: POST /api/v1/bookings/{id}/rooms (Add rooms)
    // ============================================

    public function test_non_admin_adding_rooms_3_existing_plus_2_new_rejected_with_422(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 10);

        $booking = $this->createDraftBooking($user, $roomType, 3);

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $payload = [
            'booking_rooms' => $this->buildRoomPayload($roomType->id, $checkIn, $checkOut, 2),
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/bookings/{$booking->id}/rooms", $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'message' => 'สามารถจองได้สูงสุด 4 ห้องต่อการจอง หากต้องการจองมากกว่านี้ กรุณาติดต่อผู้ดูแลค่ะ',
        ]);

        // Rooms count remains 3
        $this->assertEquals(3, $booking->fresh()->bookingRooms()->count());
    }

    public function test_non_admin_adding_rooms_3_existing_plus_1_new_passes(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 10);

        $booking = $this->createDraftBooking($user, $roomType, 3);

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $payload = [
            'booking_rooms' => $this->buildRoomPayload($roomType->id, $checkIn, $checkOut, 1),
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/bookings/{$booking->id}/rooms", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Total rooms now exactly 4
        $this->assertEquals(4, $booking->fresh()->bookingRooms()->count());
    }

    public function test_non_admin_adding_room_to_already_4_rooms_booking_rejected(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 10);

        $booking = $this->createDraftBooking($user, $roomType, 4);

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $payload = [
            'booking_rooms' => $this->buildRoomPayload($roomType->id, $checkIn, $checkOut, 1),
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/bookings/{$booking->id}/rooms", $payload);

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'message' => 'สามารถจองได้สูงสุด 4 ห้องต่อการจอง หากต้องการจองมากกว่านี้ กรุณาติดต่อผู้ดูแลค่ะ',
        ]);
        $this->assertEquals(4, $booking->fresh()->bookingRooms()->count());
    }

    public function test_admin_adding_rooms_exceeding_cap_passes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 15);

        // Admin adds to an existing 4-room booking
        $booking = $this->createDraftBooking($admin, $roomType, 4);

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        // Add 3 more rooms (total 7)
        $payload = [
            'booking_rooms' => $this->buildRoomPayload($roomType->id, $checkIn, $checkOut, 3),
        ];

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/bookings/{$booking->id}/rooms", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertEquals(7, $booking->fresh()->bookingRooms()->count());
    }

    // ============================================
    // Path 3: PUT /api/v1/bookings/{id}/rooms (Batch edit - no room increase)
    // ============================================

    public function test_batch_edit_does_not_enforce_room_cap_and_preserves_behavior(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 10);

        // Grandfathered or existing 4-room booking
        $booking = $this->createDraftBooking($user, $roomType, 4);
        $rooms = $booking->bookingRooms;

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(3)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(5)->toDateString();

        $batchPayload = [
            'booking_rooms' => [
                [
                    'booking_room_id' => $rooms[0]->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
                [
                    'booking_room_id' => $rooms[1]->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ];

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/rooms", $batchPayload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertEquals(4, $booking->fresh()->bookingRooms()->count());
    }

    // ============================================
    // Config Override Test
    // ============================================

    public function test_custom_config_max_rooms_per_booking_is_honored(): void
    {
        Config::set('booking.max_rooms_per_booking', 2);

        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRooms($roomType, 10);

        $checkIn = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        // 3 rooms should be rejected when cap = 2
        $payload = [
            'source' => 'online',
            'booking_rooms' => $this->buildRoomPayload($roomType->id, $checkIn, $checkOut, 3),
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms']);
        $this->assertEquals(
            'สามารถจองได้สูงสุด 2 ห้องต่อการจอง หากต้องการจองมากกว่านี้ กรุณาติดต่อผู้ดูแลค่ะ',
            $response->json('errors')['booking_rooms'][0]
        );
    }
}
