<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\User;
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
        $roomA = $this->createRoom();
        $roomB = $this->createRoom();
        $response = $this->getJson('/api/v1/rooms');
        $response->assertStatus(200);

        // 🛡️ Scrutinize: Verify rooms actually returned (not just empty 200)
        $rooms = $response->json('rooms');
        $roomIds = collect($rooms)->pluck('id');
        $this->assertContains($roomA->id, $roomIds, 'Room A should be in the listing');
        $this->assertContains($roomB->id, $roomIds, 'Room B should be in the listing');
    }

    public function test_anyone_can_list_room_types(): void
    {
        $roomType = $this->createRoomType();
        $response = $this->getJson('/api/v1/room-types');
        $response->assertStatus(200);

        // 🛡️ Scrutinize: Verify room type actually returned
        $roomTypes = $response->json('room_types') ?? $response->json('data') ?? [];
        $ids = collect($roomTypes)->pluck('id')->map(fn ($id) => (string) $id);
        $this->assertContains((string) $roomType->id, $ids, 'Created room type should be in the listing');
    }

    public function test_anyone_can_check_availability(): void
    {
        $roomType = $this->createRoomType();
        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '201',
            'status' => 'available',
        ]);

        $response = $this->getJson('/api/v1/availability?' . http_build_query([
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
        ]));
        $response->assertStatus(200);

        // 🛡️ Scrutinize: Verify availability payload reflects the room we created
        $roomTypes = $response->json('room_types') ?? [];
        $found = collect($roomTypes)->firstWhere('room_type_id', $roomType->id);
        $this->assertNotNull($found, 'Created room type should appear in availability');
        $this->assertGreaterThanOrEqual(1, $found['available_rooms'] ?? 0,
            'Available rooms should reflect the room we just created');
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

    public function test_cancelled_booking_room_does_not_reduce_availability(): void
    {
        $roomType = $this->createRoomType();
        $room = Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'status' => 'available',
        ]);

        // 🌟 Refactor (29/06/26): container ไม่มี 'cancelled' แล้ว — draft หมดอายุถูก hard delete
        // แต่ BR-level ยังมี 'cancelled' อยู่ (BR states: draft/confirmed/checked_in/checked_out/cancelled/no_show)
        // การทดสอบนี้ยืนยันว่า BR status='cancelled' จะไม่นับลด availability
        $user = User::factory()->create();
        $booking = Booking::create([
            'user_id' => $user->id,
            'confirmation' => 'TEST-' . Str::uuid(),
            'source' => 'admin',
            'status' => 'confirmed',
            'total_amount' => 3000,
        ]);

        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => 'cancelled', // BR-level cancelled — ไม่นับลด availability
        ]);

        $response = $this->getJson('/api/v1/availability?' . http_build_query([
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
        ]));

        $response->assertStatus(200);
        $roomTypes = $response->json('room_types');
        $found = collect($roomTypes)->firstWhere('room_type_id', $roomType->id);

        // BR-level cancelled should NOT reduce available rooms
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

        // 🌟 Refactor (25/06/26): guest fields + dates ย้ายไป BR-level
        $user = User::factory()->create();
        $booking = Booking::create([
            'user_id' => $user->id,
            'confirmation' => 'TEST-' . Str::uuid(),
            'source' => 'admin',
            'status' => 'confirmed',
            'total_amount' => 3000,
        ]);

        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDays(1)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
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