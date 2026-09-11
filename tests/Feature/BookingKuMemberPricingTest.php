<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Discount;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingKuMemberPricingTest extends TestCase
{
    use RefreshDatabase;

    private static int $roomSeq = 0;

    private function createRoomTypeWithRates(int $dailyBaht = 1200, ?int $dailyKuBaht = 1000): RoomType
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe Room',
            'name_th' => 'ห้องดีลักซ์',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'code' => null,
            'name_en' => 'Deluxe Daily General',
            'default_price' => $dailyBaht,
            'is_active' => true,
        ]);

        if ($dailyKuBaht !== null) {
            GlobalRate::create([
                'rate_type' => 'daily_ku',
                'room_type_id' => $rt->id,
                'code' => null,
                'name_en' => 'Deluxe Daily KU Member',
                'default_price' => $dailyKuBaht,
                'is_active' => true,
            ]);
        }

        GlobalRate::create([
            'rate_type' => 'addon',
            'room_type_id' => null,
            'code' => 'extra_bed',
            'name_en' => 'Extra Bed',
            'default_price' => 500,
            'is_active' => true,
        ]);

        GlobalRate::create([
            'rate_type' => 'addon',
            'room_type_id' => null,
            'code' => 'breakfast',
            'name_en' => 'Breakfast',
            'default_price' => 150,
            'is_active' => true,
        ]);

        return $rt;
    }

    private function createRoom(RoomType $roomType): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '10'.(++self::$roomSeq),
            'status' => 'available',
        ]);
    }

    public function test_general_user_booking_uses_daily_rate(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_ku_member' => false,
        ]);

        $roomType = $this->createRoomTypeWithRates(1200, 1000);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(7)->toDateString(); // 2 nights

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
            ]);

        $booking = Booking::with('bookingRooms')->where('user_id', $user->id)->firstOrFail();
        $room = $booking->bookingRooms->first();

        // 2 nights * 1,200 baht (daily rate) = 2,400
        $this->assertSame(2400, $room->room_amount);
        $this->assertSame(2400, $room->amount);
        $this->assertSame(2400, $booking->total_amount);
    }

    public function test_ku_member_user_booking_uses_daily_ku_rate(): void
    {
        $kuMember = User::factory()->create([
            'role' => 'ku_member',
            'is_ku_member' => false, // Ensure only role is checked!
        ]);

        $roomType = $this->createRoomTypeWithRates(1200, 1000);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(7)->toDateString(); // 2 nights

        $response = $this->actingAs($kuMember, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
            ]);

        $booking = Booking::with('bookingRooms')->where('user_id', $kuMember->id)->firstOrFail();
        $room = $booking->bookingRooms->first();

        // 2 nights * 1,000 baht (daily_ku rate) = 2,000
        $this->assertSame(2000, $room->room_amount);
        $this->assertSame(2000, $room->amount);
        $this->assertSame(2000, $booking->total_amount);
    }

    public function test_ku_member_user_falls_back_to_daily_when_daily_ku_missing(): void
    {
        $kuMember = User::factory()->create([
            'role' => 'ku_member',
        ]);

        // RoomType with ONLY daily rate (no daily_ku)
        $roomType = $this->createRoomTypeWithRates(1200, null);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(3)->toDateString();
        $checkOut = Carbon::now()->addDays(4)->toDateString(); // 1 night

        $response = $this->actingAs($kuMember, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $booking = Booking::with('bookingRooms')->where('user_id', $kuMember->id)->firstOrFail();
        $room = $booking->bookingRooms->first();

        // Falls back to daily rate: 1,200 baht
        $this->assertSame(1200, $room->room_amount);
        $this->assertSame(1200, $room->amount);
        $this->assertSame(1200, $booking->total_amount);
    }

    public function test_ku_member_add_rooms_calculates_with_daily_ku_rate(): void
    {
        $kuMember = User::factory()->create([
            'role' => 'ku_member',
        ]);

        $roomType = $this->createRoomTypeWithRates(1200, 1000);
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(10)->toDateString();
        $checkOut = Carbon::now()->addDays(11)->toDateString(); // 1 night

        // First room: 1,000 baht
        $createRes = $this->actingAs($kuMember, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);
        $createRes->assertStatus(201);
        $bookingId = $createRes->json('booking_id');

        // Add second room: 1 night * 1,000 baht
        $addRes = $this->actingAs($kuMember, 'sanctum')->postJson("/api/v1/bookings/{$bookingId}/rooms", [
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $addRes->assertStatus(200);
        $this->assertSame(1000, $addRes->json('added_amount'));
        $this->assertSame(2000, $addRes->json('total_amount'));

        $booking = Booking::with('bookingRooms')->findOrFail($bookingId);
        $this->assertSame(2000, $booking->total_amount);
        $this->assertSame(2000, $booking->bookingRooms->sum('amount'));
    }

    public function test_ku_member_booking_with_addons_preserves_invariant(): void
    {
        $kuMember = User::factory()->create([
            'role' => 'ku_member',
        ]);

        $roomType = $this->createRoomTypeWithRates(1200, 1000);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(14)->toDateString();
        $checkOut = Carbon::now()->addDays(16)->toDateString(); // 2 nights

        $response = $this->actingAs($kuMember, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'addons' => [
                        'extra_bed' => 1, // 500 * 2 nights = 1,000 baht
                        'breakfast' => 2, // 150 * 2 qty = 300 baht
                    ],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $booking = Booking::with(['bookingRooms.addon'])->where('user_id', $kuMember->id)->firstOrFail();
        $room = $booking->bookingRooms->first();

        // Room rate: 2 nights * 1,000 baht = 2,000 baht
        $this->assertSame(2000, $room->room_amount);
        // Addons: 1,000 (extra bed) + 300 (breakfast) = 1,300 baht
        $this->assertSame(1000, $room->addon->extra_bed_price);
        $this->assertSame(300, $room->addon->breakfast_price);
        // Total room amount: 2,000 + 1,300 = 3,300 baht
        $this->assertSame(3300, $room->amount);
        $this->assertSame(3300, $booking->total_amount);
        $this->assertSame($booking->total_amount, $booking->bookingRooms->sum('amount'));
    }

    public function test_ku_member_booking_with_percent_discount(): void
    {
        $kuMember = User::factory()->create([
            'role' => 'ku_member',
        ]);

        $roomType = $this->createRoomTypeWithRates(1200, 1000);
        $this->createRoom($roomType);

        Discount::create([
            'code' => 'KU10PERCENT',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        $checkIn = Carbon::now()->addDays(20)->toDateString();
        $checkOut = Carbon::now()->addDays(21)->toDateString(); // 1 night

        $response = $this->actingAs($kuMember, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'discount_code' => 'KU10PERCENT',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $booking = Booking::with('bookingRooms')->where('user_id', $kuMember->id)->firstOrFail();
        $room = $booking->bookingRooms->first();

        // 1 night * 1,000 baht (daily_ku) = 1,000 baht
        // 10% discount = 100 baht
        // Net room amount = 900 baht
        $this->assertSame(1000, $room->room_amount);
        $this->assertSame(100, $room->discount_amount);
        $this->assertSame(900, $room->amount);
        $this->assertSame(900, $booking->total_amount);
    }
}
