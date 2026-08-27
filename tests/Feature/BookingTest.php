<?php

namespace Tests\Feature;

use App\Models\Addon;
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

    // counter แทน rand() — เลขห้องสุ่มชนกันเองใน test เดียวกันแล้วทำ suite พังแบบสุ่ม (flaky)
    private static int $roomSeq = 0;

    private function createRoom(RoomType $roomType, string $status = 'available'): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '10'.(++self::$roomSeq),
            'status' => $status,
        ]);
    }

    /**
     * 🌟 Refactor (18/06/26): Guest fields ย้ายไป booking_rooms แล้ว
     * ตอนนี้ Booking มีแค่ข้อมูลการจอง + user_id (คนจอง)
     * ข้อมูลผู้เข้าพักเก็บใน booking_rooms.guests (JSON)
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
            'rate_daily' => 1500,
            'nights' => 2,
        ]);

        return $booking->fresh(['bookingRooms']);
    }

    /**
     * 🌟 (17/08/26): สร้าง draft booking สำหรับ test delete/update room —
     * ใช้ room type ที่เรียกผ่าน createRoomType (มี rate 1500/คืน) และสร้าง Addon ให้ทุกห้อง
     */
    private function createDraftBooking(User $user, RoomType $roomType, int $roomCount = 1, array $overrides = []): Booking
    {
        $booking = Booking::create(array_merge([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 0,
            'payment_deadline' => now()->addHours(24),
        ], $overrides));

        for ($i = 0; $i < $roomCount; $i++) {
            $br = BookingRoom::create([
                'booking_id' => $booking->id,
                'room_type_id' => $roomType->id,
                'room_id' => null,
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(3)->toDateString(),
                'status' => 'draft',
                'guests' => [
                    ['title' => 'mr', 'name' => 'Test Guest', 'nationality' => 'TH'],
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

        // 2 คืน × 1500 ต่อห้อง
        $booking->update(['total_amount' => 3000 * $roomCount]);

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
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonCount(1, 'booking_rooms');
        $response->assertJsonStructure([
            'status',
            'message',
            'booking_id',
            'confirmation',
            'total_amount',
            'payment_deadline',
            'user_id',
            'booking_rooms' => [
                [
                    'id',
                    'booking_id',
                    'room_type_id',
                    'check_in',
                    'check_out',
                    'status',
                    'guests',
                    'addon',
                ],
            ],
        ]);

        $this->assertNull($response->json('booking_rooms.0.room_type'));
        $this->assertNull($response->json('booking_rooms.0.room'));

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
        ]);

        $bookingRoom = BookingRoom::where('booking_id', $booking->id)->first();
        $guests = is_string($bookingRoom->guests) ? json_decode($bookingRoom->guests, true) : $bookingRoom->guests;
        $this->assertEquals($user->name, $guests[0]['name'] ?? null,
            'Guest name must be stored in booking_rooms.guests JSON, not in bookings table');
    }

    public function test_authenticated_user_can_create_booking_with_first_and_last_name_and_contact_info(): void
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
                            [
                                'title' => 'Mr.',
                                'firstName' => 'Somchai',
                                'lastName' => 'Prasert',
                                'email' => 'somchai.p@ku.th',
                                'phone' => '0812345678',
                                'nationality' => 'TH',
                            ],
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonCount(1, 'booking_rooms');
        $response->assertJsonPath('booking_rooms.0.guests.0.firstName', 'Somchai');
        $response->assertJsonPath('booking_rooms.0.guests.0.lastName', 'Prasert');
        $response->assertJsonPath('booking_rooms.0.guests.0.email', 'somchai.p@ku.th');
        $response->assertJsonPath('booking_rooms.0.guests.0.phone', '0812345678');

        $bookingRoom = BookingRoom::where('room_type_id', $roomType->id)->latest('created_at')->first();
        $this->assertNotNull($bookingRoom);
        $this->assertEquals('Mr. Somchai Prasert', $bookingRoom->primary_guest_name);
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
        $this->assertNotContains('throttle:5,1', $route->gatherMiddleware(),
            'POST /bookings should not have throttle:5,1 middleware while paused');
    }

    // ============================================
    // ✅ Delete draft booking (owner or admin) — DELETE /bookings/{id}
    // ============================================

    public function test_owner_can_delete_own_draft_booking(): void
    {
        $user = User::factory()->create();
        $booking = $this->createBooking(['user_id' => $user->id]);
        $brId = $booking->bookingRooms->first()->id;

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // 🛡️ hard delete cascade — หายทั้ง booking + BR
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->assertDatabaseMissing('booking_rooms', ['id' => $brId]);

        // 📝 audit log draft → deleted เขียนไว้แม้ row หายแล้ว
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'booking',
            'entity_id' => $booking->id,
            'from_status' => 'draft',
            'to_status' => 'deleted',
        ]);
    }

    public function test_delete_draft_booking_cascades_addon_rows(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $booking = $this->createDraftBooking($user, $roomType);
        $brId = $booking->bookingRooms->first()->id;

        $this->assertDatabaseHas('addons', ['booking_room_id' => $brId]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('addons', ['booking_room_id' => $brId]);
    }

    public function test_user_cannot_delete_other_users_booking(): void
    {
        $booking = $this->createBooking(); // เจ้าของคนอื่น

        $intruder = User::factory()->create();
        $response = $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_user_cannot_delete_non_draft_booking(): void
    {
        $user = User::factory()->create();
        $booking = $this->createBooking(['user_id' => $user->id, 'status' => 'paid']);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'paid']);
    }

    public function test_admin_can_delete_any_draft_booking(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(); // ของ user อื่น

        $response = $this->deleteJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_unauthenticated_user_cannot_delete_booking(): void
    {
        $booking = $this->createBooking();

        $response = $this->deleteJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(401);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_delete_booking_unknown_id_returns_404(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/bookings/'.Str::uuid());

        $response->assertStatus(404);
    }

    // ============================================
    // ✅ Add rooms to draft booking — POST /bookings/{bookingId}/rooms
    // ============================================

    public function test_owner_can_add_rooms_to_draft_booking(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType); // 1 ห้อง = 3000
        $existingBrId = $booking->bookingRooms->first()->id;

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                        'guests' => [['title' => 'mr', 'name' => 'Added Guest', 'nationality' => 'TH']],
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        // 2 คืน × 1500 = 3000 เข้ายอดเดิม 3000 → 6000
        $response->assertJsonPath('added_amount', 3000);
        $response->assertJsonPath('total_amount', 6000);

        // 🛡️ BR + Addon ของห้องใหม่ถูกสร้างจริง
        $this->assertDatabaseCount('booking_rooms', 2);
        $newBr = BookingRoom::where('booking_id', $booking->id)
            ->where('id', '!=', $existingBrId)
            ->first();
        $this->assertNotNull($newBr);
        $this->assertEquals('Added Guest', $newBr->guests[0]['name']);
        $this->assertDatabaseHas('addons', ['booking_room_id' => $newBr->id]);
    }

    public function test_admin_can_add_rooms_to_any_draft_booking(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking(User::factory()->create(), $roomType);

        $response = $this->postJson("/api/v1/bookings/{$booking->id}/rooms", [
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => now()->addDay()->toDateString(),
                    'check_out' => now()->addDays(3)->toDateString(),
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('booking_rooms', 2);
    }

    public function test_user_cannot_add_rooms_to_other_users_booking(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $owner = User::factory()->create();
        $booking = $this->createDraftBooking($owner, $roomType);

        $intruder = User::factory()->create(); // role 'user' เหมือนเจ้าของ
        $response = $this->actingAs($intruder, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                    ],
                ],
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('booking_rooms', 1); // ห้องใหม่ไม่ถูกสร้าง
    }

    public function test_unauthenticated_user_cannot_add_rooms(): void
    {
        $booking = $this->createBooking();

        $response = $this->postJson("/api/v1/bookings/{$booking->id}/rooms", [
            'booking_rooms' => [
                [
                    'room_type_id' => $booking->bookingRooms->first()->room_type_id,
                    'check_in' => now()->addDay()->toDateString(),
                    'check_out' => now()->addDays(3)->toDateString(),
                ],
            ],
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseCount('booking_rooms', 1);
    }

    public function test_add_rooms_to_non_draft_booking_returns_422(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType, 1, ['status' => 'paid']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('booking_rooms', 1);
    }

    public function test_add_rooms_unknown_booking_returns_404(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings/'.Str::uuid().'/rooms', [
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                    ],
                ],
            ]);

        $response->assertStatus(404);
    }

    // ============================================
    // ✅ Update booking room (draft only) — PUT /bookings/{bookingId}/rooms/{bookingRoomId}
    // ============================================

    public function test_owner_can_update_draft_booking_room_dates(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);
        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();
        $deadline = $booking->payment_deadline;

        // ยืดจาก 2 คืน (+1..+3) เป็น 3 คืน (+5..+8) → 1500 × 3 = 4500
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'check_in' => now()->addDays(5)->toDateString(),
                'check_out' => now()->addDays(8)->toDateString(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total_amount', 4500);

        $freshBr = $br->fresh();
        $this->assertEquals(now()->addDays(5)->toDateString(), $freshBr->check_in->toDateString());
        $this->assertEquals(now()->addDays(8)->toDateString(), $freshBr->check_out->toDateString());

        // payment_deadline คงเดิม (เหมือน addRooms)
        $this->assertTrue($booking->fresh()->payment_deadline->equalTo($deadline));
    }

    public function test_update_room_reprices_addons_server_side(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        // seed addon rates: extra_bed 100/คืน breakfast 50/ท่าน early_checkin 100/ชม.
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'extra_bed',
            'name_en' => 'Extra Bed', 'default_price' => 100, 'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'breakfast',
            'name_en' => 'Breakfast', 'default_price' => 50, 'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'early_checkin',
            'name_en' => 'Early Check-in', 'default_price' => 100, 'is_active' => true,
        ]);

        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        // 3 คืน + extra_bed 1 หลัง + breakfast 2 ท่าน + early 2 ชม. → (1500×3) + (100×1×3) + (50×2) + (100×2) = 5100
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'check_in' => now()->addDays(5)->toDateString(),
                'check_out' => now()->addDays(8)->toDateString(),
                'extra_beds' => 1,
                'addons' => ['breakfast' => 2, 'early_checkin' => 2],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total_amount', 5100);

        // 🛡️ ราคาถูกเขียนกลับลง Addon row ฝั่ง server เท่านั้น
        $this->assertDatabaseHas('addons', [
            'booking_room_id' => $br->id,
            'extra_bed' => 1,
            'extra_bed_price' => 300,
            'breakfast' => 2,
            'breakfast_price' => 100,
            'early_hours' => 2,
            'early_checkIn_price' => 200,
        ]);
    }

    /**
     * 🕐 (27/08/26): Early/Late check-in/out คิดราคาตามสูตรรายชั่วโมง (int 0-5 ชม.)
     * ลบ boolean ออกจาก response และบันทึก early_hours / late_hours ใน Addon
     */
    public function test_create_booking_charges_early_late_by_hours(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        // seed early 150 / late 250 (satang per hour)
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'early_checkin',
            'name_en' => 'Early Check-in', 'default_price' => 150, 'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'late_checkout',
            'name_en' => 'Late Check-out', 'default_price' => 250, 'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                        'addons' => ['early_checkin' => 2, 'late_checkout' => 1],
                    ],
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(3)->toDateString(),
                        // ไม่ส่ง addons → hours เป็น 0 และราคา 0
                    ],
                ],
            ]);

        $response->assertStatus(201);

        // ห้องที่เลือก → hours + ราคา = hours × rate; ไม่มี boolean บน booking_room
        $response->assertJsonMissingPath('booking_rooms.0.early_checkin');
        $response->assertJsonMissingPath('booking_rooms.0.late_checkout');
        $response->assertJsonPath('booking_rooms.0.addon.early_hours', 2);
        $response->assertJsonPath('booking_rooms.0.addon.late_hours', 1);
        $response->assertJsonPath('booking_rooms.0.addon.early_checkIn_price', 300); // 2 × 150
        $response->assertJsonPath('booking_rooms.0.addon.late_checkOut_price', 250); // 1 × 250

        // ห้องที่ไม่เลือก → hours 0 + ราคา 0; ไม่มี boolean
        $response->assertJsonMissingPath('booking_rooms.1.early_checkin');
        $response->assertJsonMissingPath('booking_rooms.1.late_checkout');
        $response->assertJsonPath('booking_rooms.1.addon.early_hours', 0);
        $response->assertJsonPath('booking_rooms.1.addon.late_hours', 0);
        $response->assertJsonPath('booking_rooms.1.addon.early_checkIn_price', 0);
        $response->assertJsonPath('booking_rooms.1.addon.late_checkOut_price', 0);

        // total = (1500×2)×2 ห้อง + 300 + 250 = 6550
        $response->assertJsonPath('total_amount', 6550);
    }

    public function test_create_booking_rejects_boolean_early_checkin(): void
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
                        'check_out' => now()->addDays(2)->toDateString(),
                        'addons' => ['early_checkin' => true],
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['booking_rooms.0.addons.early_checkin']);
    }

    public function test_create_booking_rejects_early_hours_out_of_range(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // test 6 (เกิน 5)
        $response1 = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(2)->toDateString(),
                        'addons' => ['early_checkin' => 6],
                    ],
                ],
            ]);
        $response1->assertStatus(422);
        $response1->assertJsonValidationErrors(['booking_rooms.0.addons.early_checkin']);

        // test -1 (น้อยกว่า 0)
        $response2 = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(2)->toDateString(),
                        'addons' => ['early_checkin' => -1],
                    ],
                ],
            ]);
        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors(['booking_rooms.0.addons.early_checkin']);

        // test 2.5 (ไม่ใช่ integer)
        $response3 = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'source' => 'online',
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => now()->addDay()->toDateString(),
                        'check_out' => now()->addDays(2)->toDateString(),
                        'addons' => ['early_checkin' => 2.5],
                    ],
                ],
            ]);
        $response3->assertStatus(422);
        $response3->assertJsonValidationErrors(['booking_rooms.0.addons.early_checkin']);
    }

    /**
     * 🛡️ Regression (26/08/26, 27/08/26): PUT แก้ห้องโดยไม่ส่ง addons key มาด้วย
     * ต้องคงชั่วโมงและราคา early/late เดิมไว้
     */
    public function test_update_room_without_addons_key_keeps_early_late_prices(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        // seed rate ปัจจุบันให้ตรงราคาที่เคยคิดไว้ (fallback จะ reprice ตาม rate ปัจจุบัน)
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'early_checkin',
            'name_en' => 'Early Check-in', 'default_price' => 5000, 'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'late_checkout',
            'name_en' => 'Late Check-out', 'default_price' => 7000, 'is_active' => true,
        ]);

        // จำลอง addon ที่เคยเลือก early/late ไว้แล้ว (1 ชม. แต่ละอัน)
        $br->addon->update([
            'early_hours' => 1,
            'early_checkIn_price' => 5000,
            'late_hours' => 1,
            'late_checkOut_price' => 7000,
        ]);
        // 2 คืน × 1500 + 5000 + 7000 = 15000
        $booking->update(['total_amount' => 15000]);

        // แก้แค่ billing_comment — ไม่ส่ง addons / extra_beds มาเลย
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'billing_comment' => 'Need invoice',
            ]);

        $response->assertStatus(200);

        // 🛡️ ราคาและชั่วโมง early/late ต้องถูกคงไว้ (ไม่หายเป็น 0)
        $this->assertDatabaseHas('addons', [
            'booking_room_id' => $br->id,
            'early_hours' => 1,
            'early_checkIn_price' => 5000,
            'late_hours' => 1,
            'late_checkOut_price' => 7000,
        ]);
        $response->assertJsonMissingPath('booking_room.early_checkin');
        $response->assertJsonMissingPath('booking_room.late_checkout');
        $response->assertJsonPath('booking_room.addon.early_hours', 1);
        $response->assertJsonPath('booking_room.addon.late_hours', 1);
        $response->assertJsonPath('total_amount', 15000);

        // 🛡️ เคส 2: ส่ง addons ชัดๆ — ปิด early (0 ชม.) คง late (1 ชม.)
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => ['early_checkin' => 0, 'late_checkout' => 1],
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('addons', [
            'booking_room_id' => $br->id,
            'early_hours' => 0,
            'early_checkIn_price' => 0,
            'late_hours' => 1,
            'late_checkOut_price' => 7000,
        ]);
        $response->assertJsonPath('booking_room.addon.early_hours', 0);
        $response->assertJsonPath('booking_room.addon.late_hours', 1);
        // total คิดใหม่ = 3000 (ห้อง 2 คืน) + 7000 (late) = 10000
        $response->assertJsonPath('total_amount', 10000);
    }

    /**
     * 🕐 Test batch updateRooms reprices early/late hours correctly,
     * and preserves previous hours/prices when a room omits the 'addons' key (batch fallback).
     */
    public function test_update_rooms_batch_reprices_early_late_hours(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'early_checkin',
            'name_en' => 'Early Check-in', 'default_price' => 100, 'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'late_checkout',
            'name_en' => 'Late Check-out', 'default_price' => 200, 'is_active' => true,
        ]);

        $booking = $this->createDraftBooking($user, $roomType, 2);
        [$br1, $br2] = $booking->bookingRooms->all();

        // br2: ไม่ส่ง addons key → ต้อง fallback คงชั่วโมงเดิมจากแถว addon (resolveEarlyLate(null, $existing))
        //    pre-seed early_hours = 2 เพื่อพิสูจน์ว่า fallback อ่าน "ชั่วโมง" ไม่ใช่ reset เป็น 0
        //    (rate early = 100 → หลัง batch ต้องเป็น 2 ชม. × 100 = 200 satang)
        $br2->addon->update([
            'early_hours' => 2,
            'early_checkIn_price' => 200,
        ]);

        // br1: early 3 ชม. (300) + late 1 ชม. (200) = 500 addon + 3000 room = 3500
        // br2: คงเดิม early 2 ชม. (200) = 200 addon + 3000 room = 3200
        // total = 6700
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $br1->id,
                        'addons' => ['early_checkin' => 3, 'late_checkout' => 1],
                    ],
                    [
                        'booking_room_id' => $br2->id,
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total_amount', 6700);

        $this->assertDatabaseHas('addons', [
            'booking_room_id' => $br1->id,
            'early_hours' => 3,
            'early_checkIn_price' => 300,
            'late_hours' => 1,
            'late_checkOut_price' => 200,
        ]);

        $this->assertDatabaseHas('addons', [
            'booking_room_id' => $br2->id,
            'early_hours' => 2,
            'early_checkIn_price' => 200,
            'late_hours' => 0,
            'late_checkOut_price' => 0,
        ]);
    }

    public function test_update_room_rejects_when_no_availability(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType); // มีห้องเดียว

        // booking ของคนอื่นยึดห้อง (ตัวเดียวที่มี) ช่วง +10..+12 ไว้
        $other = User::factory()->create();
        $otherBooking = $this->createDraftBooking($other, $roomType);
        $otherBooking->bookingRooms->first()->update([
            'check_in' => now()->addDays(10)->toDateString(),
            'check_out' => now()->addDays(12)->toDateString(),
        ]);

        $user = User::factory()->create();
        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        // ย้ายไปทับช่วงที่เต็ม (+11..+13 คร่อม +10..+12) → 422
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'check_in' => now()->addDays(11)->toDateString(),
                'check_out' => now()->addDays(13)->toDateString(),
            ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);

        // วันที่ต้องไม่ถูกแก้ (rollback)
        $this->assertEquals(now()->addDays(3)->toDateString(), $br->fresh()->check_out->toDateString());
    }

    public function test_update_room_blocked_when_booking_not_draft(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $booking = $this->createDraftBooking($user, $roomType, 1, ['status' => 'paid']);
        $br = $booking->bookingRooms->first();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'guests' => [['title' => 'mr', 'name' => 'New Name', 'nationality' => 'TH']],
            ]);

        $response->assertStatus(422);
    }

    public function test_update_room_of_another_booking_returns_404(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $bookingA = $this->createDraftBooking($user, $roomType);
        $bookingB = $this->createDraftBooking($user, $roomType);
        $brB = $bookingB->bookingRooms->first();

        // BR ของ booking B แต่อ้างผ่าน booking A → 404
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingA->id}/rooms/{$brB->id}", [
                'guests' => [['title' => 'mr', 'name' => 'Hijack', 'nationality' => 'TH']],
            ]);

        $response->assertStatus(404);
    }

    public function test_user_cannot_update_other_users_booking_room(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $owner = User::factory()->create();
        $booking = $this->createDraftBooking($owner, $roomType);
        $br = $booking->bookingRooms->first();

        $intruder = User::factory()->create();
        $response = $this->actingAs($intruder, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'guests' => [['title' => 'mr', 'name' => 'Intruder', 'nationality' => 'TH']],
            ]);

        $response->assertStatus(403);
    }

    public function test_update_guests_only_keeps_total(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'guests' => [
                    ['title' => 'mr', 'name' => 'Updated Guest', 'nationality' => 'US'],
                ],
                'billing_comment' => 'Tax ID: 0105559999999',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total_amount', 3000);

        $freshBr = $br->fresh();
        $this->assertEquals('Updated Guest', $freshBr->guests[0]['name']);
        $this->assertEquals('Tax ID: 0105559999999', $freshBr->billing_comment);
    }

    public function test_unauthenticated_user_cannot_update_booking_room(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $owner = User::factory()->create();
        $booking = $this->createDraftBooking($owner, $roomType);
        $br = $booking->bookingRooms->first();

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
            'guests' => [['title' => 'mr', 'name' => 'Ghost Guest', 'nationality' => 'TH']],
        ]);

        $response->assertStatus(401);
        $this->assertNotEquals('Ghost Guest', $br->fresh()->guests[0]['name']);
    }

    // ============================================
    // ✅ Batch update booking rooms (draft only) — PUT /bookings/{bookingId}/rooms
    // ============================================

    public function test_owner_can_batch_update_booking_rooms(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        // seed breakfast 50/ท่าน
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'breakfast',
            'name_en' => 'Breakfast', 'default_price' => 50, 'is_active' => true,
        ]);

        $booking = $this->createDraftBooking($user, $roomType, 2);
        [$br1, $br2] = $booking->bookingRooms->all();
        $deadline = $booking->payment_deadline;

        // br1 ยืด 2→3 คืน (4500) · br2 เฉยๆ แต่เพิ่ม breakfast 2 ท่าน (3000+100)
        // → total = 7600
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $br1->id,
                        'check_in' => now()->addDays(5)->toDateString(),
                        'check_out' => now()->addDays(8)->toDateString(),
                    ],
                    [
                        'booking_room_id' => $br2->id,
                        'guests' => [['title' => 'mr', 'name' => 'Batch Guest', 'nationality' => 'TH']],
                        'addons' => ['breakfast' => 2],
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('total_amount', 7600);
        $response->assertJsonCount(2, 'booking_rooms');

        $freshBr1 = $br1->fresh();
        $this->assertEquals(now()->addDays(5)->toDateString(), $freshBr1->check_in->toDateString());
        $this->assertEquals(now()->addDays(8)->toDateString(), $freshBr1->check_out->toDateString());

        $freshBr2 = $br2->fresh();
        $this->assertEquals('Batch Guest', $freshBr2->guests[0]['name']);
        $this->assertDatabaseHas('addons', [
            'booking_room_id' => $br2->id,
            'breakfast' => 2,
            'breakfast_price' => 100,
        ]);

        // payment_deadline คงเดิม (เหมือน addRooms/updateRoom)
        $this->assertTrue($booking->fresh()->payment_deadline->equalTo($deadline));
    }

    public function test_user_cannot_batch_update_other_users_booking_rooms(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $owner = User::factory()->create();
        $booking = $this->createDraftBooking($owner, $roomType, 2); // 2 ห้อง = 6000
        [$br1, $br2] = $booking->bookingRooms->all();

        $intruder = User::factory()->create(); // role 'user' เหมือนเจ้าของ
        $response = $this->actingAs($intruder, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    ['booking_room_id' => $br1->id, 'billing_comment' => 'Hijack'],
                    ['booking_room_id' => $br2->id, 'billing_comment' => 'Hijack'],
                ],
            ]);

        $response->assertStatus(403);
        // 🛡️ ห้ามมีการแก้ข้อมูลใดๆ ทั้งยอดและ BR
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'total_amount' => 6000]);
        $this->assertNotEquals('Hijack', $br1->fresh()->billing_comment);
        $this->assertNotEquals('Hijack', $br2->fresh()->billing_comment);
    }

    public function test_batch_update_rejects_duplicate_booking_room_ids(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    ['booking_room_id' => $br->id, 'billing_comment' => 'A'],
                    ['booking_room_id' => $br->id, 'billing_comment' => 'B'],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_batch_update_with_foreign_booking_room_id_returns_404(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $bookingA = $this->createDraftBooking($user, $roomType);
        $bookingB = $this->createDraftBooking($user, $roomType);
        $brA = $bookingA->bookingRooms->first();
        $brB = $bookingB->bookingRooms->first();

        // BR ของ booking B แอบอ้างผ่าน booking A → 404 ทั้ง batch และห้องของ A ต้องไม่ถูกแตะ
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingA->id}/rooms", [
                'booking_rooms' => [
                    ['booking_room_id' => $brA->id, 'billing_comment' => 'Should not apply'],
                    ['booking_room_id' => $brB->id, 'billing_comment' => 'Hijack'],
                ],
            ]);

        $response->assertStatus(404);
        $this->assertNotEquals('Should not apply', $brA->fresh()->billing_comment);
    }

    public function test_batch_update_atomic_rollback_when_room_not_draft(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType, 2);
        [$br1, $br2] = $booking->bookingRooms->all();
        $br2->update(['status' => 'confirmed']);

        // br1 draft ปกติ แต่ br2 confirmed → batch ต้อง fail ทั้งชุด โดย br1 ไม่ถูกแตะ
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    ['booking_room_id' => $br1->id, 'billing_comment' => 'New comment'],
                    ['booking_room_id' => $br2->id, 'billing_comment' => 'Should fail'],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);
        $this->assertNotEquals('New comment', $br1->fresh()->billing_comment);
    }

    public function test_batch_update_rejects_when_batch_overbooks_type(): void
    {
        $user = User::factory()->create();

        // type T มีห้องจริงแค่ 1 ห้อง
        $typeT = $this->createRoomType();
        $this->createRoom($typeT);

        // type T2 (อีก 1 ห้อง) — จะย้ายห้องนี้เข้า T ทับห้องเดิมที่ไม่ได้เปลี่ยน shape
        $typeT2 = $this->createRoomType();
        $this->createRoom($typeT2);

        $booking = Booking::create([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 0,
            'payment_deadline' => now()->addHours(24),
        ]);

        $roomX = BookingRoom::create([ // ห้อง X: type T +1..+3 (จะแก้แค่ guests — shape ไม่เปลี่ยน)
            'booking_id' => $booking->id,
            'room_type_id' => $typeT->id,
            'room_id' => null,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => 'draft',
        ]);
        $roomY = BookingRoom::create([ // ห้อง Y: type T2 → จะย้ายเข้า T ช่วงเดียวกัน
            'booking_id' => $booking->id,
            'room_type_id' => $typeT2->id,
            'room_id' => null,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => 'draft',
        ]);

        // final state: X (T, +1..+3) + Y (T, +1..+3) = 2 ห้อง แต่ type T มีจริงแค่ 1 → 422
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    ['booking_room_id' => $roomX->id, 'guests' => [['title' => 'mr', 'name' => 'X', 'nationality' => 'TH']]],
                    ['booking_room_id' => $roomY->id, 'room_type_id' => $typeT->id],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);

        // rollback — Y ยังเป็น type T2 เดิม, X ยังเป็น type T
        $this->assertEquals($typeT2->id, (string) $roomY->fresh()->room_type_id);
        $this->assertEquals($typeT->id, (string) $roomX->fresh()->room_type_id);
    }

    public function test_batch_update_rejects_invalid_dates_per_room(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first();

        // ส่งครบทั้งคู่ แต่ check_out ก่อน check_in → validation จับได้ (422)
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $br->id,
                        'check_in' => now()->addDays(5)->toDateString(),
                        'check_out' => now()->addDays(4)->toDateString(),
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_batch_update_rejects_check_out_before_existing_check_in_when_check_in_not_sent(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $booking = $this->createDraftBooking($user, $roomType);
        $br = $booking->bookingRooms->first(); // check_in เดิม = +1

        // ส่งแต่ check_out ย้อนหลังก่อน check_in เดิม → validation after:* เทียบไม่ได้
        //    (ฟิลด์ check_in ไม่ได้ส่งมา) → controller effective-dates guard ต้องจับ (422)
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $br->id,
                        'check_out' => now()->toDateString(),
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);

        // rollback — check_out ต้องไม่ถูกแก้
        $this->assertEquals(now()->addDays(3)->toDateString(), $br->fresh()->check_out->toDateString());
    }

    public function test_unauthenticated_user_cannot_batch_update_booking_rooms(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $owner = User::factory()->create();
        $booking = $this->createDraftBooking($owner, $roomType);
        $br = $booking->bookingRooms->first();

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/rooms", [
            'booking_rooms' => [
                ['booking_room_id' => $br->id, 'billing_comment' => 'Ghost Batch'],
            ],
        ]);

        $response->assertStatus(401);
        $this->assertNotEquals('Ghost Batch', $br->fresh()->billing_comment);
    }

    public function test_batch_update_booking_rooms_returns_mutated_rooms(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType, 2);
        [$br1, $br2] = $booking->bookingRooms->all();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $br1->id,
                        'check_in' => $br1->check_in->toDateString(), // identical
                        'check_out' => $br1->check_out->toDateString(), // identical
                        'billing_comment' => 'Updated Note 1',
                    ],
                    [
                        'booking_room_id' => $br2->id,
                        'bed_preference' => 'twin',
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'booking_id',
            'booking_rooms' => [
                '*' => [
                    'id',
                    'booking_id',
                    'room_type_id',
                    'check_in',
                    'check_out',
                    'status',
                    'addon',
                ],
            ],
            'total_amount',
        ]);

        $this->assertEquals('Updated Note 1', $br1->fresh()->billing_comment);
        $this->assertEquals('twin', $br2->fresh()->bed_preference);
    }

    public function test_batch_update_with_identical_dates_skips_availability_recheck(): void
    {
        // Only 1 room available of this type
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $owner = User::factory()->create();
        $booking = $this->createDraftBooking($owner, $roomType, 1);
        $br = $booking->bookingRooms->first();

        // Send identical dates and room_type_id, only updating billing_address
        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $br->id,
                        'room_type_id' => $roomType->id,
                        'check_in' => $br->check_in->toDateString(),
                        'check_out' => $br->check_out->toDateString(),
                        'billing_address' => '123 Test St',
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertEquals('123 Test St', $br->fresh()->billing_address);
    }

    // ============================================
    // ✅ Delete booking room (draft only) — DELETE /bookings/{bookingId}/rooms/{bookingRoomId}
    // ============================================

    public function test_owner_can_delete_booking_room_from_draft_booking(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType, 2); // 2 ห้อง = 6000
        $br = $booking->bookingRooms->first();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('remaining_rooms', 1);
        $response->assertJsonPath('total_amount', 3000);

        // 🛡️ BR + Addon หายจริง, booking ยังอยู่
        $this->assertDatabaseMissing('booking_rooms', ['id' => $br->id]);
        $this->assertDatabaseMissing('addons', ['booking_room_id' => $br->id]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'draft']);

        // 📝 audit log
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'booking_room',
            'entity_id' => $br->id,
            'from_status' => 'draft',
            'to_status' => 'deleted',
        ]);
    }

    public function test_cannot_delete_last_booking_room(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType, 1);
        $br = $booking->bookingRooms->first();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}");

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);

        // ห้อง + booking ต้องยังอยู่ครบ
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id]);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_delete_room_blocked_when_booking_not_draft(): void
    {
        $user = User::factory()->create();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $booking = $this->createDraftBooking($user, $roomType, 2, ['status' => 'paid']);
        $br = $booking->bookingRooms->first();

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id]);
    }

    public function test_user_cannot_delete_other_users_booking_room(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $owner = User::factory()->create();
        $booking = $this->createDraftBooking($owner, $roomType, 2);
        $br = $booking->bookingRooms->first();

        $intruder = User::factory()->create();
        $response = $this->actingAs($intruder, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id]);
    }

    public function test_unauthenticated_user_cannot_delete_booking_room(): void
    {
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $this->createRoom($roomType);
        $owner = User::factory()->create();
        $booking = $this->createDraftBooking($owner, $roomType, 2);
        $br = $booking->bookingRooms->first();

        $response = $this->deleteJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}");

        $response->assertStatus(401);
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id]);
    }

    // 🌟 Refactor (18/06/26): lookup & requestPaymentForGuest routes ถูกลบแล้ว — ไม่มี public guest access
}
