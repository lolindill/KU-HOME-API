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

    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'status' => 'draft',
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.com',
            'guest_phone' => '0812345678',
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'total_amount' => 4500,
        ], $overrides));
    }

    // ============================================
    // ✅ Create Booking (guest)
    // ============================================

    public function test_guest_can_create_booking(): void
    {
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);

        $response = $this->postJson('/api/v1/bookings', [
            'source' => 'online',
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'guest_name' => 'New Guest',
            'guest_email' => 'newguest@example.com',
            'guest_phone' => '0899999999',
            'booking_rooms' => [
                ['room_type_id' => $roomType->id, 'quantity' => 1],
            ],
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('bookings', ['guest_email' => 'newguest@example.com']);
    }

    // ============================================
    // ✅ Create Booking (authenticated)
    // ============================================

    public function test_authenticated_user_can_create_booking(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(3)->toDateString(),
                'guest_name' => $user->name,
                'guest_email' => $user->email,
                'guest_phone' => '0899999999',
                'booking_rooms' => [
                    ['room_type_id' => $roomType->id, 'quantity' => 1],
                ],
            ]);
        $response->assertStatus(201);
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
    // ✅ Lookup Booking
    // ============================================

    public function test_lookup_booking_by_confirmation_and_email(): void
    {
        $booking = $this->createBooking(['confirmation' => 'ABC123']);

        $response = $this->postJson('/api/v1/bookings/lookup', [
            'confirmation' => 'ABC123',
            'guest_email' => 'guest@example.com',
        ]);
        $response->assertStatus(200);
    }

    public function test_lookup_booking_fails_with_wrong_confirmation(): void
    {
        $response = $this->postJson('/api/v1/bookings/lookup', [
            'confirmation' => 'WRONG',
            'guest_email' => 'guest@example.com',
        ]);
        $response->assertStatus(404);
    }

    public function test_lookup_booking_fails_with_wrong_email(): void
    {
        $this->createBooking(['confirmation' => 'ABC123']);

        $response = $this->postJson('/api/v1/bookings/lookup', [
            'confirmation' => 'ABC123',
            'guest_email' => 'wrong@example.com',
        ]);
        $response->assertStatus(404);
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
        $this->createBooking(['guest_name' => 'John Doe']);
        $this->createBooking(['guest_name' => 'Jane Smith']);

        $response = $this->getJson('/api/v1/bookings?term=John');
        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('pagination.total', 1);
    }

    // ============================================
    // ✅ Booking validation
    // ============================================

    public function test_create_booking_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/bookings', []);
        $response->assertStatus(422);
    }

    public function test_create_booking_validates_check_out_after_check_in(): void
    {
        $roomType = $this->createRoomType();
        $response = $this->postJson('/api/v1/bookings', [
            'source' => 'online',
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDay()->toDateString(), // check_out before check_in
            'guest_name' => 'Test',
            'guest_email' => 'test@example.com',
            'guest_phone' => '0812345678',
            'booking_rooms' => [
                ['room_type_id' => $roomType->id, 'quantity' => 1],
            ],
        ]);
        $response->assertStatus(422);
    }

    // ============================================
    // ✅ Guest Draft Prevention
    // ============================================

    public function test_guest_cannot_create_booking_with_active_draft(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // 🛑 สร้าง Draft ที่ยังไม่หมดอายุไว้ก่อน
        $this->createBooking([
            'guest_email' => 'spam@example.com',
            'status' => 'draft',
            'payment_deadline' => now()->addHours(12), // ยังไม่หมด
        ]);

        // 🚫 พยายามสร้างใหม่ด้วย email เดียวกัน → ต้องโดนปฏิเสธ
        $response = $this->postJson('/api/v1/bookings', [
            'source' => 'online',
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'guest_name' => 'Spammer',
            'guest_email' => 'spam@example.com',
            'guest_phone' => '0812345678',
            'booking_rooms' => [
                ['room_type_id' => $roomType->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);
    }

    public function test_guest_can_create_booking_after_draft_expired(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // 🕐 สร้าง Draft ที่หมดอายุแล้ว
        $this->createBooking([
            'guest_email' => 'expired@example.com',
            'status' => 'draft',
            'payment_deadline' => now()->subHours(1), // หมดอายุแล้ว
        ]);

        // ✅ สร้างใหม่ด้วย email เดียวกัน → ต้องผ่าน
        $response = $this->postJson('/api/v1/bookings', [
            'source' => 'online',
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'guest_name' => 'Expired Guest',
            'guest_email' => 'expired@example.com',
            'guest_phone' => '0812345678',
            'booking_rooms' => [
                ['room_type_id' => $roomType->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
    }

    public function test_guest_different_email_can_create_booking_independently(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // 🛑 มี Draft ของ email A ที่ยังไม่หมดอายุ
        $this->createBooking([
            'guest_email' => 'user_a@example.com',
            'status' => 'draft',
            'payment_deadline' => now()->addHours(12),
        ]);

        // ✅ email B ต่างคนต่างสร้างได้ปกติ
        $response = $this->postJson('/api/v1/bookings', [
            'source' => 'online',
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'guest_name' => 'User B',
            'guest_email' => 'user_b@example.com',
            'guest_phone' => '0812345678',
            'booking_rooms' => [
                ['room_type_id' => $roomType->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
    }

    // ============================================
    // ✅ Rate Limiting on Public Booking Routes
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

    public function test_booking_lookup_route_has_rate_limiting(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByAction(
            'App\Http\Controllers\Api\V1\BookingController@lookupBooking'
        );

        $this->assertNotNull($route, 'Route for lookupBooking should exist');
        $this->assertContains('throttle:10,1', $route->gatherMiddleware(),
            'POST /bookings/lookup should have throttle:10,1 middleware');
    }

    public function test_guest_payment_request_route_has_rate_limiting(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByAction(
            'App\Http\Controllers\Api\V1\PaymentController@requestPaymentForGuest'
        );

        $this->assertNotNull($route, 'Route for requestPaymentForGuest should exist');
        $this->assertContains('throttle:5,1', $route->gatherMiddleware(),
            'POST /bookings/{id}/request-payment should have throttle:5,1 middleware');
    }
}
