<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingTest extends TestCase
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
        // 🌟 Refactor (22/07/26): rate_daily_general ย้ายไป global_rates แล้ว — seed ที่นี่
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

    private function createRoom(RoomType $roomType, string $status = 'available'): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '10'.rand(1, 99),
            'status' => $status,
        ]);
    }

    /**
     * 🌟 Refactor (18/06/26): Guest fields ย้ายไป booking_rooms แล้ว
     * ตอนนี้ Booking มีแค่ข้อมูลการจอง + user_id (คนจอง)
     * ข้อมูลผู้เข้าพักเก็บใน booking_rooms.guests (JSON) + booking_rooms.has_children
     */
    private function createBooking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        // 🌟 Refactor (25/06/26): bookings ไม่มี check_in/check_out แล้ว — ย้ายไป BR-level
        $booking = Booking::create(array_merge([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 4500,
        ], $overrides));

        // สร้าง booking_room พร้อมข้อมูลผู้เข้าพัก + check_in/check_out
        $roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard',
            'name_th' => 'สแตนดาร์ด',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        // 🌟 Refactor (22/07/26): seed room rate ใน global_rates
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $roomType->id,
            'code' => null,
            'name_en' => 'Standard Daily',
            'default_price' => 1500,
            'is_active' => true,
        ]);

        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'guests' => [
                ['title' => 'mr', 'name' => 'Test Guest', 'nationality' => 'TH'],
            ],
            'has_children' => false,
            'rate_daily' => 1500,
            'nights' => 2,
        ]);

        return $booking->fresh(['bookingRooms']);
    }

    // ============================================
    // ✅ Create Booking (MUST be authenticated — guest/non-member cannot)
    // ============================================

    public function test_unauthenticated_user_cannot_create_booking(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $response = $this->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => now()->addDay()->toDateString(),
                    'check_out' => now()->addDays(3)->toDateString(),
                    'guests' => [
                        ['title' => 'mr', 'name' => 'Ghost', 'nationality' => 'TH'],
                    ],
                    'has_children' => false,
                ],
            ],
        ]);

        // 🌟 ไม่ login → 401
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_create_booking(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                        'guests' => [
                            ['title' => 'mr', 'name' => $user->name, 'nationality' => 'TH'],
                        ],
                        'has_children' => false,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        // 🛡️ Scrutinize: Verify booking linkage + total_amount calculated server-side
        $this->assertDatabaseHas('bookings', [
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
        ]);

        $booking = Booking::where('user_id', $user->id)->latest('created_at')->first();
        $this->assertNotNull($booking, 'Booking should be created');

        // 🛡️ Verify total_amount calculated server-side (2 nights × 1500 = 3000)
        $this->assertEquals(3000, $booking->total_amount,
            'Total amount must be calculated server-side, not trusted from client');

        // 🛡️ Verify booking_rooms with correct room_type linkage + guests JSON
        $this->assertDatabaseHas('booking_rooms', [
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'has_children' => false,
        ]);

        $bookingRoom = BookingRoom::where('booking_id', $booking->id)->first();
        $guests = is_string($bookingRoom->guests) ? json_decode($bookingRoom->guests, true) : $bookingRoom->guests;
        $this->assertEquals($user->name, $guests[0]['name'] ?? null,
            'Guest name must be stored in booking_rooms.guests JSON, not in bookings table');
    }

    // ============================================
    // ✅ Get own bookings
    // ============================================

    public function test_authenticated_user_can_get_own_bookings(): void
    {
        $user = User::factory()->create();
        $bookingA = $this->createBooking(['user_id' => $user->id]);
        $bookingB = $this->createBooking(['user_id' => $user->id]);

        // 🛡️ Noise booking from different user — must NOT appear in results
        $this->createBooking();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings');
        $response->assertStatus(200);

        // 🛡️ Scrutinize: Verify user only sees THEIR bookings (no cross-user leak)
        $bookingIds = collect($response->json('bookings'))->pluck('id');
        $this->assertContains($bookingA->id, $bookingIds, 'User should see their own booking A');
        $this->assertContains($bookingB->id, $bookingIds, 'User should see their own booking B');
        $this->assertCount(2, $bookingIds,
            'User should see exactly 2 bookings — other users\' bookings must be filtered out');
    }

    // ============================================
    // ✅ Admin: Update booking status
    // ============================================

    public function test_admin_can_update_booking_status(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'draft']);

        $response = $this->putJson("/api/v1/bookings/update/{$booking->id}", [
            'status' => 'paid',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    public function test_admin_cannot_do_invalid_status_transition(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'draft']);

        // 🌟 Refactor (25/06/26): draft → confirmed is now VALID (admin walk-in skip paid)
        // ต้องใช้ transition ที่ invalid จริงๆ ตาม container state machine
        // draft → complete ข้ามขั้น paid/confirmed ไม่ได้ค่ะ
        $response = $this->putJson("/api/v1/bookings/update/{$booking->id}", [
            'status' => 'complete',
        ]);
        $response->assertStatus(422);
    }

    // ============================================
    // ✅ Admin: Booking search (merged into GET /bookings?term=)
    // ============================================

    public function test_admin_can_search_bookings_with_term(): void
    {
        $this->actingAsAdmin();
        // 🌟 ค้นหาด้วยชื่อใน booking_rooms แทน (guests JSON)
        $this->createBooking();
        $this->createBooking();

        $response = $this->getJson('/api/v1/bookings?term=Test');
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    }

    // ============================================
    // ✅ Booking validation
    // ============================================

    public function test_create_booking_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', []);
        $response->assertStatus(422);
    }

    public function test_create_booking_validates_check_out_after_check_in(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDays(5)->toDateString(),
                        'check_out' => now()->addDay()->toDateString(), // check_out before check_in
                        'guests' => [
                            ['title' => 'mr', 'name' => 'Test', 'nationality' => 'TH'],
                        ],
                        'has_children' => false,
                    ],
                ],
            ]);
        $response->assertStatus(422);
    }

    // ============================================
    // ✅ Draft Prevention (now by user_id, not email)
    // ============================================

    public function test_user_cannot_create_booking_with_active_draft(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // 🛑 สร้าง Draft ที่ยังไม่หมดอายุไว้ก่อน
        $this->createBooking([
            'user_id' => $user->id,
            'status' => 'draft',
            'payment_deadline' => now()->addHours(12), // ยังไม่หมด
        ]);

        // 🚫 พยายามสร้างใหม่ด้วย user เดียวกัน → ต้องโดนปฏิเสธ
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                        'guests' => [
                            ['title' => 'mr', 'name' => 'Spammer', 'nationality' => 'TH'],
                        ],
                        'has_children' => false,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);
    }

    public function test_user_can_create_booking_after_draft_expired(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // 🕐 สร้าง Draft ที่หมดอายุแล้ว
        $this->createBooking([
            'user_id' => $user->id,
            'status' => 'draft',
            'payment_deadline' => now()->subHours(1), // หมดอายุแล้ว
        ]);

        // ✅ สร้างใหม่ด้วย user เดียวกัน → ต้องผ่าน
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                        'guests' => [
                            ['title' => 'mr', 'name' => 'Expired Guest', 'nationality' => 'TH'],
                        ],
                        'has_children' => false,
                    ],
                ],
            ]);

        $response->assertStatus(201);
    }

    public function test_different_users_can_create_booking_independently(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // 🛑 มี Draft ของ User A ที่ยังไม่หมดอายุ
        $this->createBooking([
            'status' => 'draft',
            'payment_deadline' => now()->addHours(12),
        ]);

        // ✅ User B ต่างคนต่างสร้างได้ปกติ
        $userB = User::factory()->create();
        $response = $this->actingAs($userB, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                        'guests' => [
                            ['title' => 'mr', 'name' => 'User B', 'nationality' => 'TH'],
                        ],
                        'has_children' => false,
                    ],
                ],
            ]);

        $response->assertStatus(201);
    }

    // ============================================
    // ✅ Rate Limiting on Booking Routes
    // ============================================

    public function test_create_booking_route_has_rate_limiting(): void
    {
        $route = Route::getRoutes()->getByAction(
            'App\Http\Controllers\Api\V1\BookingController@createBooking'
        );

        $this->assertNotNull($route, 'Route for createBooking should exist');
        $this->assertContains('throttle:5,1', $route->gatherMiddleware(),
            'POST /bookings should have throttle:5,1 middleware');
    }

    // 🌟 Refactor (18/06/26): lookup & requestPaymentForGuest routes ถูกลบแล้ว — ไม่มี public guest access
}
