<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Booking;
use App\Models\BookingRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class RoomTest extends TestCase
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

    private function createRoom(string $status = 'available'): Room
    {
        $roomType = $this->createRoomType();
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '10' . rand(1, 99),
            'status' => $status,
        ]);
    }

    // ============================================
    // 🔓 Public: Room listing
    // ============================================

    public function test_anyone_can_list_rooms(): void
    {
        $this->createRoom();
        $this->createRoom();
        $response = $this->getJson('/api/v1/rooms');
        $response->assertStatus(200);
    }

    public function test_anyone_can_list_room_types(): void
    {
        $this->createRoomType();
        $response = $this->getJson('/api/v1/room-types');
        $response->assertStatus(200);
    }

    public function test_anyone_can_check_availability(): void
    {
        $response = $this->getJson('/api/v1/availability');
        $response->assertStatus(200);
    }

    // ============================================
    // 🔐 Admin: Room status update
    // ============================================

    public function test_admin_can_update_room_status(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom('available');

        $response = $this->putJson("/api/v1/rooms/{$room->id}/status", [
            'status' => 'dirty',
        ]);
        $response->assertStatus(200);
        $this->assertEquals('dirty', $room->fresh()->status);
    }

    public function test_admin_cannot_do_invalid_room_status_transition(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom('available');

        $response = $this->putJson("/api/v1/rooms/{$room->id}/status", [
            'status' => 'checkout_makeup', // available → checkout_makeup is invalid
        ]);
        $response->assertStatus(422);
    }

    // ============================================
    // 🔒 Non-admin rejected
    // ============================================

    public function test_non_admin_cannot_update_room_status(): void
    {
        $this->actingAsUser();
        $room = $this->createRoom('available');

        $response = $this->putJson("/api/v1/rooms/{$room->id}/status", [
            'status' => 'dirty',
        ]);
        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_update_room_status(): void
    {
        $room = $this->createRoom('available');
        $response = $this->putJson("/api/v1/rooms/{$room->id}/status", [
            'status' => 'dirty',
        ]);
        $response->assertStatus(401);
    }

    // ============================================
    // ✅ #31: Availability query consistency
    // ============================================

    public function test_deleted_booking_does_not_reduce_availability(): void
    {
        $roomType = $this->createRoomType();
        $room = Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'status' => 'available',
        ]);

        // Create a deleted booking — should NOT count as booked
        $booking = Booking::create([
            'status' => 'deleted',
            'guest_name' => 'Deleted Guest',
            'guest_email' => 'deleted@test.com',
            'guest_phone' => '0810000000',
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'total_amount' => 3000,
        ]);

        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);

        $response = $this->getJson('/api/v1/availability?' . http_build_query([
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
        ]));

        $response->assertStatus(200);
        $roomTypes = $response->json('room_types');
        $found = collect($roomTypes)->firstWhere('room_type_id', $roomType->id);

        // deleted booking should NOT reduce available rooms
        $this->assertEquals(1, $found['available_rooms']);
    }

    public function test_confirmed_booking_reduces_availability(): void
    {
        $roomType = $this->createRoomType();
        $room = Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'status' => 'available',
        ]);

        // Create a confirmed booking — SHOULD count as booked
        $booking = Booking::create([
            'status' => 'confirmed',
            'guest_name' => 'Confirmed Guest',
            'guest_email' => 'confirmed@test.com',
            'guest_phone' => '0811111111',
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'total_amount' => 3000,
        ]);

        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
        ]);

        $response = $this->getJson('/api/v1/availability?' . http_build_query([
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
        ]));

        $response->assertStatus(200);
        $roomTypes = $response->json('room_types');
        $found = collect($roomTypes)->firstWhere('room_type_id', $roomType->id);

        // confirmed booking should reduce available rooms to 0
        $this->assertEquals(0, $found['available_rooms']);
    }
}
