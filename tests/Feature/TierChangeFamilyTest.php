<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\User;
use App\Support\BookingRule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🏨 TierChangeFamilyTest — HTTP Feature test suite สำหรับครอบครัวการเปลี่ยน tier และย้ายห้อง v2
 *
 * อ้างอิง: wayfinder/tier-endpoints-v2/tickets/03-tests-v2.md
 * ครอบคลุม:
 *   - B. PUT โหมด 2 (BookingController::updateRoom)
 *   - C. change-room ขยาย (FrontDeskController::changeRoom)
 */
class TierChangeFamilyTest extends TestCase
{
    use RefreshDatabase;

    private static int $roomSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function createStaff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function createRoomTypeWithRate(
        string $nameEn,
        int $maxGuests,
        int $dailyRate,
        int $maxExtraBeds = 2,
        bool $extraBedEnabled = true
    ): RoomType {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => $nameEn,
            'name_th' => $nameEn,
            'max_guests' => $maxGuests,
            'max_extra_beds' => $maxExtraBeds,
            'extra_bed_enabled' => $extraBedEnabled,
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
            'room_number' => '50'.(++self::$roomSeq),
            'status' => $status,
        ]);
    }

    private function createBooking(array $overrides = []): Booking
    {
        $user = $overrides['user'] ?? User::factory()->create();
        unset($overrides['user']);

        return Booking::create(array_merge([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'confirmed',
            'total_amount' => 3000,
            'payment_type' => 'full',
            'is_paid' => true,
        ], $overrides));
    }

    private function createBookingRoom(
        Booking $booking,
        RoomType $roomType,
        ?Room $room = null,
        string $brStatus = 'confirmed',
        int $guestCount = 1,
        array $overrides = []
    ): BookingRoom {
        $guests = [];
        for ($i = 0; $i < $guestCount; $i++) {
            $guests[] = [
                'title' => 'mr',
                'firstName' => 'Guest',
                'lastName' => (string) $i,
                'email' => "guest{$i}@test.com",
                'phone' => '0812345678',
                'nationality' => 'TH',
            ];
        }

        $today = Carbon::today(BookingRule::TIMEZONE);
        $checkIn = $overrides['check_in'] ?? $today->toDateString();
        $checkOut = $overrides['check_out'] ?? $today->copy()->addDays(2)->toDateString();

        return BookingRoom::create(array_merge([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => $room?->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => $guests,
            'status' => $brStatus,
            'room_amount' => $overrides['room_amount'] ?? 3000,
            'discount_amount' => 0,
            'amount' => $overrides['amount'] ?? 3000,
        ], $overrides));
    }

    // =========================================================
    // 🅱️ B. PUT โหมด 2 (BookingController::updateRoom)
    // =========================================================

    public function test_put_mode_2_staff_allowed_and_updates_tier(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available'); // ต้องมีห้องว่างใน pool สำหรับ availability check

        // จอง 2 คืน Standard = 2000 จ่ายแล้ว 2000
        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // staff ยิง PUT โหมด 2 โดยไม่ส่ง apply_new_rate -> เปลี่ยน tier ได้ แต่ default คงราคาเดิม (surcharge 0)
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('surcharge_amount', 0);

        $this->assertSame((string) $deluxe->id, (string) $br->fresh()->room_type_id);
        $this->assertSame(2000, (int) $br->fresh()->room_amount);
        $this->assertSame(2000, (int) $booking->fresh()->total_amount);
        $this->assertSame(0, (int) $booking->fresh()->outstanding_amount);
        $this->assertTrue((bool) $booking->fresh()->is_paid);
    }

    public function test_put_mode_2_with_apply_new_rate_true_recalculates_surcharge(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
                'apply_new_rate' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('surcharge_amount', 1000);

        $this->assertSame((string) $deluxe->id, (string) $br->fresh()->room_type_id);
        // 2 คืน × 1500 = 3000 -> surcharge 1000
        $this->assertSame(3000, (int) $booking->fresh()->total_amount);
        $this->assertSame(1000, (int) $booking->fresh()->outstanding_amount);
        $this->assertFalse((bool) $booking->fresh()->is_paid);
    }

    public function test_put_mode_2_staff_manual_price_per_night(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // staff manual price 1800 บาท/คืน (2 คืน = 3600)
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1800,
            ]);

        $response->assertStatus(200);
        $this->assertSame(3600, (int) $booking->fresh()->total_amount);
        $this->assertSame(1600, (int) $booking->fresh()->outstanding_amount);
    }

    public function test_put_mode_2_downgrade_reduces_total_clamps_outstanding_and_sets_is_paid_true(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2000);
        $this->createRoom($standard, 'available');

        // เดิม Deluxe 2 คืน = 4000 จ่ายครบ 4000
        $booking = $this->createBooking(['total_amount' => 4000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 4000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $deluxe, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'amount' => 4000,
        ]);

        // downgrade เป็น Standard (1000 × 2 = 2000) โดยเปิด apply_new_rate=true
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $standard->id,
                'apply_new_rate' => true,
            ]);

        $response->assertStatus(200);
        $this->assertSame(2000, (int) $booking->fresh()->total_amount);
        $this->assertSame(-2000, (int) $response->json('surcharge_amount'));
        $this->assertSame(0, (int) $booking->fresh()->outstanding_amount);
        // paid 4000 >= total 2000 -> is_paid กลับ true!
        $this->assertTrue((bool) $booking->fresh()->is_paid);
    }

    public function test_put_mode_2_rejects_br_with_assigned_room_pointing_to_change_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $room = $this->createRoom($standard);

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $room, 'confirmed');

        // BR มี room_id อยู่แล้ว ยิง PUT โหมด 2 -> 422 ชี้ไป change-room
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('change-room', $response->json('message'));
    }

    public function test_put_mode_2_rejects_param_room_id(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $room = $this->createRoom($deluxe);

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed');

        // ส่ง param room_id เข้ามาในโหมด 2 -> 422
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
                'room_id' => $room->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('change-room', $response->json('message'));
    }

    public function test_put_mode_2_validates_guests_cap_against_new_tier(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 4, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500); // max 2
        $this->createRoom($deluxe, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 3); // 3 guests

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ปรับลดผู้เข้าพัก', $response->json('message'));
    }

    public function test_put_mode_2_validates_extra_beds_cap_against_new_tier(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000, 3);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500, 1); // max extra bed = 1
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1);

        Addon::create([
            'booking_room_id' => $br->id,
            'extra_beds_by_night' => [
                $today->toDateString() => 2, // 2 เตียง เกิน 1 ของ deluxe
            ],
        ]);

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('เตียงเสริม', $response->json('message'));
    }

    public function test_put_mode_2_preserves_group_rate_reprice_pipeline(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2000);
        $this->createRoom($deluxe, 'available');

        GlobalRate::create([
            'rate_type' => 'group',
            'room_type_id' => $deluxe->id,
            'code' => 'min_5_rooms',
            'name_en' => 'Deluxe Group 5',
            'default_price' => 1600, // group rate = 1600 (ถูกกว่า 2000)
            'is_active' => true,
        ]);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['source' => 'admin', 'total_amount' => 10000]);

        $brs = [];
        for ($i = 0; $i < 5; $i++) {
            $brs[] = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
                'room_amount' => 2000,
                'amount' => 2000,
            ]);
        }

        // แก้ห้องที่ 0 ให้เป็น Deluxe + เปิด apply_new_rate=true -> บิล 5 ห้อง เข้าเงื่อนไข group rate ของ Deluxe
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$brs[0]->id}", [
                'room_type_id' => $deluxe->id,
                'apply_new_rate' => true,
            ]);

        $response->assertStatus(200);
        // ห้องที่ 0 เป็น Deluxe 2 คืน × 1600 (group rate) = 3200
        $this->assertSame(3200, (int) $brs[0]->fresh()->room_amount);
    }

    // =========================================================
    // 🚪 C. change-room ขยาย (FrontDeskController::changeRoom)
    // =========================================================

    public function test_change_room_staff_manual_price_per_night(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $roomStandard = $this->createRoom($standard, 'occupied');
        $roomDeluxe = $this->createRoom($deluxe, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $roomStandard, 'checked_in', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // staff manual price 1700 บาท/คืน (2 คืน = 3400)
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $roomDeluxe->id,
                'price_per_night' => 1700,
            ]);

        $response->assertStatus(200);
        $this->assertSame(3400, (int) $booking->fresh()->total_amount);
        $this->assertSame(1400, (int) $booking->fresh()->outstanding_amount);
    }

    public function test_change_room_downgrade_reduces_total_clamps_outstanding_and_syncs_is_paid(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2000);

        $roomDeluxe = $this->createRoom($deluxe, 'occupied');
        $roomStandard = $this->createRoom($standard, 'available');

        // เดิม Deluxe 2 คืน = 4000 จ่ายครบ 4000
        $booking = $this->createBooking(['total_amount' => 4000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 4000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $deluxe, $roomDeluxe, 'checked_in', 1, [
            'room_amount' => 4000,
            'amount' => 4000,
        ]);

        // downgrade เป็น Standard (1000 × 2 = 2000) โดยเปิด apply_new_rate=true
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $roomStandard->id,
                'apply_new_rate' => true,
            ]);

        $response->assertStatus(200);
        $this->assertSame(2000, (int) $booking->fresh()->total_amount);
        $this->assertSame(-2000, (int) $response->json('surcharge_amount'));
        $this->assertSame(0, (int) $booking->fresh()->outstanding_amount);
        $this->assertTrue((bool) $booking->fresh()->is_paid);
    }

    public function test_change_room_confirmed_br_moves_without_touching_physical_rooms_or_housekeeping(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);

        $room1 = $this->createRoom($standard, 'available');
        $room2 = $this->createRoom($standard, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $room1, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $room2->id,
            ]);

        $response->assertStatus(200);
        // ห้องทางกายภาพไม่เปลี่ยนสถานะ และไม่มี task
        $this->assertSame('available', $room1->fresh()->status);
        $this->assertSame('available', $room2->fresh()->status);
        $this->assertDatabaseCount('housekeeping_tasks', 0);
    }

    public function test_change_room_checked_in_br_runs_full_housekeeping_ceremony(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);

        $room1 = $this->createRoom($standard, 'occupied');
        $room2 = $this->createRoom($standard, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $room1, 'checked_in', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $room2->id,
            ]);

        $response->assertStatus(200);
        // ห้องเก่า dirty + task checkout, ห้องใหม่ occupied
        $this->assertSame('dirty', $room1->fresh()->status);
        $this->assertSame('occupied', $room2->fresh()->status);
        $this->assertDatabaseHas('housekeeping_tasks', [
            'room_id' => $room1->id,
            'task_type' => 'checkout',
        ]);
    }

    public function test_change_room_validates_maintenance_and_holding_slot_overlap(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);

        $room1 = $this->createRoom($standard, 'occupied');
        $roomMaint = $this->createRoom($standard, 'available');
        $roomOverlap = $this->createRoom($standard, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        $br = $this->createBookingRoom($booking, $standard, $room1, 'checked_in', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // 1. ติด maintenance
        RoomStatePeriod::create([
            'room_id' => $roomMaint->id,
            'kind' => RoomStatePeriod::KIND_MAINTENANCE,
            'start_date' => $br->check_in->toDateString(),
            'end_date' => $br->check_out->toDateString(),
        ]);

        $resMaint = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $roomMaint->id,
            ]);
        $resMaint->assertStatus(422);
        $this->assertStringContainsString('ซ่อมแซม', $resMaint->json('message'));

        // 2. Overlap holdingSlot ของ BR อื่น
        $bookingOther = $this->createBooking();
        $this->createBookingRoom($bookingOther, $standard, $roomOverlap, 'confirmed', 1, [
            'check_in' => $br->check_in->toDateString(),
            'check_out' => $br->check_out->toDateString(),
        ]);

        $resOverlap = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $roomOverlap->id,
            ]);
        $resOverlap->assertStatus(422);
        $this->assertStringContainsString('ทับช่วงเข้าพัก', $resOverlap->json('message'));
    }

    // =========================================================
    // 🎚️ D. Default keep-price + apply_new_rate toggle (2026-11-16)
    // =========================================================

    public function test_put_mode_2_downgrade_defaults_to_keeping_original_price(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2000);
        $this->createRoom($standard, 'available');

        $booking = $this->createBooking(['total_amount' => 4000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 4000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $deluxe, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'amount' => 4000,
        ]);

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $standard->id,
                'apply_new_rate' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('surcharge_amount', 0)
            ->assertJsonPath('total_amount', 4000)
            ->assertJsonPath('outstanding_amount', 0);

        $this->assertSame((string) $standard->id, (string) $br->fresh()->room_type_id);
        $this->assertSame(4000, (int) $br->fresh()->room_amount);
        $this->assertTrue((bool) $booking->fresh()->is_paid);
    }

    public function test_put_mode_2_manual_price_wins_over_apply_new_rate(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $this->createRoom($deluxe, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // ส่งทั้ง apply_new_rate=true และ price_per_night=1300 -> manual price ต้องชนะ (1300×2=2600 ไม่ใช่ 5000)
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
                'apply_new_rate' => true,
                'price_per_night' => 1300,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('total_amount', 2600)
            ->assertJsonPath('surcharge_amount', 600)
            ->assertJsonPath('outstanding_amount', 600);
    }

    public function test_put_mode_2_tier_change_with_addon_keeps_room_price_and_adds_addon_only(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $this->createRoom($deluxe, 'available');
        GlobalRate::create([
            'rate_type' => 'addon',
            'code' => 'breakfast_200',
            'name_en' => 'Breakfast 200',
            'default_price' => 200,
            'is_active' => true,
        ]);

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // อัปเกรด Standard->Deluxe (default ไม่คิดค่าห้องเพิ่ม) + เพิ่มอาหารเช้า 200 (2 คืน = 400)
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
                'addons' => [
                    'breakfast_sets' => ['set_200' => 1],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('total_amount', 2400)
            ->assertJsonPath('surcharge_amount', 400)
            ->assertJsonPath('outstanding_amount', 400);

        $this->assertSame(2000, (int) $br->fresh()->room_amount);
        $this->assertSame(2400, (int) $br->fresh()->amount);
    }

    public function test_put_mode_2_changing_nights_still_recalculates_room_price(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // ขยายวันพัก 2 คืน -> 3 คืน โดยไม่ส่ง apply_new_rate -> ต้องคิดค่าห้อง 3 คืนปกติ (3000)
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'check_out' => $br->check_in->copy()->addDays(3)->toDateString(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('total_amount', 3000)
            ->assertJsonPath('surcharge_amount', 1000)
            ->assertJsonPath('outstanding_amount', 1000);
    }

    public function test_put_mode_2_br_draft_under_paid_booking_defaults_to_keeping_price_and_toggles_on(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        // บิล paid แต่ BR ยัง draft (ยังไม่จัดเลขห้อง)
        $booking = $this->createBooking(['status' => 'paid', 'total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'draft', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // 1) Default (ไม่ส่ง apply_new_rate) -> เปลี่ยนเป็น Deluxe ฟรี (surcharge 0)
        $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('total_amount', 2000)
            ->assertJsonPath('surcharge_amount', 0);

        // 2) ส่ง apply_new_rate=true -> คำนวณเรท Deluxe ใหม่ (1500×2=3000)
        $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'apply_new_rate' => true,
            ])
            ->assertStatus(200)
            ->assertJsonPath('total_amount', 3000)
            ->assertJsonPath('surcharge_amount', 1000)
            ->assertJsonPath('outstanding_amount', 1000);
    }

    public function test_put_mode_1_draft_booking_ignores_apply_new_rate_false_and_always_reprices(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);
        $this->createRoom($standard, 'available');
        $this->createRoom($deluxe, 'available');

        $owner = User::factory()->create(['role' => 'user']);
        $booking = $this->createBooking(['user' => $owner, 'status' => 'draft', 'total_amount' => 2000, 'is_paid' => false]);
        $br = $this->createBookingRoom($booking, $standard, null, 'draft', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // บิล draft: แม้ส่ง apply_new_rate=false (หรือ admin แก้ draft) ต้องคิดราคาห้องใหม่เสมอ (2500×2=5000)
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
                'apply_new_rate' => false,
            ])
            ->assertStatus(200)
            ->assertJsonPath('total_amount', 5000)
            ->assertJsonPath('surcharge_amount', 3000);

        // ฝั่ง admin บน draft ก็คิดราคาใหม่เช่นกัน
        $this->actingAs($this->createAdmin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $standard->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('total_amount', 2000);
    }

    public function test_put_mode_3_checked_in_rejects_apply_new_rate(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking(['total_amount' => 2000]);
        $br = $this->createBookingRoom($booking, $standard, null, 'checked_in', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'apply_new_rate' => true,
                'addons' => ['breakfast_sets' => ['set_200' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'ห้องเข้าพักแล้วรับเฉพาะการเพิ่ม/แก้ addon เท่านั้นค่ะ 🏨');
    }

    public function test_apply_new_rate_rejects_non_boolean_on_both_endpoints(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $room1 = $this->createRoom($standard, 'occupied');
        $room2 = $this->createRoom($deluxe, 'available');

        $booking = $this->createBooking(['total_amount' => 2000]);
        $brNoRoom = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);
        $brWithRoom = $this->createBookingRoom($booking, $standard, $room1, 'checked_in', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        $staff = $this->createStaff();

        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$brNoRoom->id}", [
                'room_type_id' => $deluxe->id,
                'apply_new_rate' => 'not-a-bool',
            ])
            ->assertStatus(422);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$brWithRoom->id}/change-room", [
                'room_id' => $room2->id,
                'apply_new_rate' => 'not-a-bool',
            ])
            ->assertStatus(422);
    }

    public function test_change_room_upgrade_and_downgrade_default_to_keeping_original_price(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);

        $roomStandard = $this->createRoom($standard, 'occupied');
        $roomDeluxe = $this->createRoom($deluxe, 'available');
        $roomStandard2 = $this->createRoom($standard, 'available');

        $booking = $this->createBooking(['total_amount' => 2000, 'is_paid' => true]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $roomStandard, 'checked_in', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // 1) Upgrade Standard -> Deluxe โดยไม่ส่ง apply_new_rate -> คงราคาเดิม (2000, surcharge 0) + ceremony ทำงานปกติ
        $resUp = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $roomDeluxe->id,
            ]);

        $resUp->assertStatus(200)
            ->assertJsonPath('room_type_changed', true)
            ->assertJsonPath('total_amount', 2000)
            ->assertJsonPath('surcharge_amount', 0)
            ->assertJsonPath('outstanding_amount', 0);

        $this->assertTrue((bool) $booking->fresh()->is_paid);
        $this->assertSame('dirty', $roomStandard->fresh()->status);
        $this->assertSame('occupied', $roomDeluxe->fresh()->status);

        // 2) Downgrade Deluxe -> Standard โดยส่ง apply_new_rate=false -> ยังคงราคาเดิม 2000 (ไม่ลดราคา)
        $resDown = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $roomStandard2->id,
                'apply_new_rate' => false,
            ]);

        $resDown->assertStatus(200)
            ->assertJsonPath('room_type_changed', true)
            ->assertJsonPath('total_amount', 2000)
            ->assertJsonPath('surcharge_amount', 0)
            ->assertJsonPath('outstanding_amount', 0);
    }

    public function test_change_room_multi_room_default_preserves_other_room_custom_price(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1500);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);

        $room1 = $this->createRoom($standard, 'available');
        $room2 = $this->createRoom($standard, 'occupied');
        $roomDeluxe = $this->createRoom($deluxe, 'available');

        // บิล 2 ห้อง: ห้อง 1 ราคาพิเศษ 2400 (เรทวันนี้ 3000) + ห้อง 2 ราคา 2000 = 4400
        $booking = $this->createBooking(['total_amount' => 4400, 'is_paid' => true]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 4400, 'status' => 'completed']);
        $br1 = $this->createBookingRoom($booking, $standard, $room1, 'confirmed', 1, [
            'room_amount' => 2400,
            'amount' => 2400,
        ]);
        $br2 = $this->createBookingRoom($booking, $standard, $room2, 'checked_in', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // ย้ายห้อง 2 -> Deluxe โดยไม่ส่ง apply_new_rate -> ทั้งห้อง 1 (2400) และห้อง 2 (2000) ต้องคงราคาเดิม
        $res = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br2->id}/change-room", [
                'room_id' => $roomDeluxe->id,
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('total_amount', 4400)
            ->assertJsonPath('surcharge_amount', 0)
            ->assertJsonPath('outstanding_amount', 0);

        $this->assertSame(2400, (int) $br1->fresh()->room_amount);
        $this->assertSame(2000, (int) $br2->fresh()->room_amount);
    }

    public function test_change_room_manual_price_wins_over_apply_new_rate(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2500);

        $roomStandard = $this->createRoom($standard, 'occupied');
        $roomDeluxe = $this->createRoom($deluxe, 'available');

        $booking = $this->createBooking(['total_amount' => 2000, 'is_paid' => true]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $roomStandard, 'checked_in', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // ส่งทั้ง apply_new_rate=true และ price_per_night=1400 -> manual price ต้องชนะ (1400×2=2800)
        $res = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $roomDeluxe->id,
                'apply_new_rate' => true,
                'price_per_night' => 1400,
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('total_amount', 2800)
            ->assertJsonPath('surcharge_amount', 800)
            ->assertJsonPath('outstanding_amount', 800);
    }

    // =========================================================
    // 🍳 (19/11/26) addon-only carve-out — ห้อง assign แล้วก่อนเช็คอิน
    //    (บิล paid/confirmed + BR confirmed ที่มี room_id — เดิมโดน guard 422 ทั้ง request)
    // =========================================================

    public function test_put_mode_2_addon_only_passes_guard_on_assigned_room_and_adds_surcharge(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $room = $this->createRoom($standard);
        GlobalRate::create([
            'rate_type' => 'addon',
            'code' => 'early_checkin',
            'name_en' => 'Early Check-in',
            'default_price' => 300,
            'is_active' => true,
        ]);

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $room, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // addon-only (early check-in 2 ชม. × 300 = 600) บนห้องที่มีเลขแล้ว → ผ่าน guard และคิด surcharge เฉพาะ addon
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => ['early_hours' => 2],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('total_amount', 2600)
            ->assertJsonPath('surcharge_amount', 600)
            ->assertJsonPath('outstanding_amount', 600);

        // เลขห้อง/tier/วันที่ ต้องไม่ถูกแตะ
        $this->assertSame($room->id, (string) $br->fresh()->room_id);
        $this->assertSame(2000, (int) $br->fresh()->room_amount);
        $this->assertSame(2600, (int) $br->fresh()->amount);

        // is_paid recompute — paid 2000 < ยอดใหม่ 2600 → false
        $this->assertFalse((bool) $booking->fresh()->is_paid);

        // audit note ติดป้าย addon-only (ไม่ใช่ change-tier)
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'booking_room',
            'entity_id' => $br->id,
            'note' => 'addon-only (surcharge) · price 2000 → 2000 บาท',
        ]);
    }

    public function test_put_mode_2_assigned_room_addon_with_shape_field_still_points_to_change_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $room = $this->createRoom($standard);

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $room, 'confirmed');

        // addons ปน shape field (room_type_id) → ยังโดน guard 422 ชี้ไป change-room เต็มรูปแบบ
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $deluxe->id,
                'addons' => ['early_hours' => 2],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('change-room', $response->json('message'));
    }

    public function test_put_mode_2_assigned_room_addon_only_param_room_id_still_rejected(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $otherRoom = $this->createRoom($standard);
        $room = $this->createRoom($standard);

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $room, 'confirmed');

        // แอบแถม room_id ใน request addon-only → ยังโดน 422 (จัดเลขห้องผ่าน assign/change-room เท่านั้น)
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => ['early_hours' => 2],
                'room_id' => $otherRoom->id,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('change-room', $response->json('message'));
    }

    public function test_put_mode_2_assigned_room_addon_only_owner_forbidden(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $room = $this->createRoom($standard);

        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $room, 'confirmed');

        // เจ้าของบิล (role user) ส่ง addon-only → 403 ตามโหมด 2 (admin/staff เท่านั้น)
        $response = $this->actingAs($booking->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => ['early_hours' => 2],
            ]);

        $response->assertStatus(403);
    }

    public function test_put_mode_2_assigned_room_addon_extra_beds_over_cap_rejected(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000, 1); // max extra beds = 1
        $room = $this->createRoom($standard);
        GlobalRate::create([
            'rate_type' => 'addon',
            'code' => 'extra_bed',
            'name_en' => 'Extra Bed',
            'default_price' => 500,
            'is_active' => true,
        ]);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 2000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 2000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $room, 'confirmed', 1, [
            'room_amount' => 2000,
            'amount' => 2000,
        ]);

        // extra_beds_by_night คืนเดียว 2 เตียง > max_extra_beds (1) → 422
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'extra_beds_by_night' => [$today->toDateString() => 2],
                ],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('เตียงเสริม', $response->json('message'));
    }
}
