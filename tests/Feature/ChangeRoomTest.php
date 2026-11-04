<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\HousekeepingTask;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\StatusChangeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🚪 (2026-11-04) Change Room (in-stay move) + surcharge type upgrade tests
 *
 * คลุม 2 ฟีเจอร์:
 * 1. POST /front-desk/{bookingId}/rooms/{bookingRoomId}/change-room — ย้ายห้องจริงหลัง
 *    check-in + เปลี่ยนประเภทห้องแบบ upgrade-only (type ใหม่ต้องแพงกว่า)
 * 2. PUT /bookings/{bookingId}/rooms/{bookingRoomId} (surcharge mode) — เปลี่ยน room_type_id
 *    ได้แม้ room_id ถูกจัดแล้ว เมื่อ upgrade-only (ปลด room_id = null รอ auto-assign)
 */
class ChangeRoomTest extends TestCase
{
    use RefreshDatabase;

    // counter แทน rand() — กันเลขห้องชนกันเอง (precedent FrontDeskTest/BookingTest)
    private static int $roomSeq = 0;

    private function createRoomTypeWithRate(string $nameEn, int $maxGuests, int $dailyRate): RoomType
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => $nameEn,
            'name_th' => $nameEn,
            'max_guests' => $maxGuests,
            'extra_bed_enabled' => false,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'code' => null,
            'name_en' => $nameEn.' Daily',
            'default_price' => $dailyRate,
            'is_active' => true,
        ]);

        return $rt;
    }

    private function createRoom(RoomType $roomType, string $status = 'available'): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '20'.(++self::$roomSeq),
            'status' => $status,
        ]);
    }

    private function createBooking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        return Booking::create(array_merge([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'confirmed',
            'total_amount' => 3000,
        ], $overrides));
    }

    private function createBookingRoom(Booking $booking, RoomType $roomType, ?Room $room = null, string $brStatus = 'confirmed', int $guestCount = 1): BookingRoom
    {
        $guests = [];
        for ($i = 0; $i < $guestCount; $i++) {
            $guests[] = ['title' => 'mr', 'name' => 'Guest '.$i, 'nationality' => 'TH'];
        }

        return BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => $room?->id,
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'guests' => $guests,
            'status' => $brStatus,
        ]);
    }

    private function createStaff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function endpoint(Booking $booking, BookingRoom $br): string
    {
        return "/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room";
    }

    // ============================================
    // 🚪 1. Same-type move (ย้ายห้องกลางทาง)
    // ============================================

    public function test_staff_can_move_checked_in_room_to_same_type_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000, 'is_paid' => true]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in');
        $newRoom = $this->createRoom($standard, 'available');
        Payment::create(['booking_id' => $booking->id, 'amount' => 3000, 'status' => 'completed']);

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');

        // BR ยัง checked_in แต่ชี้ห้องใหม่ (type คงเดิม)
        $this->assertDatabaseHas('booking_rooms', [
            'id' => $br->id,
            'room_id' => $newRoom->id,
            'room_type_id' => $standard->id,
            'status' => 'checked_in',
        ]);

        // ห้องเก่า → checkout_makeup + งานแม่บ้าน · ห้องใหม่ → occupied
        $this->assertSame('checkout_makeup', $oldRoom->fresh()->status);
        $this->assertSame('occupied', $newRoom->fresh()->status);
        $this->assertDatabaseHas('housekeeping_tasks', [
            'room_id' => $oldRoom->id,
            'task_type' => 'checkout',
            'status' => 'unassigned',
        ]);

        // same-type move → ยอดไม่เปลี่ยน
        $this->assertSame(0, $response->json('surcharge_amount'));
        $this->assertSame(0, $response->json('outstanding_amount'));

        // audit ครบ: room transition ×2 + note row ของ BR
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room',
            'entity_id' => $oldRoom->id,
            'from_status' => 'occupied',
            'to_status' => 'checkout_makeup',
        ]);
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room',
            'entity_id' => $newRoom->id,
            'from_status' => 'available',
            'to_status' => 'occupied',
        ]);
        $noteRow = StatusChangeLog::where('entity_type', 'booking_room')
            ->where('entity_id', $br->id)
            ->whereNotNull('note')
            ->first();
        $this->assertNotNull($noteRow, 'ควรมี audit note row ของ booking_room');
        $this->assertStringContainsString($oldRoom->room_number, $noteRow->note);
        $this->assertStringContainsString($newRoom->room_number, $noteRow->note);

        $this->assertAmountInvariant($booking->fresh());
    }

    // ============================================
    // 🎫 2. Upgrade type (แพงกว่า) → success + reprice
    // ============================================

    public function test_staff_can_upgrade_room_type_when_pricier_and_bill_reprices(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $booking = $this->createBooking(['total_amount' => 3000, 'is_paid' => true]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in');
        $newRoom = $this->createRoom($deluxe, 'available');
        Payment::create(['booking_id' => $booking->id, 'amount' => 3000, 'status' => 'completed']);

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertTrue($response->json('room_type_changed'));

        // BR อัปเกรดเป็น deluxe + ชี้ห้อง deluxe
        $this->assertDatabaseHas('booking_rooms', [
            'id' => $br->id,
            'room_id' => $newRoom->id,
            'room_type_id' => $deluxe->id,
            'status' => 'checked_in',
        ]);

        // 💰 full-stay reprice: 2500 × 2 คืน = 5000 · surcharge 2000 · ค้างชำระ 2000 · is_paid reset
        $this->assertSame(5000, $response->json('total_amount'));
        $this->assertSame(3000, $response->json('previous_total_amount'));
        $this->assertSame(2000, $response->json('surcharge_amount'));
        $this->assertSame(2000, $response->json('outstanding_amount'));
        $this->assertFalse($booking->fresh()->is_paid);
        $this->assertAmountInvariant($booking->fresh());

        // ห้องเก่ายังต้องเก็บ (checkout_makeup + task) และห้องใหม่ occupied
        $this->assertSame('checkout_makeup', $oldRoom->fresh()->status);
        $this->assertSame('occupied', $newRoom->fresh()->status);
        $this->assertDatabaseHas('housekeeping_tasks', ['room_id' => $oldRoom->id, 'task_type' => 'checkout']);
    }

    // ============================================
    // 🚫 3. Guards ของ change-room
    // ============================================

    public function test_change_room_rejects_cheaper_room_type(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $booking = $this->createBooking(['total_amount' => 5000]);
        $oldRoom = $this->createRoom($deluxe, 'occupied');
        $br = $this->createBookingRoom($booking, $deluxe, $oldRoom, 'checked_in');
        $newRoom = $this->createRoom($standard, 'available');

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('แพงกว่า', $response->json('message'));

        // ไม่มีอะไรถูกแตะ (rollback ทั้ง transaction)
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id, 'room_id' => $oldRoom->id]);
        $this->assertSame('occupied', $oldRoom->fresh()->status);
        $this->assertSame('available', $newRoom->fresh()->status);
    }

    public function test_change_room_rejects_room_not_ready_for_checkin(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in');

        foreach (['occupied', 'dirty', 'checkout_makeup'] as $status) {
            $newRoom = $this->createRoom($standard, $status);

            $response = $this->actingAs($this->createStaff(), 'sanctum')
                ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

            $response->assertStatus(422);
        }
    }

    public function test_change_room_rejects_room_holding_overlapping_slot(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in');

        // ห้องใหม่มี BR อื่น (confirmed = holdingSlot) ทับช่วงเดียวกัน
        $newRoom = $this->createRoom($standard, 'available');
        $otherBooking = $this->createBooking(['total_amount' => 3000]);
        $this->createBookingRoom($otherBooking, $standard, $newRoom, 'confirmed');

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ทับช่วงเข้าพัก', $response->json('message'));
    }

    public function test_change_room_rejects_room_under_maintenance_period(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in');

        $newRoom = $this->createRoom($standard, 'available');
        RoomStatePeriod::create([
            'room_id' => $newRoom->id,
            'kind' => RoomStatePeriod::KIND_MAINTENANCE,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => null, // เปิดปลาย — ครอบทับช่วงพัก BR ทั้งหมด
        ]);

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ซ่อมแซม', $response->json('message'));
    }

    public function test_change_room_rejects_when_guests_exceed_new_type_max(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 4, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500); // max 2 คน
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in', guestCount: 3);
        $newRoom = $this->createRoom($deluxe, 'available');

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('เกินจำนวนสูงสุด', $response->json('message'));
    }

    public function test_change_room_requires_checked_in_booking_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'available');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'confirmed'); // ยังไม่เช็คอิน
        $newRoom = $this->createRoom($standard, 'available');

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('checked_in', $response->json('message'));
    }

    public function test_change_room_rejects_same_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in');

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $oldRoom->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('อยู่ห้องนี้อยู่แล้ว', $response->json('message'));
    }

    public function test_change_room_forbidden_for_non_staff(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in');
        $newRoom = $this->createRoom($standard, 'available');

        // เจ้าของ booking (role user) ยิงเอง → 403 (middleware + controller ตรวจคู่)
        $response = $this->actingAs($booking->user, 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(403);
    }

    // ============================================
    // 🎫 4. updateRoom surcharge mode — upgrade type ปลดห้อง
    // ============================================

    public function test_surcharge_update_room_can_upgrade_type_and_releases_assigned_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $booking = $this->createBooking(['total_amount' => 3000, 'is_paid' => true]);
        $assignedRoom = $this->createRoom($standard, 'available'); // pre-check-in — ยังไม่ occupied
        $deluxeRoom = $this->createRoom($deluxe, 'available'); // ปลายทาง — availability ต้องผ่าน
        $br = $this->createBookingRoom($booking, $standard, $assignedRoom, 'confirmed');
        Payment::create(['booking_id' => $booking->id, 'amount' => 3000, 'status' => 'completed']);

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');

        // ปลด room_id = null รอ auto-assign จัด type ใหม่ · type อัปเกรดแล้ว
        $this->assertDatabaseHas('booking_rooms', [
            'id' => $br->id,
            'room_id' => null,
            'room_type_id' => $deluxe->id,
        ]);

        // 💰 reprice: 2500 × 2 = 5000 · ค้างชำระ 2000 · is_paid reset
        $this->assertSame(5000, $response->json('total_amount'));
        $this->assertSame(2000, $response->json('surcharge_amount'));
        $this->assertSame(2000, $response->json('outstanding_amount'));
        $this->assertFalse($booking->fresh()->is_paid);
        $this->assertAmountInvariant($booking->fresh());

        // ห้องเก่า pre-check-in — ไม่แตะ status / ไม่สร้าง task
        $this->assertSame('available', $assignedRoom->fresh()->status);
        $this->assertSame(0, HousekeepingTask::where('room_id', $assignedRoom->id)->count());

        // audit note row บอกการปลดห้อง + upgrade
        $noteRow = StatusChangeLog::where('entity_type', 'booking_room')
            ->where('entity_id', $br->id)
            ->whereNotNull('note')
            ->first();
        $this->assertNotNull($noteRow);
        $this->assertStringContainsString($assignedRoom->room_number, $noteRow->note);
        $this->assertStringContainsString('Deluxe', $noteRow->note);
    }

    public function test_surcharge_update_room_rejects_downgrade_type_when_room_assigned(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $booking = $this->createBooking(['total_amount' => 5000]);
        $assignedRoom = $this->createRoom($deluxe, 'available');
        $br = $this->createBookingRoom($booking, $deluxe, $assignedRoom, 'confirmed');

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $standard->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('แพงกว่า', $response->json('message'));
        // ห้องยังผูกเดิม ไม่ถูกปลด
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id, 'room_id' => $assignedRoom->id]);
    }

    public function test_surcharge_update_room_still_blocks_dates_when_room_assigned(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $assignedRoom = $this->createRoom($standard, 'available');
        $br = $this->createBookingRoom($booking, $standard, $assignedRoom, 'confirmed');

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'check_in' => now()->addDays(3)->toDateString(),
                'check_out' => now()->addDays(5)->toDateString(),
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ปลดการจัดห้อง', $response->json('message'));
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id, 'room_id' => $assignedRoom->id]);
    }

    public function test_draft_mode_can_still_change_room_type_freely(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $booking = $this->createBooking(['status' => 'draft', 'total_amount' => 5000]);
        $standardRoom = $this->createRoom($standard, 'available'); // ปลายทาง — availability ต้องผ่าน
        $br = $this->createBookingRoom($booking, $deluxe, null, 'draft'); // ยังไม่จัดห้อง

        // draft mode — เจ้าของลด type ลงได้เสรี (regression: ไม่โดนกติกา upgrade-only)
        $response = $this->actingAs($booking->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $standard->id,
            ]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id, 'room_type_id' => $standard->id]);
        $this->assertSame(3000, (int) $booking->fresh()->total_amount);
        $this->assertAmountInvariant($booking->fresh());
    }
}
