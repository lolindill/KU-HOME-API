<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\GlobalRate;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\StatusChangeLog;
use App\Models\User;
use App\Support\BookingRule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🔀 FrontDeskSplitUpgradeTest — HTTP Feature test suite สำหรับ POST /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/split-upgrade
 *
 * อ้างอิง: wayfinder/mid-stay-split-upgrade/tickets/02-tests-split-upgrade.md
 * ครอบคลุม Auth/scope, Validation 422, Happy path (admin + staff), และ Money guard (มติ #10) + Float semantics (มติ #9)
 */
class FrontDeskSplitUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private static int $roomSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAddonRates();
    }

    protected function createStaff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    protected function createUserWithRole(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role]);
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

    private function createRoom(RoomType $roomType, string $status = 'available', array $overrides = []): Room
    {
        return Room::create(array_merge([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '40'.(++self::$roomSeq),
            'status' => $status,
            'builtin_extra_beds' => 2,
        ], $overrides));
    }

    private function createBooking(array $overrides = []): Booking
    {
        $user = $overrides['user'] ?? User::factory()->create();
        unset($overrides['user']);

        return Booking::create(array_merge([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'confirmed',
            'total_amount' => 4000,
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
        $checkOut = $overrides['check_out'] ?? $today->copy()->addDays(4)->toDateString();

        return BookingRoom::create(array_merge([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => $room?->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => $guests,
            'status' => $brStatus,
            'room_amount' => $overrides['room_amount'] ?? 4000,
            'discount_amount' => 0,
            'amount' => $overrides['amount'] ?? 4000,
        ], $overrides));
    }

    private function seedAddonRates(): void
    {
        GlobalRate::firstOrCreate(['code' => 'breakfast_100'], [
            'rate_type' => 'addon', 'room_type_id' => null,
            'name_en' => 'Breakfast Set 100', 'default_price' => 100, 'is_active' => true,
        ]);
        GlobalRate::firstOrCreate(['code' => 'breakfast_200'], [
            'rate_type' => 'addon', 'room_type_id' => null,
            'name_en' => 'Breakfast Set 200', 'default_price' => 200, 'is_active' => true,
        ]);
        GlobalRate::firstOrCreate(['code' => 'extra_bed'], [
            'rate_type' => 'addon', 'room_type_id' => null,
            'name_en' => 'Extra Bed', 'default_price' => 500, 'is_active' => true,
        ]);
        GlobalRate::firstOrCreate(['code' => 'early_checkin'], [
            'rate_type' => 'addon', 'room_type_id' => null,
            'name_en' => 'Early Check-in', 'default_price' => 100, 'is_active' => true,
        ]);
        GlobalRate::firstOrCreate(['code' => 'late_checkout'], [
            'rate_type' => 'addon', 'room_type_id' => null,
            'name_en' => 'Late Check-out', 'default_price' => 100, 'is_active' => true,
        ]);
    }

    private function endpoint(Booking $booking, BookingRoom $br): string
    {
        return "/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/split-upgrade";
    }

    // =========================================================
    // 🔒 1. Auth & Scope
    // =========================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);

        $response = $this->postJson($this->endpoint($booking, $br), [
            'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
            'room_type_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(401);
    }

    public function test_non_admin_non_staff_role_returns_403(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $owner = User::factory()->create(['role' => 'user']);
        $booking = $this->createBooking(['user' => $owner]);
        $br = $this->createBookingRoom($booking, $standard);

        $roles = ['guest', 'housekeeping', 'ku_member'];
        foreach ($roles as $role) {
            $user = $this->createUserWithRole($role);
            $response = $this->actingAs($user, 'sanctum')->postJson($this->endpoint($booking, $br), [
                'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
                'room_type_id' => $deluxe->id,
            ]);
            $response->assertStatus(403);
        }

        // เจ้าของบิล (owner) ที่ไม่ใช่ admin/staff ก็ต้องโดน 403
        $responseOwner = $this->actingAs($owner, 'sanctum')->postJson($this->endpoint($booking, $br), [
            'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
            'room_type_id' => $deluxe->id,
        ]);
        $responseOwner->assertStatus(403);
    }

    public function test_cross_booking_room_id_returns_404(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $bookingA = $this->createBooking();
        $bookingB = $this->createBooking();
        $brA = $this->createBookingRoom($bookingA, $standard);
        $brB = $this->createBookingRoom($bookingB, $standard);

        // ส่ง bookingA แต่ส่ง bookingRoomId ของ bookingB
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$bookingA->id}/rooms/{$brB->id}/split-upgrade", [
                'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(404);
    }

    // =========================================================
    // 🛡️ 2. Validation 422
    // =========================================================

    public function test_container_invalid_status_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $invalidStatuses = ['paid', 'pending', 'verify_error', 'complete'];
        foreach ($invalidStatuses as $status) {
            $booking = $this->createBooking(['status' => $status]);
            $br = $this->createBookingRoom($booking, $standard);

            $response = $this->actingAs($this->createAdmin(), 'sanctum')
                ->postJson($this->endpoint($booking, $br), [
                    'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
                    'room_type_id' => $deluxe->id,
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('status', 'error');
        }
    }

    public function test_split_date_in_past_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'check_in' => $today->copy()->subDays(5)->toDateString(),
            'check_out' => $today->copy()->addDays(5)->toDateString(),
        ]);

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $today->copy()->subDay()->toDateString(), // เมื่อวาน (อดีต)
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_split_date_out_of_bounds_or_equal_to_edges_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $checkIn = $today->copy()->addDays(2);
        $checkOut = $today->copy()->addDays(6);

        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
        ]);

        $invalidDates = [
            $checkIn->copy()->subDay()->toDateString(), // ก่อน check_in
            $checkIn->toDateString(),                    // เท่ากับ check_in เป๊ะ
            $checkOut->toDateString(),                   // เท่ากับ check_out เป๊ะ
            $checkOut->copy()->addDay()->toDateString(), // หลัง check_out
        ];

        foreach ($invalidDates as $invalidDate) {
            $response = $this->actingAs($this->createAdmin(), 'sanctum')
                ->postJson($this->endpoint($booking, $br), [
                    'split_date' => $invalidDate,
                    'room_type_id' => $deluxe->id,
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('status', 'error');
        }
    }

    public function test_invalid_room_type_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $suite = $this->createRoomTypeWithRate('Suite', 2, 800); // เรทต่ำกว่า
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);
        $splitDate = Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString();

        // 1. room_type_id เดิม
        $resSame = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $standard->id,
            ]);
        $resSame->assertStatus(422);

        // 2. tier ต่ำกว่า
        $resLower = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $suite->id,
            ]);
        $resLower->assertStatus(422);

        // 3. uuid ไม่มีจริง
        $resNotExist = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => (string) Str::uuid(),
            ]);
        $resNotExist->assertStatus(422);
    }

    public function test_price_per_night_validation_staff_and_admin(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $booking = $this->createBooking();
        // 4 คืน room_amount = 4000 -> ceil(4000/4) = 1000
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
        ]);
        $splitDate = Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString();

        // staff ใส่ price_per_night -> 422
        $resStaff = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
            ]);
        $resStaff->assertStatus(422);

        // admin ใส่ price_per_night ต่ำกว่า ceil(a0 / n0) -> ต่ำกว่า 1000 เช่น 900 -> 422
        $resAdminLow = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 900,
            ]);
        $resAdminLow->assertStatus(422);
    }

    public function test_pin_room_id_mismatched_room_type_returns_422_before_other_checks(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);
        $standardRoom = $this->createRoom($standard, 'available'); // ห้องประเภท standard

        // ส่ง room_type_id = deluxe แต่ pin room_id ของ standard
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
                'room_type_id' => $deluxe->id,
                'room_id' => $standardRoom->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_staff_computed_path_rate_lower_than_remaining_contract_returns_422_with_admin_hint(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        // tier ใหม่เรท 2000
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2000);

        $booking = $this->createBooking(['total_amount' => 12000]);
        // สัญญาเดิมสูงมาก เช่น 12,000 บาทสำหรับ 4 คืน (เฉลี่ย 3,000 บาท/คืน)
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 12000,
        ]);

        // split ที่วันที่ 1 (n1 = 1 คืน, n2 = 3 คืน)
        // a0 = 12000, a1 = intdiv(12000 * 1, 4) = 3000
        // minBr2Needed = 12000 - 3000 = 9000
        // แต่ tier ใหม่ computed_rate = 2000 -> 2000 * 3 = 6000 < 9000!
        $splitDate = Carbon::today(BookingRule::TIMEZONE)->addDay()->toDateString();

        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString('price_per_night', $response->json('message'));
    }

    public function test_booking_with_discount_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        // 1. discount_code บน booking
        $bookingWithCode = $this->createBooking(['discount_code' => 'DISC10']);
        $brA = $this->createBookingRoom($bookingWithCode, $standard);
        $splitDate = Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString();

        $resCode = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($bookingWithCode, $brA), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);
        $resCode->assertStatus(422);

        // 2. discount_redemptions บน booking_room
        $bookingWithRedemption = $this->createBooking();
        $brB = $this->createBookingRoom($bookingWithRedemption, $standard);
        $discount = Discount::create([
            'id' => Str::uuid(),
            'code' => 'TESTREDEMP',
            'type' => 'fixed',
            'value' => 200,
            'is_active' => true,
        ]);
        DiscountRedemption::create([
            'id' => Str::uuid(),
            'discount_id' => $discount->id,
            'booking_room_id' => $brB->id,
            'user_id' => $bookingWithRedemption->user_id,
            'status' => 'held',
        ]);

        $resRedemp = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($bookingWithRedemption, $brB), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);
        $resRedemp->assertStatus(422);
    }

    public function test_guest_count_exceeding_new_room_type_max_guests_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 4, 1000);
        // tier ใหม่รับได้แค่ 2 คน
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $booking = $this->createBooking();
        // มีผู้เข้าพัก 3 คน
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 3);
        $splitDate = Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString();

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString('เกินจำนวนสูงสุดที่รองรับได้', $response->json('message'));
    }

    public function test_extra_beds_by_night_in_second_half_exceeding_max_extra_beds_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000, 3);
        // tier ใหม่ max_extra_beds = 1
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500, 1);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();
        // คืน split_date (+2) มี extra bed = 2 เตียง (เกิน 1 ของ tier ใหม่)
        Addon::create([
            'booking_room_id' => $br->id,
            'extra_beds_by_night' => [
                $today->toDateString() => 1,
                $splitDate => 2,
            ],
        ]);

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_allocation_failure_or_maintenance_returns_422_and_rolls_back(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000]);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);
        $addon = Addon::create([
            'booking_room_id' => $br->id,
            'breakfast_set_100' => 1,
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // 1. กรณีไม่มีห้อง deluxe ใน pool เลย (allocate fail)
        $responseFail = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);
        $responseFail->assertStatus(422);

        // ตรวจสอบ Rollback สมบูรณ์
        $this->assertSame($today->copy()->addDays(4)->toDateString(), $br->fresh()->check_out->toDateString());
        $this->assertSame(4000, (int) $br->fresh()->room_amount);
        $this->assertSame(1, BookingRoom::where('booking_id', $booking->id)->count());
        $this->assertSame(1, Addon::where('booking_room_id', $br->id)->count());

        // 2. กรณี pin room_id ที่ติด maintenance period
        $deluxeRoom = $this->createRoom($deluxe, 'available');
        RoomStatePeriod::create([
            'room_id' => $deluxeRoom->id,
            'kind' => RoomStatePeriod::KIND_MAINTENANCE,
            'start_date' => $splitDate,
            'end_date' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $responseMaint = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'room_id' => $deluxeRoom->id,
            ]);
        $responseMaint->assertStatus(422);

        // ตรวจสอบ Rollback อีกครั้ง
        $this->assertSame($today->copy()->addDays(4)->toDateString(), $br->fresh()->check_out->toDateString());
        $this->assertSame(1, BookingRoom::where('booking_id', $booking->id)->count());
    }

    // =========================================================
    // ✨ 3. Happy Path
    // =========================================================

    public function test_happy_path_checked_in_today_split_by_admin(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $newRoom = $this->createRoom($deluxe, 'available');

        $booking = $this->createBooking(['total_amount' => 4000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 4000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $oldRoom, 'checked_in', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
                'room_id' => $newRoom->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // BR-1: check_out trimmed ถึง split_date และยังคง status 'checked_in'
        $brFresh = $br->fresh();
        $this->assertSame($splitDate, $brFresh->check_out->toDateString());
        $this->assertSame('checked_in', $brFresh->status);
        $this->assertSame(2000, (int) $brFresh->room_amount); // intdiv(4000 * 2, 4)

        // BR-2: สร้างขึ้นใหม่ status 'confirmed', room_type deluxe, room_id ชี้ newRoom
        $br2 = BookingRoom::where('booking_id', $booking->id)
            ->where('id', '!=', $br->id)
            ->firstOrFail();

        $this->assertSame('confirmed', $br2->status);
        $this->assertSame((string) $deluxe->id, (string) $br2->room_type_id);
        $this->assertSame((string) $newRoom->id, (string) $br2->room_id);
        $this->assertSame($splitDate, $br2->check_in->toDateString());
        $this->assertSame($today->copy()->addDays(4)->toDateString(), $br2->check_out->toDateString());
        $this->assertSame(3000, (int) $br2->room_amount); // 1500 * 2

        // สถานะห้องกายภาพทั้งสองห้องต้องคงเดิม (ไม่มี auto-checkout/checkin ตามมติ #3)
        $this->assertSame('occupied', $oldRoom->fresh()->status);
        $this->assertSame('available', $newRoom->fresh()->status);
    }

    public function test_happy_path_draft_container_split_and_discount_block(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['status' => 'draft', 'total_amount' => 4000]);
        $br = $this->createBookingRoom($booking, $standard, null, 'draft', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(200);

        // BR-2 ภายใต้บิล draft ต้องมี status 'draft'
        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();
        $this->assertSame('draft', $br2->status);

        // BR-1 ยังสามารถแก้ไขผ่าน updateRoom โหมด 1 ได้
        $updateRes = $this->actingAs($this->createAdmin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'guests' => [
                    ['title' => 'mr', 'firstName' => 'Updated', 'lastName' => 'Name', 'nationality' => 'TH'],
                ],
            ]);
        $updateRes->assertStatus(200);
    }

    public function test_future_split_holding_slot_overlap_guard(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $futureIn = $today->copy()->addDays(10);
        $futureOut = $today->copy()->addDays(14);
        $splitDate = $today->copy()->addDays(12)->toDateString();

        $deluxeRoom = $this->createRoom($deluxe, 'available');

        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'check_in' => $futureIn->toDateString(),
            'check_out' => $futureOut->toDateString(),
        ]);

        // split ในอนาคต ให้ BR-2 ได้ deluxeRoom
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'room_id' => $deluxeRoom->id,
            ]);
        $response->assertStatus(200);

        // บิลมือสามพยายาม check-in ห้อง deluxeRoom ในช่วงเวลาทับซ้อนกับ BR-2
        $bookingThird = $this->createBooking(['status' => 'confirmed']);
        $brThird = $this->createBookingRoom($bookingThird, $deluxe, null, 'confirmed', 1, [
            'check_in' => $splitDate,
            'check_out' => $futureOut->toDateString(),
        ]);

        $checkInRes = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$bookingThird->id}/rooms/{$brThird->id}/check-in", [
                'room_id' => $deluxeRoom->id,
            ]);

        // ต้องโดน overlap guard ปฏิเสธ 422
        $checkInRes->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_admin_manual_price_surcharge_envelope_and_outstanding(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 4000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // admin manual price = 1800 บาท/คืน
        // a0 = 4000 (4 คืน) -> a1 = intdiv(4000 * 2, 4) = 2000
        // a2 = 1800 * 2 = 3600
        // newTotal = 2000 + 3600 = 5600
        // previousTotal = 4000 -> surcharge = 1600
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1800,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('previous_total_amount', 4000)
            ->assertJsonPath('total_amount', 5600)
            ->assertJsonPath('surcharge_amount', 1600)
            ->assertJsonPath('paid_amount', 4000)
            ->assertJsonPath('outstanding_amount', 1600)
            ->assertJsonPath('split_summary.br1_new.room_amount', 2000)
            ->assertJsonPath('split_summary.br2.price_per_night', 1800)
            ->assertJsonPath('split_summary.br2.nights', 2);
    }

    public function test_staff_computed_path_uses_effective_daily_rate(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000]);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // staff ยิงไม่ใส่ price_per_night
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('split_summary.br2.price_per_night', 1500);

        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();
        $this->assertSame(3000, (int) $br2->room_amount); // 1500 * 2
    }

    public function test_addon_reslice_extra_beds_breakfast_early_late(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $d0 = $today->toDateString();
        $d1 = $today->copy()->addDay()->toDateString();
        $d2 = $today->copy()->addDays(2)->toDateString();
        $d3 = $today->copy()->addDays(3)->toDateString();
        $d4 = $today->copy()->addDays(4)->toDateString();

        $booking = $this->createBooking(['total_amount' => 4000]);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $d0,
            'check_out' => $d4,
        ]);

        Addon::create([
            'booking_room_id' => $br->id,
            'breakfast_set_100' => 1,
            'breakfast_set_200' => 2,
            'extra_beds_by_night' => [
                $d0 => 1,
                $d1 => 1,
                $d2 => 2,
                $d3 => 2,
            ],
            'early_hours' => 2,
            'late_hours' => 3,
        ]);

        // split ที่ d2
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $d2,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
            ]);

        $response->assertStatus(200);

        $br1Addon = Addon::where('booking_room_id', $br->id)->firstOrFail();
        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();
        $br2Addon = Addon::where('booking_room_id', $br2->id)->firstOrFail();

        // 1. breakfast sets คัดลอกไปทั้งคู่
        $this->assertSame(1, $br1Addon->breakfast_set_100);
        $this->assertSame(2, $br1Addon->breakfast_set_200);
        $this->assertSame(1, $br2Addon->breakfast_set_100);
        $this->assertSame(2, $br2Addon->breakfast_set_200);

        // 2. extra beds re-sliced: < d2 อยู่ BR-1, >= d2 อยู่ BR-2
        $this->assertEquals([$d0 => 1, $d1 => 1], $br1Addon->extra_beds_by_night);
        $this->assertEquals([$d2 => 2, $d3 => 2], $br2Addon->extra_beds_by_night);

        // 3. early คง BR-1, late ย้ายไป BR-2
        $this->assertSame(2, $br1Addon->early_hours);
        $this->assertSame(0, $br1Addon->late_hours);
        $this->assertSame(0, $br2Addon->early_hours);
        $this->assertSame(3, $br2Addon->late_hours);
    }

    public function test_audit_status_change_logs_created(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $admin = $this->createAdmin();
        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000]);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
                'notes' => 'Special VIP split',
            ]);

        $response->assertStatus(200);

        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();

        // ภายใต้บิล confirmed:
        // 1. Machine row: draft -> confirmed ของ BR-2
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'booking_room',
            'entity_id' => $br2->id,
            'from_status' => 'draft',
            'to_status' => 'confirmed',
            'role' => 'admin',
            'causer_id' => $admin->id,
        ]);

        // 2. Note row ของ BR-1 (from=to=confirmed)
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'booking_room',
            'entity_id' => $br->id,
            'from_status' => 'confirmed',
            'to_status' => 'confirmed',
            'role' => 'admin',
            'causer_id' => $admin->id,
        ]);

        // 3. Note row ของ BR-2 (from=to=confirmed พร้อมแนบ notes)
        $br2NoteLog = StatusChangeLog::where('entity_id', $br2->id)
            ->whereNotNull('note')
            ->firstOrFail();

        $this->assertStringContainsString('split-upgrade: spawned', $br2NoteLog->note);
        $this->assertStringContainsString('Special VIP split', $br2NoteLog->note);
    }

    public function test_invariant_sum_amount_equals_total_amount_and_booking_type_mirror(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000]);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
            ]);

        $bookingFresh = $booking->fresh();
        $sumAmount = BookingRoom::where('booking_id', $booking->id)->sum('amount');
        $this->assertSame((int) $bookingFresh->total_amount, (int) $sumAmount);

        // stay_type และ nights ของแต่ละห้องคำนวณถูกต้อง
        $br1Fresh = $br->fresh();
        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();
        $this->assertSame(2, $br1Fresh->nights);
        $this->assertSame(2, $br2->nights);
        $this->assertSame('daily', $br1Fresh->stay_type);
        $this->assertSame('daily', $br2->stay_type);
    }

    public function test_multi_room_booking_other_rooms_untouched_and_availability(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 8000]);
        $roomOther = $this->createRoom($standard, 'occupied');

        $br1 = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);
        $brOther = $this->createBookingRoom($booking, $standard, $roomOther, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br1), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
            ]);
        $response->assertStatus(200);

        // BR อื่นไม่ถูกแตะ room_id
        $this->assertSame($roomOther->id, $brOther->fresh()->room_id);
    }

    public function test_repeat_split_on_spawned_br2_succeeds(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $suite = $this->createRoomTypeWithRate('Suite', 2, 2500);

        $this->createRoom($deluxe, 'available');
        $this->createRoom($suite, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 6000]);
        $br1 = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 6000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(6)->toDateString(),
        ]);

        // Split ครั้งแรกที่วันที่ 2 (standard -> deluxe)
        $splitDate1 = $today->copy()->addDays(2)->toDateString();
        $res1 = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br1), [
                'split_date' => $splitDate1,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
            ]);
        $res1->assertStatus(200);

        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br1->id)->firstOrFail();
        $br2->transitionStatus('checked_in', 'admin');

        // Split ครั้งที่ 2 บน BR-2 ที่ checked_in แล้ว (deluxe -> suite) ที่วันที่ 4
        $splitDate2 = $today->copy()->addDays(4)->toDateString();
        $res2 = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br2), [
                'split_date' => $splitDate2,
                'room_type_id' => $suite->id,
                'price_per_night' => 2500,
            ]);
        $res2->assertStatus(200);

        $this->assertSame(3, BookingRoom::where('booking_id', $booking->id)->count());
    }

    public function test_deferred_payment_split_succeeds(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        // บิล deferred ยังไม่จ่ายเงิน (paid_amount = 0)
        $booking = $this->createBooking([
            'payment_type' => 'deferred',
            'is_paid' => false,
            'total_amount' => 4000,
        ]);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
            ]);

        $response->assertStatus(200);
        // ยอด outstanding เพิ่มตาม total ใหม่ (5000)
        $this->assertSame(5000, (int) $booking->fresh()->outstanding_amount);
    }

    // =========================================================
    // 💰 4. Money Guard & Float Semantics
    // =========================================================

    public function test_pin_freeze_invariant_crossing_group_threshold(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        // เซ็ต group rate min_5_rooms ให้ถูกกว่า daily (เช่น 800 บาท)
        GlobalRate::create([
            'rate_type' => 'group',
            'room_type_id' => $standard->id,
            'code' => 'min_5_rooms',
            'name_en' => 'Standard Group 5',
            'default_price' => 800,
            'is_active' => true,
        ]);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 16000]);

        // สร้าง 4 ห้องเดิม (ยังไม่ถึงเกณฑ์ 5 ห้อง -> คิดเรท 1000 * 4 คืน = 4000 ต่อห้อง)
        $brs = [];
        for ($i = 0; $i < 4; $i++) {
            $brs[] = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
                'room_amount' => 4000,
                'amount' => 4000,
                'check_in' => $today->toDateString(),
                'check_out' => $today->copy()->addDays(4)->toDateString(),
            ]);
        }

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // เมื่อ split ห้องที่ 0 บิลจะมีทั้งหมด 5 ห้อง (ข้ามเกณฑ์ 5 ห้อง!)
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $brs[0]), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
            ]);

        $response->assertStatus(200);

        // มติ #10: ห้องอื่นๆ (br1, br2, br3) ต้องคง room_amount เดิมทุกตัว (4000 บาท) ไม่หลุดไปใช้ group rate 800
        for ($i = 1; $i < 4; $i++) {
            $this->assertSame(4000, (int) $brs[$i]->fresh()->room_amount);
        }
    }

    public function test_pair_guard_failure_rolls_back(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 8000]);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 8000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        // พยายามยิง staff computed ที่เรทไม่พอ
        $splitDate = $today->copy()->addDays(2)->toDateString();
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);

        $response->assertStatus(422);

        // ตรวจสอบ rollback
        $this->assertSame(8000, (int) $br->fresh()->room_amount);
        $this->assertSame(1, BookingRoom::where('booking_id', $booking->id)->count());
    }

    public function test_ledger_guard_and_is_paid_reset(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        // บิล paid ครบแล้ว (is_paid = true, paid = 4000)
        $booking = $this->createBooking(['total_amount' => 4000, 'is_paid' => true]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 4000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // split เพิ่มยอดเป็น 5000 (surcharge = 1000)
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
            ]);

        $response->assertStatus(200);

        // is_paid ต้องถูก reset เป็น false ทันทีเพราะมียอดค้างชำระ
        $bookingFresh = $booking->fresh();
        $this->assertFalse((bool) $bookingFresh->is_paid);
        $this->assertSame(1000, (int) $bookingFresh->outstanding_amount);
    }

    public function test_overpay_bill_passes_without_422_and_clamps_outstanding_zero(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        // บิล overpay: จ่ายมา 10,000 แต่ยอด total แค่ 4000
        $booking = $this->createBooking(['total_amount' => 4000, 'is_paid' => true]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 10000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // newTotal = 5000 (< paid 10,000) -> ledger guard ต้องไม่ false-fire!
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 1500,
            ]);

        $response->assertStatus(200);
        $this->assertSame(0, (int) $booking->fresh()->outstanding_amount);
    }

    public function test_float_semantics_reprice_after_split_recomputes_from_current_rates(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 4000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // 1. Split สำเร็จ (manual price 2000 บาท/คืน)
        // a1 = 2000, a2 = 2000 * 2 = 4000 -> total = 6000
        $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
                'price_per_night' => 2000,
            ])
            ->assertStatus(200);

        $br1Fresh = $br->fresh();
        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();
        $this->assertSame(2000, (int) $br1Fresh->room_amount);
        $this->assertSame(4000, (int) $br2->room_amount);

        // 2. เปลี่ยน daily rate ของ Standard และ Deluxe ใน GlobalRate
        GlobalRate::where('room_type_id', $standard->id)->where('rate_type', 'daily')->update(['default_price' => 1200]);
        GlobalRate::where('room_type_id', $deluxe->id)->where('rate_type', 'daily')->update(['default_price' => 1800]);

        // 3. เรียก updateRoom โหมด 3 (เพิ่ม addon breakfast) บน BR-1
        $updateRes = $this->actingAs($this->createAdmin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'breakfast_sets' => ['set_100' => 1],
                ],
            ]);

        $updateRes->assertStatus(200);

        // มติ #9 Float semantics: reprice ครั้งถัดไปจะคิดค่าห้องใหม่จาก global_rates ในวันนี้
        // BR-1: 1200 * 2 = 2400 (เดิม 2000)
        // BR-2: 1800 * 2 = 3600 (เดิม 4000 จาก manual price)
        $this->assertSame(2400, (int) $br->fresh()->room_amount);
        $this->assertSame(3600, (int) $br2->fresh()->room_amount);
    }
}
