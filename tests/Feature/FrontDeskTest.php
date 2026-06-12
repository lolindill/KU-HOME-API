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

    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'status' => 'confirmed',
            'guest_name' => 'Front Desk Guest',
            'guest_email' => 'fd@example.com',
            'guest_phone' => '0812345678',
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'total_amount' => 3000,
        ], $overrides));
    }

    // ============================================
    // 🚶 Walk-in
    // ============================================

    public function test_admin_can_walk_in_guest(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum');
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);

        $response = $this->postJson('/api/v1/front-desk/walk-in', [
            'verified_by' => $admin->id,
            'room_id' => $room->id,
            'guest_name' => 'Walk In Guest',
            'guest_phone' => '0899999999',
            'nights' => 2,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('bookings', [
            'guest_phone' => '0899999999',
            'status' => 'checked_in',
        ]);
    }

    public function test_non_admin_cannot_walk_in_guest(): void
    {
        $this->actingAsUser();
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);

        $response = $this->postJson('/api/v1/front-desk/walk-in', [
            'room_id' => $room->id,
            'guest_name' => 'Walk In Guest',
            'guest_email' => 'walkin2@example.com',
            'guest_phone' => '0899999999',
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
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

        // Attach room to booking
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => $room->id,
        ]);

        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/check-in", [
            'assigned_rooms' => [$room->id],
        ]);

        $response->assertStatus(200);
        $this->assertEquals('checked_in', $booking->fresh()->status);
    }

    // ============================================
    // ✅ Check-out
    // ============================================

    public function test_admin_can_check_out_checked_in_booking(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType, 'occupied');
        $booking = $this->createBooking([
            'status' => 'checked_in',
            'is_paid' => true,
        ]);

        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => $room->id,
        ]);

        // Need a completed payment for check-out to succeed
        \App\Models\Payment::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'amount' => 3000,
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum');
        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/check-out", [
            'verified_by' => $admin->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('checked_out', $booking->fresh()->status);
    }

    // ============================================
    // 💰 Record payment
    // ============================================

    public function test_admin_can_record_payment(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'checked_in', 'total_amount' => 3000]);

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

        // Booking room expects Standard type
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $standardType->id,
        ]);

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
