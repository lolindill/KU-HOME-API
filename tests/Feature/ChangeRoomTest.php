<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
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
 *
 * 🚪 (2026-11-09): ขยาย change-room — รับ BR confirmed (สลับเลขห้องก่อนเช็คอิน · ไม่แตะ
 *    สถานะห้องทั้งสองฝั่ง ไม่สร้างงานแม่บ้าน) + ห้องปลายทาง dirty (occupied รับ dirty แล้ว)
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

        // 🧹 ห้องเก่า → dirty (2026-11-09: เดิม checkout_makeup) + งานแม่บ้าน 'checkout' · ห้องใหม่ → occupied
        $this->assertSame('dirty', $oldRoom->fresh()->status);
        $this->assertSame('occupied', $newRoom->fresh()->status);
        $this->assertDatabaseHas('housekeeping_tasks', [
            'room_id' => $oldRoom->id,
            'task_type' => 'checkout',
            'status' => 'unassigned',
        ]);
        // ปลายทาง available → ไม่มี task ห้องใหม่
        $this->assertNull($response->json('new_room_housekeeping_task_id'));

        // same-type move → ยอดไม่เปลี่ยน
        $this->assertSame(0, $response->json('surcharge_amount'));
        $this->assertSame(0, $response->json('outstanding_amount'));

        // audit ครบ: room transition ×2 + note row ของ BR
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room',
            'entity_id' => $oldRoom->id,
            'from_status' => 'occupied',
            'to_status' => 'dirty',
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

        // ห้องเก่ายังต้องเก็บ (dirty + task — 2026-11-09: เดิม checkout_makeup) และห้องใหม่ occupied
        $this->assertSame('dirty', $oldRoom->fresh()->status);
        $this->assertSame('occupied', $newRoom->fresh()->status);
        $this->assertDatabaseHas('housekeeping_tasks', ['room_id' => $oldRoom->id, 'task_type' => 'checkout']);
        $this->assertNull($response->json('new_room_housekeeping_task_id'));
    }

    // ============================================
    // 🚫 3. Guards ของ change-room
    // ============================================

    public function test_change_room_allows_downgrade_with_no_refund_and_is_paid_true(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $booking = $this->createBooking(['total_amount' => 5000, 'is_paid' => true]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 5000, 'status' => 'completed']);
        $oldRoom = $this->createRoom($deluxe, 'occupied');
        $br = $this->createBookingRoom($booking, $deluxe, $oldRoom, 'checked_in');
        $newRoom = $this->createRoom($standard, 'available');

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertTrue($response->json('room_type_changed'));

        // BR ลดเป็น standard + ชี้ห้อง standard
        $this->assertDatabaseHas('booking_rooms', [
            'id' => $br->id,
            'room_id' => $newRoom->id,
            'room_type_id' => $standard->id,
            'status' => 'checked_in',
        ]);

        // 💰 full-stay reprice downgrade: 1500 × 2 คืน = 3000 · surcharge -2000 · ค้างชำระ 0 · is_paid syncIsPaid true
        $this->assertSame(3000, $response->json('total_amount'));
        $this->assertSame(5000, $response->json('previous_total_amount'));
        $this->assertSame(-2000, $response->json('surcharge_amount'));
        $this->assertSame(0, $response->json('outstanding_amount'));
        $this->assertTrue((bool) $booking->fresh()->is_paid);
        $this->assertAmountInvariant($booking->fresh());

        // ห้องเก่า dirty + task checkout และห้องใหม่ occupied
        $this->assertSame('dirty', $oldRoom->fresh()->status);
        $this->assertSame('occupied', $newRoom->fresh()->status);
        $this->assertDatabaseHas('housekeeping_tasks', ['room_id' => $oldRoom->id, 'task_type' => 'checkout']);
    }

    public function test_change_room_rejects_room_not_ready_for_checkin(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in');

        // 🚪 (2026-11-09): dirty ถูกถอดออก — ห้อง dirty ย้ายเข้าได้แล้ว (occupied รับ dirty)
        foreach (['occupied', 'checkout_makeup'] as $status) {
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

    // 🚪 (tier-endpoints-v2 มติ v2 ข้อ 7): BR draft ที่มีห้องแล้ว ย้ายได้ก่อนเช็คอิน (เหมือน confirmed)
    //    ส่วน draft ที่ไม่มีห้อง → 422 ชี้ไม่มีห้องให้ย้ายออก, no_show/checked_out → 422
    public function test_change_room_gate_supports_draft_with_room_and_rejects_others(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['status' => 'paid', 'total_amount' => 3000, 'is_paid' => true]);
        $oldRoom = $this->createRoom($standard, 'available');
        $newRoom = $this->createRoom($standard, 'available');
        Payment::create(['booking_id' => $booking->id, 'amount' => 3000, 'status' => 'completed']);

        // 1. draft + มีห้อง → 200 (re-point ล้วน ไม่แตะห้องกายภาพ ไม่มี task)
        $brDraftWithRoom = $this->createBookingRoom($booking, $standard, $oldRoom, 'draft');
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $brDraftWithRoom), ['room_id' => $newRoom->id]);
        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('booking_rooms', ['id' => $brDraftWithRoom->id, 'room_id' => $newRoom->id, 'status' => 'draft']);
        $this->assertDatabaseCount('housekeeping_tasks', 0);

        // 2. draft + ไม่มีห้อง → 422
        $brDraftWithoutRoom = $this->createBookingRoom($booking, $standard, null, 'draft');
        $resNoRoom = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $brDraftWithoutRoom), ['room_id' => $newRoom->id]);
        $resNoRoom->assertStatus(422);
        $this->assertStringContainsString('ไม่มีห้องให้ย้ายออก', $resNoRoom->json('message'));

        // 3. no_show, checked_out → 422
        foreach (['no_show', 'checked_out'] as $brStatus) {
            $brOther = $this->createBookingRoom($booking, $standard, $oldRoom, $brStatus);

            $response = $this->actingAs($this->createStaff(), 'sanctum')
                ->postJson($this->endpoint($booking, $brOther), ['room_id' => $newRoom->id]);

            $response->assertStatus(422);
            $this->assertStringContainsString($brStatus, $response->json('message'));
        }
    }

    // ============================================
    // 🚪 3. BR confirmed — สลับเลขห้องก่อนเช็คอิน + ห้องปลายทาง dirty (2026-11-09)
    // ============================================

    public function test_staff_can_move_confirmed_booking_room_before_checkin_without_touching_rooms(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['status' => 'paid', 'total_amount' => 3000, 'is_paid' => true]);
        $oldRoom = $this->createRoom($standard, 'available');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'confirmed'); // ยังไม่เช็คอิน
        $newRoom = $this->createRoom($standard, 'available');
        Payment::create(['booking_id' => $booking->id, 'amount' => 3000, 'status' => 'completed']);

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');

        // BR คง confirmed แต่ชี้ห้องใหม่
        $this->assertDatabaseHas('booking_rooms', [
            'id' => $br->id,
            'room_id' => $newRoom->id,
            'room_type_id' => $standard->id,
            'status' => 'confirmed',
        ]);

        // ห้องทั้งสองฝั่งไม่ถูก transition — แขกยังไม่เคยเข้าพัก = ไม่มี checkout
        $this->assertSame('available', $oldRoom->fresh()->status);
        $this->assertSame('available', $newRoom->fresh()->status);
        $this->assertDatabaseCount('housekeeping_tasks', 0);
        $this->assertNull($response->json('housekeeping_task_id'));
        $this->assertNull($response->json('new_room_housekeeping_task_id'));

        // ไม่มี room status log (ไม่มี transition) — มีแค่ note row ของ BR (from=to=confirmed)
        $this->assertSame(0, StatusChangeLog::where('entity_type', 'room')->count());
        $noteRow = StatusChangeLog::where('entity_type', 'booking_room')
            ->where('entity_id', $br->id)
            ->whereNotNull('note')
            ->first();
        $this->assertNotNull($noteRow, 'ควรมี audit note row ของ booking_room');
        $this->assertSame('confirmed', $noteRow->from_status);
        $this->assertSame('confirmed', $noteRow->to_status);
        $this->assertStringContainsString($oldRoom->room_number, $noteRow->note);
        $this->assertStringContainsString($newRoom->room_number, $noteRow->note);

        // same-type move → ยอดไม่เปลี่ยน is_paid คงเดิม
        $this->assertSame(0, $response->json('surcharge_amount'));
        $this->assertTrue($booking->fresh()->is_paid);

        $this->assertAmountInvariant($booking->fresh());
    }

    public function test_confirmed_booking_room_can_move_into_dirty_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'available');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'confirmed');
        $newRoom = $this->createRoom($standard, 'dirty');

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');

        // BR confirmed → ห้องใหม่คง dirty รอแม่บ้านเก็บก่อนเช็คอิน · ห้องเก่าไม่ถูกแตะ
        $this->assertSame('dirty', $newRoom->fresh()->status);
        $this->assertSame('available', $oldRoom->fresh()->status);
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id, 'room_id' => $newRoom->id]);

        // 🧹 (tier-endpoints-v2 มติ v2 ข้อ 4): BR confirmed = re-point ล้วน ไม่แตะสถานะห้องกายภาพทั้งเก่า/ใหม่ ไม่สร้าง task
        $this->assertDatabaseCount('housekeeping_tasks', 0);
        $this->assertNull($response->json('new_room_housekeeping_task_id'));
        $this->assertNull($response->json('housekeeping_task_id'));
    }

    public function test_checked_in_room_can_move_into_dirty_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in');
        $newRoom = $this->createRoom($standard, 'dirty');

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), ['room_id' => $newRoom->id]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');

        // in-stay → ห้องใหม่ occupied จาก dirty (machine รับ dirty→occupied แล้ว)
        $this->assertSame('occupied', $newRoom->fresh()->status);
        // ห้องเก่า → dirty (2026-11-09: เดิม checkout_makeup)
        $this->assertSame('dirty', $oldRoom->fresh()->status);

        // task ครบ 2 ตัว: ห้องเก่า 'checkout' + ห้อง dirty ปลายทาง 'daily' (สร้างก่อน transition)
        $this->assertDatabaseHas('housekeeping_tasks', [
            'room_id' => $oldRoom->id,
            'task_type' => 'checkout',
            'status' => 'unassigned',
        ]);
        $this->assertDatabaseHas('housekeeping_tasks', [
            'room_id' => $newRoom->id,
            'task_type' => 'daily',
            'status' => 'unassigned',
        ]);
        $this->assertNotNull($response->json('housekeeping_task_id'));
        $this->assertNotNull($response->json('new_room_housekeeping_task_id'));
        $this->assertNotSame(
            $response->json('housekeeping_task_id'),
            $response->json('new_room_housekeeping_task_id')
        );

        // audit room: เก่า occupied → dirty · ใหม่ dirty → occupied
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room',
            'entity_id' => $oldRoom->id,
            'from_status' => 'occupied',
            'to_status' => 'dirty',
        ]);
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room',
            'entity_id' => $newRoom->id,
            'from_status' => 'dirty',
            'to_status' => 'occupied',
        ]);
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

    public function test_surcharge_update_room_rejects_when_room_assigned_and_points_to_change_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $booking = $this->createBooking(['total_amount' => 3000, 'is_paid' => true]);
        $assignedRoom = $this->createRoom($standard, 'available'); // pre-check-in — ยังไม่ occupied
        $br = $this->createBookingRoom($booking, $standard, $assignedRoom, 'confirmed');
        Payment::create(['booking_id' => $booking->id, 'amount' => 3000, 'status' => 'completed']);

        // 🎫 (tier-endpoints-v2 มติ v2 ข้อ 3): BR ที่มีเลขห้องจริงแล้ว ห้ามยิง PUT โหมด 2 → 422 ชี้ไป change-room
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('change-room', $response->json('message'));
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id, 'room_id' => $assignedRoom->id]);
    }

    public function test_surcharge_update_room_allows_downgrade_when_no_room_assigned(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $booking = $this->createBooking(['total_amount' => 5000, 'is_paid' => true]);
        $standardRoom = $this->createRoom($standard, 'available');
        $br = $this->createBookingRoom($booking, $deluxe, null, 'confirmed'); // ไม่มีเลขห้อง
        Payment::create(['booking_id' => $booking->id, 'amount' => 5000, 'status' => 'completed']);

        // 🎫 (tier-endpoints-v2 มติ v2 ข้อ 1 & 3): BR ไม่มีห้อง ยิง downgrade ในโหมด 2 ผ่าน + syncIsPaid
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $standard->id,
            ]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('booking_rooms', ['id' => $br->id, 'room_type_id' => $standard->id]);
        $this->assertSame(3000, (int) $booking->fresh()->total_amount);
        $this->assertSame(-2000, $response->json('surcharge_amount'));
        $this->assertSame(0, $response->json('outstanding_amount'));
        $this->assertTrue((bool) $booking->fresh()->is_paid);
    }

    public function test_surcharge_update_room_allows_dates_when_no_room_assigned(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $booking = $this->createBooking(['total_amount' => 3000, 'is_paid' => true]);
        $room = $this->createRoom($standard, 'available');
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed'); // ไม่มีห้อง
        Payment::create(['booking_id' => $booking->id, 'amount' => 3000, 'status' => 'completed']);

        $newCheckIn = now()->addDays(5)->toDateString();
        $newCheckOut = now()->addDays(7)->toDateString();

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'check_in' => $newCheckIn,
                'check_out' => $newCheckOut,
            ]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertSame($newCheckIn, $br->fresh()->check_in->toDateString());
        $this->assertSame($newCheckOut, $br->fresh()->check_out->toDateString());
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
