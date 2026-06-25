<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\BookingRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class FrontDeskTest extends TestCase
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
     * 🌟 Refactor (25/06/26): bookings ไม่มี check_in/check_out แล้ว — ย้ายไป BR-level
     * Container states: draft, paid, confirmed, complete, cancelled (เท่านั้น)
     */
    private function createBooking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        $booking = Booking::create(array_merge([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'confirmed',
            'total_amount' => 3000,
        ], $overrides));

        return $booking;
    }

    /**
     * 🌟 Helper: สร้าง BookingRoom พร้อม check_in/check_out (จำเป็นสำหรับ BR-level)
     */
    private function createBookingRoom(Booking $booking, RoomType $roomType, ?Room $room = null, string $brStatus = 'confirmed'): BookingRoom
    {
        return BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => $room?->id,
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'guests' => [['title' => 'mr', 'name' => 'FD Guest', 'nationality' => 'TH']],
            'children' => 0,
            'status' => $brStatus,
        ]);
    }

    // ============================================
    // 🚶 Walk-in (Refactored: ใช้ staff + guests JSON)
    // ============================================

    public function test_admin_can_walk_in_guest(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum');
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);

        // 🌟 Refactor (18/06/26): payload ใหม่ — guests[] + children, ไม่มี guest_name/email/phone แล้ว
        $response = $this->postJson('/api/v1/front-desk/walk-in', [
            'verified_by' => $admin->id,
            'room_id' => $room->id,
            'nights' => 2,
            'guests' => [
                ['title' => 'mr', 'name' => 'Walk In Guest', 'nationality' => 'TH'],
            ],
            'children' => 0,
        ]);

        $response->assertStatus(201);
        // 🌟 Refactor (25/06/26): container = confirmed (walk-in skips draft→paid)
        // BR-level = checked_in
        $this->assertDatabaseHas('bookings', [
            'user_id' => $admin->id,
            'source' => 'admin',
            'status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('booking_rooms', [
            'room_id' => $room->id,
            'status' => 'checked_in',
            'children' => 0,
        ]);
    }

    public function test_non_admin_cannot_walk_in_guest(): void
    {
        $this->actingAsUser();
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);

        $response = $this->postJson('/api/v1/front-desk/walk-in', [
            'room_id' => $room->id,
            'nights' => 2,
            'guests' => [
                ['title' => 'mr', 'name' => 'Walk In Guest'],
            ],
        ]);

        $response->assertStatus(403);
    }

    // ============================================
    // ✅ Check-in
    // ============================================

    public function test_admin_can_check_in_confirmed_booking(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);
        $booking = $this->createBooking(['status' => 'confirmed']);

        // 🌟 Refactor (25/06/26): BR-level — ต้องมี check_in/check_out + status
        $br = $this->createBookingRoom($booking, $roomType, null, 'confirmed');

        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/check-in", [
            'assigned_rooms' => [$room->id],
        ]);

        $response->assertStatus(200);
        // 🌟 Container stays at confirmed (check-in is BR-level now)
        $this->assertEquals('confirmed', $booking->fresh()->status);
        // 🌟 BR-level = checked_in
        $this->assertEquals('checked_in', $br->fresh()->status);
    }

    // ============================================
    // ✅ Check-out
    // ============================================

    public function test_admin_can_check_out_checked_in_booking(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum');
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType, 'occupied');
        $booking = $this->createBooking([
            'status' => 'confirmed',
            'is_paid' => true,
        ]);

        // 🌟 Refactor (25/06/26): BR-level — pre-assigned room + status checked_in
        $br = $this->createBookingRoom($booking, $roomType, $room, 'checked_in');

        // Need a completed payment for check-out to succeed
        \App\Models\Payment::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'amount' => 3000,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/check-out", [
            'verified_by' => $admin->id,
        ]);

        $response->assertStatus(200);
        // 🌟 Refactor (25/06/26): container auto-syncs to complete
        $this->assertEquals('complete', $booking->fresh()->status);
        // 🌟 BR-level = checked_out
        $this->assertEquals('checked_out', $br->fresh()->status);
    }

    // ============================================
    // 💰 Record payment
    // ============================================

    public function test_admin_can_record_payment(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'confirmed', 'total_amount' => 3000]);

        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/payment", [
            'booking_id' => $booking->id,
            'amount' => 3000,
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'amount' => 3000,
            'status' => 'completed',
        ]);
    }

    // ============================================
    // ✅ #32: Check-in room type validation
    // ============================================

    public function test_check_in_rejects_wrong_room_type(): void
    {
        $this->actingAsAdmin();

        // Create two different room types
        $standardType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard',
            'name_th' => 'สแตนดาร์ด',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
            'rate_daily_general' => 1000,
        ]);

        $deluxeType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe',
            'name_th' => 'ดีลักซ์',
            'max_guests' => 4,
            'extra_bed_enabled' => true,
            'rate_daily_general' => 3000,
        ]);

        // Create a DELUXE room (but booking is for STANDARD)
        $deluxeRoom = Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $deluxeType->id,
            'room_number' => '201',
            'status' => 'available',
        ]);

        $booking = $this->createBooking(['status' => 'confirmed']);

        // 🌟 Refactor (25/06/26): BR-level — ต้องมี check_in/check_out + status
        $this->createBookingRoom($booking, $standardType, null, 'confirmed');

        // Try to check-in with Deluxe room — should FAIL
        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/check-in", [
            'assigned_rooms' => [$deluxeRoom->id],
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'status' => 'error',
        ]);
        $this->assertStringContainsString('ไม่ตรงกับประเภทห้องที่จองไว้', $response->json('message'));
    }
}