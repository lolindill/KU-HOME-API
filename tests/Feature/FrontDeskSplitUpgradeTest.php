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
use App\Services\Discount\DiscountService;
use App\Support\BookingRule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🔀 FrontDeskSplitUpgradeTest — HTTP Feature test suite สำหรับ POST /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/split-upgrade
 *
 * อ้างอิง: tier-endpoints-v2 มติ v2 ข้อ 5 (pure same-tier split บน route เดิม)
 * ครอบคลุม Auth/scope, Validation 422, Pure same-tier split (admin + staff), และ Total amount invariant (surcharge = 0)
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

    protected function createAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
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
        ]);

        $response->assertStatus(401);
    }

    public function test_non_admin_non_staff_role_returns_403(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);

        $roles = ['user', 'guest', 'ku_member', 'housekeeping', 'system'];
        foreach ($roles as $role) {
            $user = $this->createUserWithRole($role);
            $response = $this->actingAs($user, 'sanctum')
                ->postJson($this->endpoint($booking, $br), [
                    'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
                ]);

            $response->assertStatus(403);
        }
    }

    public function test_cross_booking_room_id_returns_404(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking1 = $this->createBooking();
        $booking2 = $this->createBooking();
        $br2 = $this->createBookingRoom($booking2, $standard);

        // ยิงหา booking1 แต่ส่ง br2 ที่อยู่ใต้ booking2 -> 404
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking1->id}/rooms/{$br2->id}/split-upgrade", [
                'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
            ]);

        $response->assertStatus(404);
    }

    // =========================================================
    // 🛡️ 2. Validation & Edge Guards
    // =========================================================

    public function test_container_invalid_status_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);

        // บิลสถานะที่ไม่ใช่ confirmed/draft เช่น pending, paid, cancelled, completed
        $invalidStatuses = ['pending', 'cancelled', 'completed'];
        foreach ($invalidStatuses as $status) {
            $booking = $this->createBooking(['status' => $status]);
            $br = $this->createBookingRoom($booking, $standard);

            $response = $this->actingAs($this->createAdmin(), 'sanctum')
                ->postJson($this->endpoint($booking, $br), [
                    'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('status', 'error');
        }
    }

    public function test_split_date_in_past_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $today = Carbon::today(BookingRule::TIMEZONE);

        // จองย้อนหลัง 5 วัน ถึง อนาคตอีก 2 วัน
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'checked_in', 1, [
            'check_in' => $today->copy()->subDays(5)->toDateString(),
            'check_out' => $today->copy()->addDays(2)->toDateString(),
        ]);

        // split_date ย้อนหลัง (เมื่อวาน)
        $pastDate = $today->copy()->subDay()->toDateString();

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $pastDate,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString('อดีต', $response->json('message'));
    }

    public function test_split_date_out_of_bounds_or_equal_to_edges_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $today = Carbon::today(BookingRule::TIMEZONE);
        $checkIn = $today->copy()->addDays(2)->toDateString();
        $checkOut = $today->copy()->addDays(6)->toDateString();

        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);

        $invalidDates = [
            $checkIn,                             // เท่ากับ check_in พอดี
            $checkOut,                            // เท่ากับ check_out พอดี
            $today->copy()->addDay()->toDateString(), // ก่อน check_in
            $today->copy()->addDays(7)->toDateString(), // หลัง check_out
        ];

        foreach ($invalidDates as $invalidDate) {
            $response = $this->actingAs($this->createAdmin(), 'sanctum')
                ->postJson($this->endpoint($booking, $br), [
                    'split_date' => $invalidDate,
                ]);

            $response->assertStatus(422)
                ->assertJsonPath('status', 'error');
        }
    }

    public function test_sending_room_type_id_or_price_per_night_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);
        $splitDate = Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString();

        // 1. ส่ง room_type_id -> 422 ชี้ PUT โหมด 2 / change-room
        $resType = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_type_id' => $deluxe->id,
            ]);
        $resType->assertStatus(422);
        $this->assertStringContainsString('same-tier', $resType->json('message'));

        // 2. ส่ง price_per_night -> 422 ชี้ PUT โหมด 2 / change-room
        $resPrice = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'price_per_night' => 1200,
            ]);
        $resPrice->assertStatus(422);
        $this->assertStringContainsString('same-tier', $resPrice->json('message'));
    }

    public function test_pin_room_id_mismatched_room_type_returns_422_before_other_checks(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 1500);

        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);
        $deluxeRoom = $this->createRoom($deluxe, 'available'); // ห้องประเภท deluxe ต่างจาก standard

        // pin room_id ของ deluxe แต่ BR-1 เป็น standard -> 422 ผิดประเภทห้อง
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
                'room_id' => $deluxeRoom->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString('ไม่ตรงกับประเภทห้องเดิม', $response->json('message'));
    }

    public function test_pin_same_room_as_br1_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $room = $this->createRoom($standard, 'occupied');

        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, $room, 'checked_in');

        // pin room_id ซ้ำกับห้องของ BR-1 -> 422
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => Carbon::today(BookingRule::TIMEZONE)->addDays(2)->toDateString(),
                'room_id' => $room->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString('ห้องเดิม', $response->json('message'));
    }

    public function test_booking_with_discount_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $today = Carbon::today(BookingRule::TIMEZONE);

        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'discount_amount' => 500,
        ]);

        $discount = Discount::create([
            'id' => Str::uuid(),
            'code' => 'TEST500',
            'type' => 'fixed',
            'value' => 500,
            'is_active' => true,
        ]);

        DiscountRedemption::create([
            'id' => Str::uuid(),
            'discount_id' => $discount->id,
            'booking_id' => $booking->id,
            'booking_room_id' => $br->id,
            'user_id' => $booking->user_id,
            'status' => 'held',
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        $resRedemp = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
            ]);
        $resRedemp->assertStatus(422);
    }

    public function test_auto_allocate_excludes_br1_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);

        $room1 = $this->createRoom($standard, 'occupied');
        $room2 = $this->createRoom($standard, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000]);
        $br = $this->createBookingRoom($booking, $standard, $room1, 'checked_in', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // ไม่ pin room_id -> ระบบต้อง auto-allocate ให้ BR-2 แต่ต้องไม่เลือก room1
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
            ]);

        $response->assertStatus(200);

        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();
        $this->assertSame((string) $room2->id, (string) $br2->room_id);
        $this->assertNotSame((string) $room1->id, (string) $br2->room_id);
    }

    public function test_br1_without_room_spawns_br2_without_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000]);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // BR-1 ไม่มีห้อง ไม่ pin -> BR-2 ต้องได้ room_id = null
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
            ]);

        $response->assertStatus(200);

        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();
        $this->assertNull($br2->room_id);
    }

    public function test_extra_beds_by_night_in_second_half_exceeding_max_extra_beds_returns_422(): void
    {
        // standard max_extra_beds = 1
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000, 1);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();
        // คืน split_date (+2) มี extra bed = 2 เตียง (เกิน 1 ของ standard)
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
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_allocation_failure_or_maintenance_returns_422_and_rolls_back(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);

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

        // 1. กรณีไม่มีห้อง standard อื่นใน pool เลย (auto-allocate fail)
        $responseFail = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
            ]);
        $responseFail->assertStatus(422);

        // ตรวจสอบ Rollback สมบูรณ์
        $this->assertSame($today->copy()->addDays(4)->toDateString(), $br->fresh()->check_out->toDateString());
        $this->assertSame(4000, (int) $br->fresh()->room_amount);
        $this->assertSame(1, BookingRoom::where('booking_id', $booking->id)->count());
        $this->assertSame(1, Addon::where('booking_room_id', $br->id)->count());

        // 2. กรณี pin room_id ที่ติด maintenance period
        $standardRoom2 = $this->createRoom($standard, 'available');
        RoomStatePeriod::create([
            'room_id' => $standardRoom2->id,
            'kind' => RoomStatePeriod::KIND_MAINTENANCE,
            'start_date' => $splitDate,
            'end_date' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $responseMaint = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_id' => $standardRoom2->id,
            ]);
        $responseMaint->assertStatus(422);

        // ตรวจสอบ Rollback อีกครั้ง
        $this->assertSame($today->copy()->addDays(4)->toDateString(), $br->fresh()->check_out->toDateString());
        $this->assertSame(1, BookingRoom::where('booking_id', $booking->id)->count());
    }

    // =========================================================
    // ✨ 3. Happy Path (Pure same-tier)
    // =========================================================

    public function test_happy_path_checked_in_today_split_by_admin(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);

        $today = Carbon::today(BookingRule::TIMEZONE);
        $oldRoom = $this->createRoom($standard, 'occupied');
        $newRoom = $this->createRoom($standard, 'available');

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
                'room_id' => $newRoom->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('total_amount', 4000)
            ->assertJsonPath('surcharge_amount', 0);

        // BR-1: check_out trimmed ถึง split_date และยังคง status 'checked_in'
        $brFresh = $br->fresh();
        $this->assertSame($splitDate, $brFresh->check_out->toDateString());
        $this->assertSame('checked_in', $brFresh->status);
        $this->assertSame(2000, (int) $brFresh->room_amount); // intdiv(4000 * 2, 4)

        // BR-2: สร้างขึ้นใหม่ status 'confirmed', room_type standard เดียวกัน, room_id ชี้ newRoom
        $br2 = BookingRoom::where('booking_id', $booking->id)
            ->where('id', '!=', $br->id)
            ->firstOrFail();

        $this->assertSame('confirmed', $br2->status);
        $this->assertSame((string) $standard->id, (string) $br2->room_type_id);
        $this->assertSame((string) $newRoom->id, (string) $br2->room_id);
        $this->assertSame($splitDate, $br2->check_in->toDateString());
        $this->assertSame($today->copy()->addDays(4)->toDateString(), $br2->check_out->toDateString());
        $this->assertSame(2000, (int) $br2->room_amount); // 4000 - 2000

        // สถานะห้องกายภาพทั้งสองห้องต้องคงเดิม (ไม่มี auto-checkout/checkin ตามมติ #3)
        $this->assertSame('occupied', $oldRoom->fresh()->status);
        $this->assertSame('available', $newRoom->fresh()->status);
    }

    public function test_happy_path_draft_container_split_and_discount_block(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

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

        $today = Carbon::today(BookingRule::TIMEZONE);
        $futureIn = $today->copy()->addDays(10);
        $futureOut = $today->copy()->addDays(14);
        $splitDate = $today->copy()->addDays(12)->toDateString();

        $standardRoom = $this->createRoom($standard, 'available');

        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'check_in' => $futureIn->toDateString(),
            'check_out' => $futureOut->toDateString(),
        ]);

        // split ในอนาคต ให้ BR-2 ได้ standardRoom
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_id' => $standardRoom->id,
            ]);
        $response->assertStatus(200);

        // บิลมือสามพยายาม check-in ห้อง standardRoom ในช่วงเวลาทับซ้อนกับ BR-2
        $bookingThird = $this->createBooking(['status' => 'confirmed']);
        $brThird = $this->createBookingRoom($bookingThird, $standard, null, 'confirmed', 1, [
            'check_in' => $splitDate,
            'check_out' => $futureOut->toDateString(),
        ]);

        $checkInRes = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$bookingThird->id}/rooms/{$brThird->id}/check-in", [
                'room_id' => $standardRoom->id,
            ]);

        // ต้องโดน overlap guard ปฏิเสธ 422
        $checkInRes->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_same_tier_split_preserves_total_amount_invariant_and_zero_surcharge(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        // จำลองยอด 5000 บาท 5 คืน (คืนละ 1000 บาท)
        $booking = $this->createBooking(['total_amount' => 5000]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 5000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 5000,
            'amount' => 5000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(5)->toDateString(),
        ]);

        // split ที่ 2 คืน (n1 = 2, n2 = 3, n0 = 5)
        // a1 = intdiv(5000 * 2, 5) = 2000
        // a2 = 5000 - 2000 = 3000
        // รวมกันได้ 5000 พอดีเป๊ะ ไม่เกิดเศษเพนนี/ปัดสิบ
        $splitDate = $today->copy()->addDays(2)->toDateString();

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('previous_total_amount', 5000)
            ->assertJsonPath('total_amount', 5000)
            ->assertJsonPath('surcharge_amount', 0)
            ->assertJsonPath('outstanding_amount', 0)
            ->assertJsonPath('split_summary.br1_new.room_amount', 2000);

        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();
        $this->assertSame(3000, (int) $br2->room_amount);
        $this->assertSame(5000, (int) $booking->fresh()->total_amount);
    }

    public function test_staff_can_split_pure_same_tier(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000]);
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // staff ยิง split สำเร็จ
        $response = $this->actingAs($this->createStaff(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br->id)->firstOrFail();
        $this->assertSame(2000, (int) $br2->room_amount);
    }

    public function test_addon_reslice_extra_beds_breakfast_early_late(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

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

        // ซิงก์ total_amount ใน booking กับ addon ก่อนเริ่ม split
        app(DiscountService::class)->reprice($booking);
        $booking->refresh();

        // split ที่ d2
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $d2,
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
        $this->createRoom($standard, 'available');

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

        $this->assertStringContainsString('split: spawned', $br2NoteLog->note);
        $this->assertStringContainsString('Special VIP split', $br2NoteLog->note);
    }

    public function test_invariant_sum_amount_equals_total_amount_and_booking_type_mirror(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

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
        $this->createRoom($standard, 'available');

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
            ]);
        $response->assertStatus(200);

        // BR อื่นไม่ถูกแตะ room_id
        $this->assertSame($roomOther->id, $brOther->fresh()->room_id);
    }

    public function test_repeat_split_on_spawned_br2_succeeds(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);

        $this->createRoom($standard, 'available');
        $this->createRoom($standard, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 6000]);
        $br1 = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'room_amount' => 6000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(6)->toDateString(),
        ]);

        // Split ครั้งแรกที่วันที่ 2
        $splitDate1 = $today->copy()->addDays(2)->toDateString();
        $res1 = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br1), [
                'split_date' => $splitDate1,
            ]);
        $res1->assertStatus(200);

        $br2 = BookingRoom::where('booking_id', $booking->id)->where('id', '!=', $br1->id)->firstOrFail();
        $br2->transitionStatus('checked_in', 'admin');

        // Split ครั้งที่ 2 บน BR-2 ที่ checked_in แล้ว ที่วันที่ 4
        $splitDate2 = $today->copy()->addDays(4)->toDateString();
        $res2 = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br2), [
                'split_date' => $splitDate2,
            ]);
        $res2->assertStatus(200);

        $this->assertSame(3, BookingRoom::where('booking_id', $booking->id)->count());
        $this->assertSame(6000, (int) $booking->fresh()->total_amount);
    }

    public function test_deferred_payment_split_succeeds(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

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
            ]);

        $response->assertStatus(200);
        // total คงเดิม 4000 และ outstanding คงเดิม 4000
        $this->assertSame(4000, (int) $booking->fresh()->total_amount);
        $this->assertSame(4000, (int) $booking->fresh()->outstanding_amount);
    }

    // =========================================================
    // 💰 4. Invariant & Composition
    // =========================================================

    public function test_pin_freeze_invariant_crossing_group_threshold(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

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
            ]);

        $response->assertStatus(200);

        // ห้องอื่นๆ (br1, br2, br3) ต้องคง room_amount เดิมทุกตัว (4000 บาท) ไม่หลุดไปใช้ group rate 800
        for ($i = 1; $i < 4; $i++) {
            $this->assertSame(4000, (int) $brs[$i]->fresh()->room_amount);
        }
        $this->assertSame(16000, (int) $booking->fresh()->total_amount);
    }

    public function test_pair_guard_failure_rolls_back(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $room = $this->createRoom($standard, 'occupied');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 8000]);
        $br = $this->createBookingRoom($booking, $standard, $room, 'checked_in', 1, [
            'room_amount' => 8000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        // ส่ง room_id ซ้ำห้องเดิมของ BR-1 ทำให้ error 422
        $splitDate = $today->copy()->addDays(2)->toDateString();
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_id' => $room->id,
            ]);

        $response->assertStatus(422);

        // ตรวจสอบ rollback
        $this->assertSame(8000, (int) $br->fresh()->room_amount);
        $this->assertSame(1, BookingRoom::where('booking_id', $booking->id)->count());
    }

    public function test_split_keeps_is_paid_true_and_zero_surcharge(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

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

        // pure same-tier split surcharge = 0 -> is_paid ต้องคง true ไม่ reset
        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
            ]);

        $response->assertStatus(200);

        $bookingFresh = $booking->fresh();
        $this->assertTrue((bool) $bookingFresh->is_paid);
        $this->assertSame(0, (int) $bookingFresh->outstanding_amount);
        $this->assertSame(4000, (int) $bookingFresh->total_amount);
    }

    public function test_overpay_bill_passes_without_422_and_clamps_outstanding_zero(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $this->createRoom($standard, 'available');

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

        $response = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
            ]);

        $response->assertStatus(200);
        $this->assertSame(0, (int) $booking->fresh()->outstanding_amount);
        $this->assertSame(4000, (int) $booking->fresh()->total_amount);
    }

    public function test_composition_split_then_change_room_on_br1(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 2, 2000);

        $roomStandard1 = $this->createRoom($standard, 'occupied');
        $roomStandard2 = $this->createRoom($standard, 'available');
        $roomDeluxe = $this->createRoom($deluxe, 'available');

        $today = Carbon::today(BookingRule::TIMEZONE);
        $booking = $this->createBooking(['total_amount' => 4000, 'is_paid' => true]);
        Payment::create(['booking_id' => $booking->id, 'amount' => 4000, 'status' => 'completed']);
        $br = $this->createBookingRoom($booking, $standard, $roomStandard1, 'checked_in', 1, [
            'room_amount' => 4000,
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDays(4)->toDateString(),
        ]);

        $splitDate = $today->copy()->addDays(2)->toDateString();

        // 1. Split pure same-tier -> BR-1 (2 คืน 2000) + BR-2 (2 คืน 2000)
        $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson($this->endpoint($booking, $br), [
                'split_date' => $splitDate,
                'room_id' => $roomStandard2->id,
            ])
            ->assertStatus(200);

        $this->assertSame(2000, (int) $br->fresh()->room_amount);

        // 2. Composition: นำ BR-1 ที่ checked_in อยู่ ไปเปลี่ยนเป็น Deluxe ผ่าน change-room
        $changeRes = $this->actingAs($this->createAdmin(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/change-room", [
                'room_id' => $roomDeluxe->id,
            ]);

        $changeRes->assertStatus(200);
        $this->assertTrue($changeRes->json('room_type_changed'));
        // BR-1 2 คืน × 2000 = 4000, BR-2 2 คืน × 1000 = 2000 -> total 6000
        $this->assertSame(6000, (int) $booking->fresh()->total_amount);
        $this->assertSame(2000, (int) $booking->fresh()->outstanding_amount);
    }
}
