<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Booking;
use App\Models\GlobalRate;
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
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Double',
            'name_th' => 'สแตนดาร์ด ดับเบิล',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        // 🌟 Refactor (22/07/26): rate_daily_general ย้ายไป global_rates แล้ว
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
            'room_number' => '10' . rand(1, 99),
            'status' => $status,
        ]);
    }

    /**
     * 🌟 Refactor (29/06/26): bookings ไม่มี check_in/check_out แล้ว — ย้ายไป BR-level
     * Container states: draft, paid, confirmed, complete (เท่านั้น) — ไม่มี cancelled แล้ว
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

    /**
     * 🌟 NEW (scrutinize 29/06/26): Bug fix #1 — check-in ต้องปฏิเสธ draft booking
     * ก่อนหน้านี้ code เดิม bypass state machine ทำให้ draft → confirmed ได้โดยข้ามการชำระเงิน
     */
    public function test_check_in_rejects_draft_booking(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);
        $booking = $this->createBooking(['status' => 'draft']); // 🚨 ยังไม่จ่ายเงิน!

        $br = $this->createBookingRoom($booking, $roomType, null, 'draft');

        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/check-in", [
            'assigned_rooms' => [$room->id],
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'error']);
        $this->assertStringContainsString('ชำระเงิน', $response->json('message'));
        // 🛡️ State must remain unchanged (no silent bypass)
        $this->assertEquals('draft', $booking->fresh()->status);
        $this->assertEquals('draft', $br->fresh()->status);
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

    /**
     * 🌟 NEW (scrutinize): Integration test — full check-in → check-out flow
     * ไม่ pre-set state ด้วยมือ เพื่อจับ bugs ที่เกิดจาก integration ระหว่าง state machines จริง
     */
    public function test_full_check_in_to_check_out_integration_flow(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum');
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType, 'available');
        $booking = $this->createBooking([
            'status' => 'confirmed',
            'is_paid' => false,
            'total_amount' => 3000,
        ]);
        $br = $this->createBookingRoom($booking, $roomType, null, 'confirmed');

        // Step 1: Record payment (booking is confirmed, payment marks is_paid=true)
        $payResponse = $this->postJson("/api/v1/front-desk/{$booking->id}/payment", [
            'booking_id' => $booking->id,
            'amount' => 3000,
            'payment_method' => 'cash',
        ]);
        $payResponse->assertStatus(201);
        $this->assertTrue($booking->fresh()->is_paid);

        // Step 2: Check-in (BR: confirmed → checked_in, room → occupied)
        $checkInResponse = $this->postJson("/api/v1/front-desk/{$booking->id}/check-in", [
            'assigned_rooms' => [$room->id],
        ]);
        $checkInResponse->assertStatus(200);
        $this->assertEquals('checked_in', $br->fresh()->status);
        $this->assertEquals('occupied', $room->fresh()->status);

        // Step 3: Check-out (BR: checked_in → checked_out, room → checkout_makeup, booking → complete)
        $checkOutResponse = $this->postJson("/api/v1/front-desk/{$booking->id}/check-out", [
            'verified_by' => $admin->id,
        ]);
        $checkOutResponse->assertStatus(200);
        $this->assertEquals('checked_out', $br->fresh()->status);
        $this->assertEquals('checkout_makeup', $room->fresh()->status);
        $this->assertEquals('complete', $booking->fresh()->status);
    }

    // ============================================
    // 💰 Record payment
    // ============================================

    /**
     * 🌟 FIXED (scrutinize): เดิมใช้ status='confirmed' ทำให้ logic transition draft→paid ไม่ทำงาน
     * และไม่ assert booking status/is_paid/receipt → false confidence
     * ตอนนี้ใช้ status='draft' + assert full state เพื่อจับ bugs จริง
     */
    public function test_admin_can_record_payment(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'draft', 'total_amount' => 3000]);

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
        // 🌟 Fixed: assert full state transition จริงๆ
        $this->assertEquals('paid', $booking->fresh()->status, 'Booking should transition draft → paid');
        $this->assertTrue($booking->fresh()->is_paid, 'Booking is_paid should be true');
        // ❄️ FROZEN (24/07/26): Receipt table deprecated — recordPayment ไม่สร้าง receipt แล้ว
        //    flow payment confirmation ย้ายไป booking_confirmations table (POST /bookings/{id}/confirm + admin verify)
        $this->assertDatabaseMissing('receipts', ['booking_id' => $booking->id]);
    }

    /**
     * 🌟 Fix H4 (03/07/26): front-desk payment รับ booking_id จาก URL param
     * ไม่บังคับต้องส่ง booking_id ใน body ด้วย
     */
    public function test_record_payment_without_body_booking_id(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'draft', 'total_amount' => 3000]);

        // ส่งแค่ amount + payment_method (ไม่ส่ง booking_id ใน body)
        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/payment", [
            'amount' => 3000,
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(201);
        $this->assertTrue($booking->fresh()->is_paid);
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
        ]);
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $standardType->id,
            'code' => null,
            'name_en' => 'Standard Daily',
            'default_price' => 1000,
            'is_active' => true,
        ]);

        $deluxeType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe',
            'name_th' => 'ดีลักซ์',
            'max_guests' => 4,
            'extra_bed_enabled' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $deluxeType->id,
            'code' => null,
            'name_en' => 'Deluxe Daily',
            'default_price' => 3000,
            'is_active' => true,
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

    // ============================================
    // 🚫 Mark No-Show (Fix BUG #1, #3, #4 — 03/07/26)
    // ============================================

    /**
     * 🌟 BUG #1 regression: ป้องกัน route mark-no-show หายไปอีก
     */
    public function test_mark_no_show_route_exists(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'confirmed']);
        $roomType = $this->createRoomType();
        $this->createBookingRoom($booking, $roomType, null, 'confirmed');

        $admin = \App\Models\User::factory(['role' => 'admin'])->create();

        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/mark-no-show", [
            'verified_by' => $admin->id,
        ]);

        // ถ้า route ไม่ exist → 404; route มี → 200
        $response->assertStatus(200);
    }

    /**
     * 🌟 BUG #3+#4: full no-show — ทุกห้อง confirmed → no_show, booking → complete
     */
    public function test_admin_can_mark_full_no_show(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum');
        $roomType = $this->createRoomType();
        $booking = $this->createBooking(['status' => 'confirmed']);
        $br = $this->createBookingRoom($booking, $roomType, null, 'confirmed');

        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/mark-no-show", [
            'verified_by' => $admin->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('no_show', $br->fresh()->status);
        // booking auto-sync → complete เพราะทุกห้องจบแล้ว
        $this->assertEquals('complete', $booking->fresh()->status);
    }

    /**
     * 🌟 BUG #4: partial no-show — mark เฉพาะบางห้อง
     * ห้องที่ mark → no_show, ห้องที่เหลือ → confirmed, booking → ยัง confirmed
     */
    public function test_admin_can_mark_partial_no_show(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum');
        $roomType = $this->createRoomType();
        $booking = $this->createBooking(['status' => 'confirmed']);
        $br1 = $this->createBookingRoom($booking, $roomType, null, 'confirmed');
        $br2 = $this->createBookingRoom($booking, $roomType, null, 'confirmed');

        // Mark เฉพาะ br1
        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/mark-no-show", [
            'verified_by' => $admin->id,
            'booking_room_ids' => [$br1->id],
        ]);

        $response->assertStatus(200);
        $this->assertEquals('no_show', $br1->fresh()->status);
        // อีกห้องยัง confirmed อยู่
        $this->assertEquals('confirmed', $br2->fresh()->status);
        // booking ยังไม่ complete (ยังมีห้อง confirmed)
        $this->assertEquals('confirmed', $booking->fresh()->status);
    }

    /**
     * 🌟 BUG #3: booking ยังเป็น paid → auto paid→confirmed→complete
     */
    public function test_no_show_auto_transitions_paid_to_confirmed(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum');
        $roomType = $this->createRoomType();
        // booking paid (จ่ายเงินแล้ว ยังไม่ confirm)
        $booking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);
        $br = $this->createBookingRoom($booking, $roomType, null, 'confirmed');

        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/mark-no-show", [
            'verified_by' => $admin->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals('no_show', $br->fresh()->status);
        // booking: paid → confirmed → complete (auto sync)
        $this->assertEquals('complete', $booking->fresh()->status);
    }

    /**
     * 🌟 BUG #4: reject BR ที่ไม่ใช่ confirmed ด้วย error ชัดเจน (ไม่ silent skip)
     */
    public function test_no_show_rejects_non_confirmed_br(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'sanctum');
        $roomType = $this->createRoomType();
        $booking = $this->createBooking(['status' => 'confirmed']);
        // BR ที่ checked_in แล้ว — ไม่ควร mark no-show ได้
        $br = $this->createBookingRoom($booking, $roomType, null, 'checked_in');

        $response = $this->postJson("/api/v1/front-desk/{$booking->id}/mark-no-show", [
            'verified_by' => $admin->id,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'error']);
        // state ต้องไม่เปลี่ยน (no silent mutation)
        $this->assertEquals('checked_in', $br->fresh()->status);
        $this->assertEquals('confirmed', $booking->fresh()->status);
    }
}