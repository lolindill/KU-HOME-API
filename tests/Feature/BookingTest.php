<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private function createRoomType(): RoomType
    {
        return RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Double',
            'name_th' => 'สแตนดาร์ด ดับเบิล',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
            'rate_daily_general' => 1500,
        ]);
    }

    private function createRoom(RoomType $roomType, string $status = 'available'): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '10' . rand(1, 99),
            'status' => $status,
        ]);
    }

    /**
     * 🌟 Refactor (18/06/26): Guest fields ย้ายไป booking_rooms แล้ว
     * ตอนนี้ Booking มีแค่ข้อมูลการจอง + user_id (คนจอง)
     * ข้อมูลผู้เข้าพักเก็บใน booking_rooms.guests (JSON) + booking_rooms.children
     */
    private function createBooking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        $booking = Booking::create(array_merge([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'total_amount' => 4500,
        ], $overrides));

        // สร้าง booking_room พร้อมข้อมูลผู้เข้าพัก
        $roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard',
            'name_th' => 'สแตนดาร์ด',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
            'rate_daily_general' => 1500,
        ]);

        \App\Models\BookingRoom::create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'guests' => [
                ['title' => 'mr', 'name' => 'Test Guest', 'nationality' => 'TH'],
            ],
            'children' => 0,
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
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'quantity' => 1,
                    'guests' => [
                        ['title' => 'mr', 'name' => 'Ghost', 'nationality' => 'TH'],
                    ],
                    'children' => 0,
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
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(3)->toDateString(),
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'quantity' => 1,
                        'guests' => [
                            ['title' => 'mr', 'name' => $user->name, 'nationality' => 'TH'],
                        ],
                        'children' => 0,
                    ],
                ],
            ]);

        $response->assertStatus(201);
        // 🌟 ตรวจว่าข้อมูลผู้เข้าพักถูกเก็บใน booking_rooms ไม่ใช่ bookings
        $this->assertDatabaseHas('booking_rooms', [
            'children' => 0,
        ]);
    }

    // ============================================
    // ✅ Get own bookings
    // ============================================

    public function test_authenticated_user_can_get_own_bookings(): void
    {
        $user = User::factory()->create();
        $this->createBooking(['user_id' => $user->id]);
        $this->createBooking(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/bookings');
        $response->assertStatus(200);
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

        $response = $this->putJson("/api/v1/bookings/update/{$booking->id}", [
            'status' => 'confirmed', // draft → confirmed is invalid
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
                'check_in' => now()->addDays(5)->toDateString(),
                'check_out' => now()->addDay()->toDateString(), // check_out before check_in
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'quantity' => 1,
                        'guests' => [
                            ['title' => 'mr', 'name' => 'Test', 'nationality' => 'TH'],
                        ],
                        'children' => 0,
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
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(3)->toDateString(),
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'quantity' => 1,
                        'guests' => [
                            ['title' => 'mr', 'name' => 'Spammer', 'nationality' => 'TH'],
                        ],
                        'children' => 0,
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
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(3)->toDateString(),
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'quantity' => 1,
                        'guests' => [
                            ['title' => 'mr', 'name' => 'Expired Guest', 'nationality' => 'TH'],
                        ],
                        'children' => 0,
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
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(3)->toDateString(),
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'quantity' => 1,
                        'guests' => [
                            ['title' => 'mr', 'name' => 'User B', 'nationality' => 'TH'],
                        ],
                        'children' => 0,
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
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByAction(
            'App\Http\Controllers\Api\V1\BookingController@createBooking'
        );

        $this->assertNotNull($route, 'Route for createBooking should exist');
        $this->assertContains('throttle:5,1', $route->gatherMiddleware(),
            'POST /bookings should have throttle:5,1 middleware');
    }

    // 🌟 Refactor (18/06/26): lookup & requestPaymentForGuest routes ถูกลบแล้ว — ไม่มี public guest access
}