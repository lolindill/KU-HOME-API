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
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingAdvanceNoticeTest extends TestCase
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

    private function createRoom(RoomType $roomType): Room
    {
        return Room::create([
            'room_type_id' => $roomType->id,
            'room_number' => (string) rand(100, 999),
            'status' => 'available',
        ]);
    }

    private function createDraftBooking(User $user, RoomType $roomType, int $roomCount = 1): Booking
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
                    ['title' => 'mr', 'name' => 'Advance Guest', 'nationality' => 'TH'],
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

    public function test_non_admin_cannot_create_booking_for_today(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $today = Carbon::now('Asia/Bangkok')->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $today,
                    'check_out' => $checkOut,
                    'guests' => [['title' => 'mr', 'name' => 'User', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms.0.check_in']);
        $this->assertEquals(
            'วันที่เช็คอินต้องจองล่วงหน้าอย่างน้อย 2 วันค่ะ',
            $response->json('errors')['booking_rooms.0.check_in'][0]
        );
    }

    public function test_non_admin_cannot_create_booking_for_tomorrow(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $tomorrow = Carbon::now('Asia/Bangkok')->addDay()->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(3)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $tomorrow,
                    'check_out' => $checkOut,
                    'guests' => [['title' => 'mr', 'name' => 'User', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms.0.check_in']);
        $this->assertEquals(
            'วันที่เช็คอินต้องจองล่วงหน้าอย่างน้อย 2 วันค่ะ',
            $response->json('errors')['booking_rooms.0.check_in'][0]
        );
    }

    public function test_non_admin_can_create_booking_two_days_in_advance(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $twoDaysAdvance = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $twoDaysAdvance,
                    'check_out' => $checkOut,
                    'guests' => [['title' => 'mr', 'name' => 'User', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
    }

    public function test_admin_can_create_booking_for_today(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $today = Carbon::now('Asia/Bangkok')->toDateString();
        $tomorrow = Carbon::now('Asia/Bangkok')->addDay()->toDateString();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $today,
                    'check_out' => $tomorrow,
                    'guests' => [['title' => 'mr', 'name' => 'Walkin Guest', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
    }

    public function test_admin_can_create_booking_for_tomorrow(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $tomorrow = Carbon::now('Asia/Bangkok')->addDay()->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(3)->toDateString();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $tomorrow,
                    'check_out' => $checkOut,
                    'guests' => [['title' => 'mr', 'name' => 'Tomorrow Guest', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
    }

    public function test_admin_cannot_create_booking_in_the_past(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $yesterday = Carbon::now('Asia/Bangkok')->subDay()->toDateString();
        $tomorrow = Carbon::now('Asia/Bangkok')->addDay()->toDateString();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $yesterday,
                    'check_out' => $tomorrow,
                    'guests' => [['title' => 'mr', 'name' => 'Past Guest', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms.0.check_in']);
        $this->assertEquals(
            'วันที่เช็คอินต้องไม่เป็นวันในอดีต',
            $response->json('errors')['booking_rooms.0.check_in'][0]
        );
    }

    public function test_admin_exemption_judged_by_sanctum_role_not_payload_source(): void
    {
        // User role is 'user', but payload tries to spoof 'source' => 'admin'
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $tomorrow = Carbon::now('Asia/Bangkok')->addDay()->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(3)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $tomorrow,
                    'check_out' => $checkOut,
                    'guests' => [['title' => 'mr', 'name' => 'Spoofer', 'nationality' => 'TH']],
                ],
            ],
        ]);

        // Still rejected because Sanctum user role !== 'admin'
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms.0.check_in']);
        $this->assertEquals(
            'วันที่เช็คอินต้องจองล่วงหน้าอย่างน้อย 2 วันค่ะ',
            $response->json('errors')['booking_rooms.0.check_in'][0]
        );
    }

    // ============================================
    // Path 2: POST /api/v1/bookings/{id}/rooms (Add rooms)
    // ============================================

    public function test_non_admin_cannot_add_rooms_earlier_than_two_days_advance(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType);

        $tomorrow = Carbon::now('Asia/Bangkok')->addDay()->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(3)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/bookings/{$booking->id}/rooms", [
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $tomorrow,
                    'check_out' => $checkOut,
                    'guests' => [['title' => 'mr', 'name' => 'New Room', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms.0.check_in']);
        $this->assertEquals(
            'วันที่เช็คอินต้องจองล่วงหน้าอย่างน้อย 2 วันค่ะ',
            $response->json('errors')['booking_rooms.0.check_in'][0]
        );
    }

    public function test_non_admin_can_add_rooms_two_days_in_advance(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType);

        $twoDaysAdvance = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/v1/bookings/{$booking->id}/rooms", [
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $twoDaysAdvance,
                    'check_out' => $checkOut,
                    'guests' => [['title' => 'mr', 'name' => 'New Room', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    }

    // ============================================
    // Path 3: PUT /api/v1/bookings/{id}/rooms/{roomId} (Single room update)
    // ============================================

    public function test_non_admin_cannot_update_single_room_to_tomorrow(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        $tomorrow = Carbon::now('Asia/Bangkok')->addDay()->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(3)->toDateString();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'check_in' => $tomorrow,
                'check_out' => $checkOut,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['check_in']);
        $this->assertEquals(
            'วันที่เช็คอินต้องจองล่วงหน้าอย่างน้อย 2 วันค่ะ',
            $response->json('errors.check_in.0')
        );
    }

    public function test_non_admin_can_update_single_room_with_two_days_advance(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        $twoDaysAdvance = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(5)->toDateString();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'check_in' => $twoDaysAdvance,
                'check_out' => $checkOut,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    }

    // ============================================
    // Path 4: PUT /api/v1/bookings/{id}/rooms (Batch update rooms)
    // ============================================

    public function test_non_admin_cannot_batch_update_rooms_to_tomorrow(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        $tomorrow = Carbon::now('Asia/Bangkok')->addDay()->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(3)->toDateString();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $br->id,
                        'check_in' => $tomorrow,
                        'check_out' => $checkOut,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms.0.check_in']);
        $this->assertEquals(
            'วันที่เช็คอินต้องจองล่วงหน้าอย่างน้อย 2 วันค่ะ',
            $response->json('errors')['booking_rooms.0.check_in'][0]
        );
    }

    public function test_non_admin_can_batch_update_rooms_with_two_days_advance(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        $twoDaysAdvance = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(5)->toDateString();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $br->id,
                        'check_in' => $twoDaysAdvance,
                        'check_out' => $checkOut,
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    }

    // ============================================
    // Edge case: Grandfathered Drafts
    // ============================================

    public function test_grandfathered_draft_with_short_advance_can_be_read_normally(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // Grandfathered draft created previously with check_in = tomorrow (+1 day)
        $booking = Booking::create([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 3000,
            'payment_deadline' => now()->addHours(24),
        ]);

        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => null,
            'check_in' => Carbon::now('Asia/Bangkok')->addDay()->toDateString(),
            'check_out' => Carbon::now('Asia/Bangkok')->addDays(3)->toDateString(),
            'status' => 'draft',
            'guests' => [
                ['title' => 'mr', 'name' => 'Grandfathered Guest', 'nationality' => 'TH'],
            ],
        ]);

        // GET endpoint must succeed (no validation on read path)
        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/bookings/{$booking->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    }

    // ============================================
    // Config Overrides
    // ============================================

    public function test_config_overrides_min_advance_days(): void
    {
        config(['booking.min_advance_days' => 3]);

        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // 2 days in advance fails when config is 3
        $twoDays = Carbon::now('Asia/Bangkok')->addDays(2)->toDateString();
        $checkOut = Carbon::now('Asia/Bangkok')->addDays(4)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $twoDays,
                    'check_out' => $checkOut,
                    'guests' => [['title' => 'mr', 'name' => 'User', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertEquals(
            'วันที่เช็คอินต้องจองล่วงหน้าอย่างน้อย 3 วันค่ะ',
            $response->json('errors')['booking_rooms.0.check_in'][0]
        );

        // 3 days in advance passes
        $threeDays = Carbon::now('Asia/Bangkok')->addDays(3)->toDateString();
        $checkOut3 = Carbon::now('Asia/Bangkok')->addDays(5)->toDateString();

        $passResponse = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $threeDays,
                    'check_out' => $checkOut3,
                    'guests' => [['title' => 'mr', 'name' => 'User', 'nationality' => 'TH']],
                ],
            ],
        ]);

        $passResponse->assertStatus(201);
    }
}
