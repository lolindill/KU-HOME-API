<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Discount\DiscountService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiscountTest extends TestCase
{
    use RefreshDatabase;

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

    private function createRoomType(int $dailyRateBaht = 2000): RoomType
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe King',
            'name_th' => 'ดีลักซ์ คิง',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'code' => null,
            'name_en' => 'Deluxe King Daily',
            'default_price' => $dailyRateBaht,
            'is_active' => true,
        ]);

        $this->createRoom($rt);

        return $rt;
    }

    private function makeUser(string $role = 'user'): User
    {
        return User::create([
            'id' => Str::uuid(),
            'name' => 'Test User',
            'email' => 'user_'.Str::random(6).'@kuhome.com',
            'password' => bcrypt('password123'),
            'role' => $role,
            'phone' => '0812345678',
        ]);
    }

    private function createDraftBooking(User $user, RoomType $roomType, int $roomCount = 1, int $nights = 1): Booking
    {
        $checkIn = Carbon::today()->addDays(5)->toDateString();
        $checkOut = Carbon::today()->addDays(5 + $nights)->toDateString();

        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'confirmation' => '202608-'.str_pad((string) rand(1, 99999), 5, '0', STR_PAD_LEFT),
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 0,
            'payment_deadline' => Carbon::now()->addHours(24),
        ]);

        for ($i = 0; $i < $roomCount; $i++) {
            $br = BookingRoom::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'room_type_id' => $roomType->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'status' => 'draft',
                'room_amount' => 0,
                'discount_amount' => 0,
            ]);

            Addon::create([
                'booking_room_id' => $br->id,
                'extra_bed' => 0,
                'extra_bed_price' => 0,
                'breakfast' => 0,
                'breakfast_price' => 0,
                'early_checkIn_price' => 0,
                'late_checkOut_price' => 0,
            ]);
        }

        app(DiscountService::class)->reprice($booking);

        return $booking->fresh();
    }

    // =========================================================================
    // 👥 Admin CRUD Tests
    // =========================================================================

    public function test_admin_can_create_discount(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/discounts', [
            'code' => 'super50',
            'type' => 'percent',
            'value' => 50,
            'max_uses' => 100,
            'max_uses_per_user' => 2,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'discount' => [
                    'code' => 'SUPER50',
                    'type' => 'percent',
                    'value' => 50,
                    'is_active' => true,
                ],
            ]);

        $this->assertDatabaseHas('discounts', [
            'code' => 'SUPER50',
            'value' => 50,
        ]);
    }

    public function test_admin_can_update_discount(): void
    {
        $admin = $this->makeUser('admin');
        $discount = Discount::create([
            'code' => 'SUMMER20',
            'type' => 'percent',
            'value' => 20,
            'max_uses' => 50,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/discounts/{$discount->id}", [
            'value' => 30,
            'max_uses' => 100,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'discount' => [
                    'value' => 30,
                    'max_uses' => 100,
                ],
            ]);

        $this->assertEquals(30, $discount->fresh()->value);
    }

    public function test_admin_can_toggle_discount(): void
    {
        $admin = $this->makeUser('admin');
        $discount = Discount::create([
            'code' => 'TOGGLEME',
            'type' => 'fixed',
            'value' => 500,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/discounts/{$discount->id}/toggle");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'discount' => [
                    'is_active' => false,
                ],
            ]);

        $this->assertFalse((bool) $discount->fresh()->is_active);
    }

    public function test_non_admin_cannot_access_discount_crud(): void
    {
        $user = $this->makeUser('user');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/discounts')
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/discounts', [
            'code' => 'HACK',
            'type' => 'percent',
            'value' => 99,
        ])->assertStatus(403);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/discounts/'.Str::uuid(), [
            'value' => 10,
        ])->assertStatus(403);

        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/discounts/'.Str::uuid().'/toggle')
            ->assertStatus(403);
    }

    public function test_admin_can_filter_discounts_by_code_query_param(): void
    {
        $admin = $this->makeUser('admin');

        Discount::create([
            'code' => 'ALPHA10',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        Discount::create([
            'code' => 'BETA20',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/discounts?code=alpha10');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $discounts = $response->json('discounts');
        $this->assertCount(1, $discounts);
        $this->assertEquals('ALPHA10', $discounts[0]['code']);
    }

    public function test_admin_can_get_discount_by_code_name(): void
    {
        $admin = $this->makeUser('admin');

        $discount = Discount::create([
            'code' => 'SPECIAL50',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
        ]);

        // Test with exact code name (case-insensitive)
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/discounts/special50');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'discount' => [
                    'id' => (string) $discount->id,
                    'code' => 'SPECIAL50',
                    'value' => 50,
                ],
            ]);
    }

    public function test_admin_can_get_discount_by_uuid(): void
    {
        $admin = $this->makeUser('admin');

        $discount = Discount::create([
            'code' => 'UUIDTEST',
            'type' => 'percent',
            'value' => 25,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/discounts/{$discount->id}");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'discount' => [
                    'id' => (string) $discount->id,
                    'code' => 'UUIDTEST',
                ],
            ]);
    }

    public function test_get_discount_by_non_existent_code_returns_404(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/discounts/NOTEXIST99');

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_all_user_roles_can_get_discount_by_code(): void
    {
        $discount = Discount::create([
            'code' => 'OPENFORALL',
            'type' => 'percent',
            'value' => 15,
            'is_active' => true,
        ]);

        $roles = ['user', 'guest', 'ku_member', 'staff', 'housekeeping', 'admin'];

        foreach ($roles as $role) {
            $user = $this->makeUser($role);
            $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/discounts/openforall');

            $response->assertStatus(200)
                ->assertJson([
                    'status' => 'success',
                    'discount' => [
                        'code' => 'OPENFORALL',
                        'value' => 15,
                    ],
                ]);
        }
    }

    public function test_unauthenticated_user_cannot_get_discount_by_code(): void
    {
        $response = $this->getJson('/api/v1/discounts/ANYCODE');

        $response->assertStatus(401);
    }

    // =========================================================================
    // 🛡️ Validation Tests
    // =========================================================================

    public function test_create_discount_rejects_percent_over_100(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/discounts', [
            'code' => 'OVER100',
            'type' => 'percent',
            'value' => 150,
        ]);

        $response->assertStatus(422);
    }

    public function test_create_discount_rejects_unknown_room_type(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/discounts', [
            'code' => 'BADTYPE',
            'type' => 'fixed',
            'value' => 100,
            'room_type_ids' => [Str::uuid()->toString()],
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // 💰 Apply & Money Math Tests
    // =========================================================================

    public function test_apply_percent_discount_to_draft_booking(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(2000); // 2,000 THB/night
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 2, nights: 2);
        // Base room price = 2 rooms * 2 nights * 2,000 = 800,000 baht

        $discount = Discount::create([
            'code' => 'SUPER50',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'super50',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'ใส่โค้ดส่วนลดเรียบร้อยแล้วค่ะ! 🎟️',
            ]);

        $freshBooking = $booking->fresh();
        $this->assertEquals('SUPER50', $freshBooking->discount_code);
        $this->assertEquals(4000, $freshBooking->total_amount);

        foreach ($freshBooking->bookingRooms as $br) {
            $this->assertEquals(4000, $br->room_amount); // 2,000 * 2
            $this->assertEquals(2000, $br->discount_amount); // 50% of 4,000
            // 🧾 (03/09/26) amount = 4,000 − 2,000 = 2,000 (net ต่อห้อง)
            $this->assertEquals(2000, $br->amount);
        }

        $this->assertCount(2, DiscountRedemption::where('discount_id', $discount->id)->get());
        $this->assertAmountInvariant($booking);
    }

    public function test_fixed_discount_clamps_to_room_amount(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1000); // 1,000 THB/night
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1, nights: 1); // roomBase = 1,000

        Discount::create([
            'code' => 'HUGE999',
            'type' => 'fixed',
            'value' => 5000, // 5,000 THB > 1,000 THB roomBase
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'HUGE999',
        ])->assertStatus(200);

        $br = $booking->fresh()->bookingRooms->first();
        $this->assertEquals(1000, $br->room_amount);
        $this->assertEquals(1000, $br->discount_amount); // Clamped to room_amount
        $this->assertEquals(0, $booking->fresh()->total_amount);
    }

    public function test_set_room_price_discount(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(2000); // rate = 2,000 THB
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1, nights: 2); // 4,000 baht

        Discount::create([
            'code' => 'SPECIAL1200',
            'type' => 'set_room_price',
            'value' => 1200, // 1,200 THB/night -> discount = (2000 - 1200) * 2 = 1,600 THB (1,600 baht)
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'SPECIAL1200',
        ])->assertStatus(200);

        $br = $booking->fresh()->bookingRooms->first();
        $this->assertEquals(4000, $br->room_amount);
        $this->assertEquals(1600, $br->discount_amount);
        $this->assertEquals(2400, $booking->fresh()->total_amount); // 1,200 * 2 nights = 2,400 THB
    }

    public function test_set_room_price_discount_value_higher_than_rate_results_in_zero_discount(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1500); // rate = 1,500 THB
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1, nights: 1);

        Discount::create([
            'code' => 'EXPENSIVE_PROMO',
            'type' => 'set_room_price',
            'value' => 2000, // 2,000 THB > rate 1,500 THB -> discount = 0
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'EXPENSIVE_PROMO',
        ])->assertStatus(200);

        $br = $booking->fresh()->bookingRooms->first();
        $this->assertEquals(1500, $br->room_amount);
        $this->assertEquals(0, $br->discount_amount);
        $this->assertEquals(1500, $booking->fresh()->total_amount);
    }

    public function test_addon_not_discounted(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(2000);
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1, nights: 1);

        // Add breakfast addon: 600 baht
        $br = $booking->bookingRooms->first();
        $br->addon->update([
            'breakfast' => 2,
            'breakfast_price' => 600,
        ]);

        Discount::create([
            'code' => 'FULL100',
            'type' => 'percent',
            'value' => 100, // 100% room discount
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'FULL100',
        ])->assertStatus(200);

        $freshBooking = $booking->fresh();
        $this->assertEquals(2000, $freshBooking->bookingRooms->first()->room_amount);
        $this->assertEquals(2000, $freshBooking->bookingRooms->first()->discount_amount);
        // Total = (2000 - 2000) + 600 breakfast = 600
        $this->assertEquals(600, $freshBooking->total_amount);

        // 🧾 (03/09/26) amount = 2,000 − 2,000 + 600 breakfast = 600 — addon ไม่โดนลด
        $this->assertEquals(600, $freshBooking->bookingRooms->first()->amount);
        $this->assertAmountInvariant($booking);
    }

    // =========================================================================
    // 🎯 Eligibility Tests
    // =========================================================================

    public function test_partial_eligibility_by_room_type(): void
    {
        $user = $this->makeUser();
        $typeA = $this->createRoomType(1000);
        $typeB = $this->createRoomType(2000);

        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'confirmation' => '202608-00123',
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 0,
            'payment_deadline' => Carbon::now()->addHours(24),
        ]);

        $brA = BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $typeA->id,
            'check_in' => Carbon::today()->addDays(5)->toDateString(),
            'check_out' => Carbon::today()->addDays(6)->toDateString(),
            'status' => 'draft',
            'room_amount' => 0,
            'discount_amount' => 0,
        ]);

        $brB = BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $typeB->id,
            'check_in' => Carbon::today()->addDays(5)->toDateString(),
            'check_out' => Carbon::today()->addDays(6)->toDateString(),
            'status' => 'draft',
            'room_amount' => 0,
            'discount_amount' => 0,
        ]);

        Discount::create([
            'code' => 'ONLY_TYPE_A',
            'type' => 'percent',
            'value' => 50,
            'room_type_ids' => [$typeA->id],
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'ONLY_TYPE_A',
        ])->assertStatus(200);

        $this->assertEquals(500, $brA->fresh()->discount_amount);
        $this->assertEquals(0, $brB->fresh()->discount_amount);
        $this->assertEquals(2500, $booking->fresh()->total_amount); // 500 + 2,000

        // Only 1 redemption row for room A
        $this->assertCount(1, DiscountRedemption::where('booking_room_id', $brA->id)->get());
        $this->assertCount(0, DiscountRedemption::where('booking_room_id', $brB->id)->get());

        // 🧾 (03/09/26) amount รายห้อง: A = 1,000 − 500 = 500 · B = 2,000 (ไม่ลด)
        $this->assertEquals(500, $brA->fresh()->amount);
        $this->assertEquals(2000, $brB->fresh()->amount);
        $this->assertAmountInvariant($booking);
    }

    public function test_stay_window_rejects_outside_range(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1000);
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1, nights: 2);

        Discount::create([
            'code' => 'SUMMER_STAY',
            'type' => 'percent',
            'value' => 20,
            'stay_from' => Carbon::today()->addDays(20)->toDateString(),
            'stay_until' => Carbon::today()->addDays(30)->toDateString(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'SUMMER_STAY',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // 🧮 Limit & Quota Math Tests
    // =========================================================================

    public function test_max_uses_blocks_when_pool_exhausted(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $roomType = $this->createRoomType(1000);

        $discount = Discount::create([
            'code' => 'LIMITED2',
            'type' => 'percent',
            'value' => 20,
            'max_uses' => 2,
            'is_active' => true,
        ]);

        $booking1 = $this->createDraftBooking($user1, $roomType, roomCount: 2);
        $this->actingAs($user1, 'sanctum')->putJson("/api/v1/bookings/{$booking1->id}/discount-code", [
            'code' => 'LIMITED2',
        ])->assertStatus(200);

        // Pool is now 2/2 used
        $booking2 = $this->createDraftBooking($user2, $roomType, roomCount: 1);
        $response = $this->actingAs($user2, 'sanctum')->putJson("/api/v1/bookings/{$booking2->id}/discount-code", [
            'code' => 'LIMITED2',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'โค้ด LIMITED2 ถูกใช้ครบโควตาแล้วค่ะ',
            ]);
    }

    public function test_max_uses_per_user_accumulates_across_bookings(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1000);

        Discount::create([
            'code' => 'PERUSER3',
            'type' => 'percent',
            'value' => 15,
            'max_uses_per_user' => 3,
            'is_active' => true,
        ]);

        $booking1 = $this->createDraftBooking($user, $roomType, roomCount: 2);
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking1->id}/discount-code", [
            'code' => 'PERUSER3',
        ])->assertStatus(200);

        // Simulate booking 1 moving forward (e.g. paid/confirmed)
        $booking1->transitionStatus('paid', 'admin');

        $booking2 = $this->createDraftBooking($user, $roomType, roomCount: 2);
        // User already used 2 slots, booking2 needs 2 slots -> 2 + 2 > 3 -> 422
        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking2->id}/discount-code", [
            'code' => 'PERUSER3',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'นายท่านใช้โค้ด PERUSER3 ครบโควตาต่อคนแล้วค่ะ (3 ห้อง)',
            ]);
    }

    public function test_null_limits_mean_unlimited(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1000);

        Discount::create([
            'code' => 'UNLIMITED',
            'type' => 'percent',
            'value' => 10,
            'max_uses' => null,
            'max_uses_per_user' => null,
            'is_active' => true,
        ]);

        $booking = $this->createDraftBooking($user, $roomType, roomCount: 5);
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'UNLIMITED',
        ])->assertStatus(200);

        $this->assertCount(5, DiscountRedemption::where('user_id', $user->id)->get());
    }

    public function test_apply_is_all_or_nothing(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $roomType = $this->createRoomType(1000);

        Discount::create([
            'code' => 'POOL5',
            'type' => 'percent',
            'value' => 10,
            'max_uses' => 5,
            'is_active' => true,
        ]);

        $booking1 = $this->createDraftBooking($user1, $roomType, roomCount: 3);
        $this->actingAs($user1, 'sanctum')->putJson("/api/v1/bookings/{$booking1->id}/discount-code", [
            'code' => 'POOL5',
        ])->assertStatus(200);

        // 3 slots used, 2 slots remaining. User 2 asks for 3 rooms.
        $booking2 = $this->createDraftBooking($user2, $roomType, roomCount: 3);
        $response = $this->actingAs($user2, 'sanctum')->putJson("/api/v1/bookings/{$booking2->id}/discount-code", [
            'code' => 'POOL5',
        ]);

        $response->assertStatus(422);

        // Assert 0 slots held for booking2
        $this->assertCount(0, DiscountRedemption::where('user_id', $user2->id)->get());
        $this->assertNull($booking2->fresh()->discount_code);
    }

    // =========================================================================
    // 🔄 Release Mechanism Tests
    // =========================================================================

    public function test_deleting_draft_booking_releases_slots(): void
    {
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();
        $roomType = $this->createRoomType(1000);

        $discount = Discount::create([
            'code' => 'SOLO1',
            'type' => 'percent',
            'value' => 50,
            'max_uses' => 1,
            'is_active' => true,
        ]);

        $booking1 = $this->createDraftBooking($user1, $roomType, roomCount: 1);
        $this->actingAs($user1, 'sanctum')->putJson("/api/v1/bookings/{$booking1->id}/discount-code", [
            'code' => 'SOLO1',
        ])->assertStatus(200);

        // User 2 cannot use it
        $booking2 = $this->createDraftBooking($user2, $roomType, roomCount: 1);
        $this->actingAs($user2, 'sanctum')->putJson("/api/v1/bookings/{$booking2->id}/discount-code", [
            'code' => 'SOLO1',
        ])->assertStatus(422);

        // User 1 deletes draft booking
        $this->actingAs($user1, 'sanctum')->deleteJson("/api/v1/bookings/{$booking1->id}")
            ->assertStatus(200);

        $this->assertCount(0, DiscountRedemption::where('discount_id', $discount->id)->get());

        // Now User 2 can use it
        $this->actingAs($user2, 'sanctum')->putJson("/api/v1/bookings/{$booking2->id}/discount-code", [
            'code' => 'SOLO1',
        ])->assertStatus(200);

        $this->assertCount(1, DiscountRedemption::where('discount_id', $discount->id)->get());
    }

    public function test_removing_booking_room_releases_slot_via_cascade(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1000);
        $discount = Discount::create([
            'code' => 'PAIR2',
            'type' => 'percent',
            'value' => 20,
            'max_uses' => 2,
            'is_active' => true,
        ]);

        $booking = $this->createDraftBooking($user, $roomType, roomCount: 2);
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'PAIR2',
        ])->assertStatus(200);

        $this->assertCount(2, DiscountRedemption::where('discount_id', $discount->id)->get());

        $roomToDelete = $booking->bookingRooms->first();
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/bookings/{$booking->id}/rooms/{$roomToDelete->id}")
            ->assertStatus(200);

        // 1 redemption released via cascade
        $this->assertCount(1, DiscountRedemption::where('discount_id', $discount->id)->get());
    }

    public function test_removing_discount_code_reprices_booking(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(2000);
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1, nights: 1);

        Discount::create([
            'code' => 'HALF',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'HALF',
        ])->assertStatus(200);

        $this->assertEquals(1000, $booking->fresh()->total_amount);

        // Remove discount
        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/bookings/{$booking->id}/discount-code");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'ลบโค้ดส่วนลดออกจากการจองเรียบร้อยแล้วค่ะ 🎟️',
            ]);

        $fresh = $booking->fresh();
        $this->assertNull($fresh->discount_code);
        $this->assertEquals(2000, $fresh->total_amount);
        $this->assertEquals(0, $fresh->bookingRooms->first()->discount_amount);
        // 🧾 (03/09/26) ลบโค้ดแล้ว amount กลับไปที่ยอด gross ต่อห้อง
        $this->assertEquals(2000, $fresh->bookingRooms->first()->amount);
        $this->assertCount(0, DiscountRedemption::all());
        $this->assertAmountInvariant($booking);
    }

    // =========================================================================
    // 🚦 Lifecycle & State Transition Tests
    // =========================================================================

    public function test_redemption_stays_held_through_pending_and_verify_error(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1000);
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1);

        $discount = Discount::create([
            'code' => 'PROMO10',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'PROMO10',
        ])->assertStatus(200);

        $redemption = DiscountRedemption::where('discount_id', $discount->id)->first();
        $this->assertEquals('held', $redemption->status);

        // Transition to pending
        $booking->transitionStatus('pending', 'user');
        $this->assertEquals('held', $redemption->fresh()->status);

        // Admin rejects slip -> verify_error
        $booking->transitionStatus('verify_error', 'admin');
        $this->assertEquals('held', $redemption->fresh()->status);

        // User resubmits -> pending
        $booking->transitionStatus('pending', 'user');
        $this->assertEquals('held', $redemption->fresh()->status);
    }

    public function test_redemption_becomes_used_on_paid(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1000);
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1);

        $discount = Discount::create([
            'code' => 'PAYTEST',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'PAYTEST',
        ])->assertStatus(200);

        $booking->transitionStatus('paid', 'admin');

        $redemption = DiscountRedemption::where('discount_id', $discount->id)->first();
        $this->assertEquals('used', $redemption->status);
    }

    // =========================================================================
    // 🔍 Preview & JSON Schema Tests
    // =========================================================================

    public function test_validate_endpoint_returns_quota_preview(): void
    {
        $user = $this->makeUser();
        $discount = Discount::create([
            'code' => 'PREVIEW_CODE',
            'type' => 'percent',
            'value' => 25,
            'max_uses' => 10,
            'max_uses_per_user' => 3,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/discounts/validate', [
            'code' => 'preview_code',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'โค้ดใช้งานได้ค่ะนายท่าน! ✨',
                'discount' => [
                    'code' => 'PREVIEW_CODE',
                    'value' => 25,
                ],
                'quota' => [
                    'global_used' => 0,
                    'global_max' => 10,
                    'global_remaining' => 10,
                    'per_user_used' => 0,
                    'per_user_max' => 3,
                    'per_user_remaining' => 3,
                ],
            ]);

        // Assert 0 redemptions created
        $this->assertCount(0, DiscountRedemption::all());
    }

    public function test_booking_json_contains_discount_fields(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1000);
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1);

        Discount::create([
            'code' => 'JSONTEST',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'JSONTEST',
        ])->assertStatus(200);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/bookings/{$booking->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'booking' => [
                    'id',
                    'discount_code',
                    'total_amount',
                    'booking_rooms' => [
                        '*' => [
                            'id',
                            'room_amount',
                            'discount_amount',
                        ],
                    ],
                ],
            ]);

        $this->assertEquals('JSONTEST', $response->json('booking.discount_code'));
        $this->assertEquals(1000, $response->json('booking.booking_rooms.0.room_amount'));
        $this->assertEquals(200, $response->json('booking.booking_rooms.0.discount_amount'));
    }

    public function test_create_booking_with_discount_code(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType(2000);

        Discount::create([
            'code' => 'DIRECT10',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'discount_code' => 'direct10',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => Carbon::today()->addDays(2)->toDateString(),
                    'check_out' => Carbon::today()->addDays(3)->toDateString(),
                ],
            ],
        ]);

        $response->assertStatus(201);
        $bookingId = $response->json('booking_id');
        $booking = Booking::find($bookingId);

        $this->assertEquals('DIRECT10', $booking->discount_code);
        $this->assertEquals(1800, $booking->total_amount); // 2,000 - 10%
        $this->assertCount(1, DiscountRedemption::where('user_id', $user->id)->get());
    }

    public function test_discount_value_change_recomputes_on_draft_edit(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();
        $roomType = $this->createRoomType(2000);
        $booking = $this->createDraftBooking($user, $roomType, roomCount: 1, nights: 1);

        $discount = Discount::create([
            'code' => 'LIVE_RECOMPUTE',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/discount-code", [
            'code' => 'LIVE_RECOMPUTE',
        ])->assertStatus(200);

        $this->assertEquals(1000, $booking->fresh()->total_amount);

        // Admin modifies discount from 50% to 30%
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/discounts/{$discount->id}", [
            'value' => 30,
        ])->assertStatus(200);

        // User edits room in draft (e.g. single room edit)
        $br = $booking->bookingRooms->first();
        $this->actingAs($user, 'sanctum')->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
            'check_in' => Carbon::today()->addDays(6)->toDateString(),
            'check_out' => Carbon::today()->addDays(7)->toDateString(),
        ])->assertStatus(200);

        // Now total is recomputed with 30% discount -> 2,000 - 600 = 1,400
        $this->assertEquals(1400, $booking->fresh()->total_amount);
        $this->assertEquals(600, $br->fresh()->discount_amount);
    }

    // =========================================================================
    // 🛡️ Fix regression suite (27/08/26 — scrutinize findings)
    // =========================================================================

    public function test_missing_code_field_returns_422_not_500(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType();
        $booking = $this->createDraftBooking($user, $roomType);

        // 🛡️ Fix #1: ValidationException ถูก rethrow — ต้องได้ 422 มาตรฐาน Laravel (เดิม 500)
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/discount-code", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_non_owner_cannot_set_discount_code(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $roomType = $this->createRoomType();
        $booking = $this->createDraftBooking($owner, $roomType);

        Discount::create([
            'code' => 'STEALME',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/discount-code", ['code' => 'STEALME'])
            ->assertStatus(403);

        $this->assertNull($booking->fresh()->discount_code);
    }

    public function test_non_draft_booking_rejects_discount_code(): void
    {
        $user = $this->makeUser();
        $roomType = $this->createRoomType();
        $booking = $this->createDraftBooking($user, $roomType);
        $booking->transitionStatus('pending', 'user');

        Discount::create([
            'code' => 'LATECODE',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/discount-code", ['code' => 'LATECODE'])
            ->assertStatus(422);
    }

    public function test_duplicate_code_different_case_is_422_not_500(): void
    {
        $admin = $this->makeUser('admin');
        Discount::create([
            'code' => 'WELCOME10',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        // 🛡️ Fix #5: DB unique rule จับต่าง case ไม่ได้ — closure ต้อง fail ก่อนเป็น 422
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/discounts', [
            'code' => 'welcome10',
            'type' => 'percent',
            'value' => 20,
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_cannot_rename_code_while_redemptions_exist(): void
    {
        $admin = $this->makeUser('admin');
        $user = $this->makeUser();
        $roomType = $this->createRoomType(1000);
        $booking = $this->createDraftBooking($user, $roomType);

        $discount = Discount::create([
            'code' => 'OLDNAME',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/discount-code", ['code' => 'OLDNAME'])
            ->assertStatus(200);

        // 🛡️ Fix #2: rename ขณะมี hold → 422 validation error
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/discounts/{$discount->id}", ['code' => 'NEWNAME'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        // Hold ยังอยู่ครบ ส่วนลดยังทำงาน (reprice เจอโค้ดเดิม)
        $this->assertCount(1, DiscountRedemption::where('discount_id', $discount->id)->get());
        $this->assertEquals(500, $booking->fresh()->total_amount);
        $this->assertSame('OLDNAME', $discount->fresh()->code);
    }

    public function test_can_rename_code_when_no_redemptions_exist(): void
    {
        $admin = $this->makeUser('admin');
        $discount = Discount::create([
            'code' => 'FREE_NAME',
            'type' => 'percent',
            'value' => 25,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/discounts/{$discount->id}", ['code' => 'RENAMED25'])
            ->assertStatus(200);

        $this->assertSame('RENAMED25', $discount->fresh()->code);
    }

    public function test_stay_window_must_be_provided_as_a_pair(): void
    {
        $admin = $this->makeUser('admin');

        // 🛡️ Fix #3: half-set window ทำให้ preview 200 แต่ apply 422 — บังคับเป็นคู่ตั้งแต่ validate
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/discounts', [
            'code' => 'HALFWIN',
            'type' => 'percent',
            'value' => 20,
            'stay_from' => Carbon::today()->addDays(1)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['stay_until']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/discounts', [
            'code' => 'HALFWIN2',
            'type' => 'percent',
            'value' => 20,
            'stay_until' => Carbon::today()->addDays(30)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['stay_from']);

        // update() path — เพิ่มครึ่งเดียวให้โค้ดที่ยังไม่มี window ก็ต้องโดนปฏิเสธ
        $discount = Discount::create([
            'code' => 'NO_WINDOW',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/discounts/{$discount->id}", [
                'stay_until' => Carbon::today()->addDays(30)->toDateString(),
            ])->assertStatus(422)->assertJsonValidationErrors(['stay_from']);
    }

    /**
     * 🛡️ (28/08/26 F1): เปลี่ยน type โดยไม่ส่ง value ต้องถูก reject (422) กันกรณี fixed baht หลุดไปเป็น percent 100%
     */
    public function test_update_discount_type_swap_without_value_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $discount = Discount::create([
            'code' => 'FIXEDTO100',
            'type' => 'fixed',
            'value' => 1000,
            'is_active' => true,
        ]);

        // เปลี่ยนเป็น percent โดยไม่ส่ง value → 422
        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/discounts/{$discount->id}", [
                'type' => 'percent',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['value'])
            ->assertJsonPath('errors.value.0', 'เปลี่ยนประเภทส่วนลดต้องส่ง value มาพร้อมกันเสมอค่ะ');

        // ส่ง type พร้อม value ที่ถูกต้อง → 200
        $response2 = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/discounts/{$discount->id}", [
                'type' => 'percent',
                'value' => 50,
            ]);

        $response2->assertStatus(200);
        $this->assertSame('percent', $discount->fresh()->type);
        $this->assertSame(50, $discount->fresh()->value);

        // ส่งเฉพาะ value ที่เกิน 100 บน percent discount → 422
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/discounts/{$discount->id}", [
                'value' => 150,
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['value']);

        // ส่งเฉพาะ max_uses โดยไม่ส่ง type หรือ value → 200
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/discounts/{$discount->id}", [
                'max_uses' => 99,
            ])->assertStatus(200);
        $this->assertSame(99, $discount->fresh()->max_uses);
    }
}
