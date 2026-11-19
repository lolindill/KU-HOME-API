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
use App\Models\StatusChangeLog;
use App\Models\User;
use App\Support\BookingRule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 📅 RoomDatesChangeTest — HTTP Feature test suite สำหรับ PUT /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/dates
 *
 * ย้ายชุดวันเข้าพัก (shift-only) บนบิล paid|confirmed + BR confirmed (room_id nullable):
 * - จำนวนคืนต้องเท่าเดิมเป๊ะ (มติ owner)
 * - มีห้อง → strict same-room (holdingSlot overlap + maintenance period; reserved ไม่บล็อก)
 * - ไม่มีห้อง → ข้ามตรวจห้อง
 * - addon extra_beds_by_night เลื่อน key ตาม delta คง qty เดิม
 * - reprice ทั้งบิล + syncIsPaid (มติ v2 ข้อ 1)
 */
class RoomDatesChangeTest extends TestCase
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

    protected function createAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    protected function createUserWithRole(string $role = 'user'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function createRoomTypeWithRate(
        string $nameEn,
        int $maxGuests,
        int $dailyRate,
        ?int $monthRate = null,
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
        if ($monthRate !== null) {
            GlobalRate::create([
                'rate_type' => 'month',
                'room_type_id' => $rt->id,
                'code' => null,
                'name_en' => $nameEn.' Month',
                'default_price' => $monthRate,
                'is_active' => true,
            ]);
        }

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

    private function createCompletedPayment(Booking $booking, int $amount): void
    {
        Payment::create([
            'booking_id' => $booking->id,
            'amount' => $amount,
            'status' => 'completed',
        ]);
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
        return "/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/dates";
    }

    private function today(): Carbon
    {
        return Carbon::today(BookingRule::TIMEZONE)->startOfDay();
    }

    // =========================================================
    // 🔒 1. Auth & Scope
    // =========================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);

        $response = $this->putJson($this->endpoint($booking, $br), [
            'check_in' => $this->today()->copy()->addDays(7)->toDateString(),
            'check_out' => $this->today()->copy()->addDays(11)->toDateString(),
        ]);

        $response->assertStatus(401);
    }

    public function test_non_admin_non_staff_role_returns_403(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);

        foreach (['user', 'guest', 'ku_member', 'housekeeping', 'system'] as $role) {
            $actor = $this->createUserWithRole($role);

            $response = $this->actingAs($actor, 'sanctum')
                ->putJson($this->endpoint($booking, $br), [
                    'check_in' => $this->today()->copy()->addDays(7)->toDateString(),
                    'check_out' => $this->today()->copy()->addDays(11)->toDateString(),
                ]);

            // 🛡️ 403 กระโดนที่ CheckRole middleware — body เป็น {"error": ...} ไม่มี key status
            $response->assertStatus(403);
        }
    }

    public function test_cross_booking_room_id_returns_404(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking1 = $this->createBooking();
        $booking2 = $this->createBooking();
        $brOfBooking2 = $this->createBookingRoom($booking2, $standard, $this->createRoom($standard));
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking1, $brOfBooking2), [
                'check_in' => $this->today()->copy()->addDays(7)->toDateString(),
                'check_out' => $this->today()->copy()->addDays(11)->toDateString(),
            ]);

        $response->assertStatus(404)->assertJsonPath('status', 'error');
    }

    // =========================================================
    // 🛡️ 2. Status gates
    // =========================================================

    public function test_container_invalid_status_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $admin = $this->createAdmin();

        foreach (['draft', 'pending', 'verify_error'] as $containerStatus) {
            $booking = $this->createBooking(['status' => $containerStatus]);
            $br = $this->createBookingRoom($booking, $standard, $this->createRoom($standard));

            $response = $this->actingAs($admin, 'sanctum')
                ->putJson($this->endpoint($booking, $br), [
                    'check_in' => $this->today()->copy()->addDays(7)->toDateString(),
                    'check_out' => $this->today()->copy()->addDays(11)->toDateString(),
                ]);

            $response->assertStatus(422)->assertJsonPath('status', 'error');
        }
    }

    public function test_br_status_draft_or_checked_in_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $admin = $this->createAdmin();
        $target = [
            'check_in' => $this->today()->copy()->addDays(7)->toDateString(),
            'check_out' => $this->today()->copy()->addDays(11)->toDateString(),
        ];

        // BR draft (บิล paid) → 422 ชี้ PUT BR
        $paidBooking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);
        $this->createCompletedPayment($paidBooking, 4000);
        $draftBr = $this->createBookingRoom($paidBooking, $standard, $this->createRoom($standard), 'draft');

        $response = $this->actingAs($admin, 'sanctum')->putJson($this->endpoint($paidBooking, $draftBr), $target);
        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringContainsString('confirmed', $response->json('message'));

        // BR checked_in (บิล confirmed) → 422 out-of-scope
        $confirmedBooking = $this->createBooking();
        $checkedInBr = $this->createBookingRoom($confirmedBooking, $standard, $this->createRoom($standard), 'checked_in');

        $response = $this->actingAs($admin, 'sanctum')->putJson($this->endpoint($confirmedBooking, $checkedInBr), $target);
        $response->assertStatus(422)->assertJsonPath('status', 'error');
    }

    // =========================================================
    // 📋 3. Validation & shift-only guards
    // =========================================================

    public function test_sending_cut_params_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $deluxe = $this->createRoomTypeWithRate('Deluxe', 3, 2000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, $this->createRoom($standard));
        $anotherRoom = $this->createRoom($deluxe);
        $admin = $this->createAdmin();
        $base = [
            'check_in' => $this->today()->copy()->addDays(7)->toDateString(),
            'check_out' => $this->today()->copy()->addDays(11)->toDateString(),
        ];

        foreach ([
            'room_id' => array_merge($base, ['room_id' => $anotherRoom->id]),
            'room_type_id' => array_merge($base, ['room_type_id' => $deluxe->id]),
            'price_per_night' => array_merge($base, ['price_per_night' => 1200]),
        ] as $cutParam => $payload) {
            $response = $this->actingAs($admin, 'sanctum')->putJson($this->endpoint($booking, $br), $payload);

            $response->assertStatus(422)->assertJsonPath('status', 'error');
            $this->assertStringContainsString('ไม่รองรับ', $response->json('message'), "param: {$cutParam}");
        }
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $this->today()->copy()->addDays(9)->toDateString(),
                'check_out' => $this->today()->copy()->addDays(8)->toDateString(),
            ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
    }

    public function test_shift_must_keep_same_nights(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard); // เดิม 4 คืน
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $this->today()->copy()->addDays(7)->toDateString(),
                'check_out' => $this->today()->copy()->addDays(12)->toDateString(), // 5 คืน
            ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringContainsString('คงจำนวนคืนเดิม', $response->json('message'));

        $br->refresh();
        $this->assertSame($this->today()->toDateString(), $br->check_in->toDateString());
    }

    public function test_check_in_in_the_past_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null, 'confirmed', 1, [
            'check_in' => $this->today()->copy()->addDays(10)->toDateString(),
            'check_out' => $this->today()->copy()->addDays(14)->toDateString(),
        ]);
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $this->today()->copy()->subDay()->toDateString(),
                'check_out' => $this->today()->copy()->addDays(3)->toDateString(),
            ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringContainsString('อดีต', $response->json('message'));
    }

    public function test_same_dates_returns_422(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard);
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $this->today()->toDateString(),
                'check_out' => $this->today()->copy()->addDays(4)->toDateString(),
            ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringContainsString('ไม่มีอะไรให้ย้าย', $response->json('message'));
    }

    // =========================================================
    // 🌙 4. Monthly tier caps (คงเขตเดิมแม้คืนไม่เปลี่ยน)
    // =========================================================

    public function test_staff_blocked_at_monthly_tier_20_nights(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking(['total_amount' => 20000]);
        $br = $this->createBookingRoom($booking, $standard, $this->createRoom($standard), 'confirmed', 1, [
            'check_out' => $this->today()->copy()->addDays(20)->toDateString(),
            'room_amount' => 20000,
            'amount' => 20000,
        ]);
        $staff = $this->createStaff();

        $response = $this->actingAs($staff, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $this->today()->copy()->addDays(7)->toDateString(),
                'check_out' => $this->today()->copy()->addDays(27)->toDateString(),
            ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringContainsString('เขตบริการของผู้ดูแลระบบ', $response->json('message'));
    }

    public function test_exceeding_monthly_max_nights_blocked_even_for_admin(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking(['total_amount' => 91000]);
        $br = $this->createBookingRoom($booking, $standard, $this->createRoom($standard), 'confirmed', 1, [
            'check_out' => $this->today()->copy()->addDays(91)->toDateString(),
            'room_amount' => 91000,
            'amount' => 91000,
        ]);
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $this->today()->copy()->addDays(7)->toDateString(),
                'check_out' => $this->today()->copy()->addDays(98)->toDateString(),
            ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringContainsString('สูงสุด 90', $response->json('message'));
    }

    // =========================================================
    // 🏨 5. Room availability (strict same-room — เฉพาะ BR ที่มีห้อง)
    // =========================================================

    public function test_shift_blocked_when_room_taken_in_new_window(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $room = $this->createRoom($standard);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, $room); // เดิม today..today+4

        // BR อื่นครอบห้องเดียวกันช่วงใหม่ today+8..today+12 (confirmed = holdingSlot)
        $otherBooking = $this->createBooking(['total_amount' => 4000]);
        $this->createBookingRoom($otherBooking, $standard, $room, 'confirmed', 1, [
            'check_in' => $this->today()->copy()->addDays(8)->toDateString(),
            'check_out' => $this->today()->copy()->addDays(12)->toDateString(),
        ]);

        $admin = $this->createAdmin();
        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $this->today()->copy()->addDays(8)->toDateString(),
                'check_out' => $this->today()->copy()->addDays(12)->toDateString(),
            ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringContainsString('มีการจองอื่นทับช่วงวันใหม่', $response->json('message'));
        $this->assertStringContainsString($room->room_number, $response->json('message'));

        $br->refresh();
        $this->assertSame($this->today()->toDateString(), $br->check_in->toDateString());
    }

    public function test_shift_blocked_by_maintenance_period(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $room = $this->createRoom($standard);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, $room);
        $admin = $this->createAdmin();

        RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => RoomStatePeriod::KIND_MAINTENANCE,
            'start_date' => $this->today()->copy()->addDays(8)->toDateString(),
            'end_date' => $this->today()->copy()->addDays(12)->toDateString(),
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $this->today()->copy()->addDays(8)->toDateString(),
                'check_out' => $this->today()->copy()->addDays(12)->toDateString(),
            ]);

        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertStringContainsString('ซ่อมแซม', $response->json('message'));
    }

    // =========================================================
    // ✅ 6. Happy paths
    // =========================================================

    public function test_admin_can_shift_room_assigned_booking_keeps_room(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $room = $this->createRoom($standard);
        $booking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);
        $this->createCompletedPayment($booking, 4000);
        $br = $this->createBookingRoom($booking, $standard, $room); // today..today+4
        $admin = $this->createAdmin();

        $newIn = $this->today()->copy()->addDays(7);
        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $newIn->toDateString(),
                'check_out' => $newIn->copy()->addDays(4)->toDateString(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('room_number', $room->room_number)
            ->assertJsonPath('nights', 4)
            ->assertJsonPath('total_amount', 4000)
            ->assertJsonPath('surcharge_amount', 0)
            ->assertJsonPath('paid_amount', 4000)
            ->assertJsonPath('outstanding_amount', 0);

        $br->refresh();
        $this->assertSame($newIn->toDateString(), $br->check_in->toDateString());
        $this->assertSame($newIn->copy()->addDays(4)->toDateString(), $br->check_out->toDateString());
        $this->assertSame((string) $room->id, (string) $br->room_id);
        $this->assertSame('confirmed', $br->status);

        $booking->refresh();
        $this->assertTrue((bool) $booking->is_paid);
        $this->assertAmountInvariant($booking);

        // 📝 audit note row (from=to=confirmed)
        $log = StatusChangeLog::where('entity_type', 'booking_room')
            ->where('entity_id', $br->id)
            ->latest('created_at')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('confirmed', $log->from_status);
        $this->assertSame('confirmed', $log->to_status);
        $this->assertStringContainsString('ย้ายชุดวันเข้าพัก', $log->note);
    }

    public function test_shift_confirmed_container_without_room_skips_room_check(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, null); // room_id = null
        $admin = $this->createAdmin();

        $newIn = $this->today()->copy()->addDays(7);
        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $newIn->toDateString(),
                'check_out' => $newIn->copy()->addDays(4)->toDateString(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('room_number', null);

        $br->refresh();
        $this->assertNull($br->room_id);
        $this->assertSame($newIn->toDateString(), $br->check_in->toDateString());
        $this->assertAmountInvariant($booking->fresh());
    }

    public function test_shift_earlier_allowed_when_new_window_free(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $room = $this->createRoom($standard);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, $room, 'confirmed', 1, [
            'check_in' => $this->today()->copy()->addDays(10)->toDateString(),
            'check_out' => $this->today()->copy()->addDays(14)->toDateString(),
        ]);
        $admin = $this->createAdmin();

        $newIn = $this->today()->copy()->addDays(2);
        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $newIn->toDateString(),
                'check_out' => $newIn->copy()->addDays(4)->toDateString(),
            ]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');

        $br->refresh();
        $this->assertSame($newIn->toDateString(), $br->check_in->toDateString());
    }

    public function test_admin_shifts_20_night_booking_monthly_pricing(): void
    {
        // 🌙 เขตรายเดือน — admin + 20 คืน → reprice ตาม month rate: intdiv(20 × 9000, 30) = 6000
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000, monthRate: 9000);
        $room = $this->createRoom($standard);
        $booking = $this->createBooking(['total_amount' => 20000]);
        $br = $this->createBookingRoom($booking, $standard, $room, 'confirmed', 1, [
            'check_out' => $this->today()->copy()->addDays(20)->toDateString(),
            'room_amount' => 20000,
            'amount' => 20000,
        ]);
        $admin = $this->createAdmin();

        $newIn = $this->today()->copy()->addDays(7);
        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $newIn->toDateString(),
                'check_out' => $newIn->copy()->addDays(20)->toDateString(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('nights', 20)
            ->assertJsonPath('total_amount', 6000);

        $br->refresh();
        $this->assertSame(6000, (int) $br->room_amount);
        $this->assertAmountInvariant($booking->fresh());
    }

    public function test_extra_beds_by_night_keys_shift_with_delta(): void
    {
        $standard = $this->createRoomTypeWithRate('Standard', 2, 1000);
        $room = $this->createRoom($standard);
        $booking = $this->createBooking();
        $br = $this->createBookingRoom($booking, $standard, $room);

        $today = $this->today();
        Addon::create([
            'booking_room_id' => $br->id,
            'breakfast_enabled' => true,
            'breakfast_code' => 'breakfast_100',
            'extra_beds_by_night' => [
                $today->copy()->addDay(1)->toDateString() => 1,
                $today->copy()->addDays(2)->toDateString() => 2,
            ],
            'early_hours' => 0,
            'late_hours' => 0,
        ]);

        $admin = $this->createAdmin();
        $newIn = $today->copy()->addDays(7); // delta +7
        $response = $this->actingAs($admin, 'sanctum')
            ->putJson($this->endpoint($booking, $br), [
                'check_in' => $newIn->toDateString(),
                'check_out' => $newIn->copy()->addDays(4)->toDateString(),
            ]);

        $response->assertStatus(200)->assertJsonPath('status', 'success');

        // 🛏️ key เลื่อนตาม delta +7 คง qty เดิม
        $br->refresh();
        $beds = $br->addon->extra_beds_by_night;
        $this->assertSame(1, $beds[$today->copy()->addDays(8)->toDateString()] ?? null);
        $this->assertSame(2, $beds[$today->copy()->addDays(9)->toDateString()] ?? null);
        $this->assertArrayNotHasKey($today->copy()->addDay(1)->toDateString(), $beds);
        $this->assertArrayNotHasKey($today->copy()->addDays(2)->toDateString(), $beds);

        // 💰 room 4000 + breakfast 100×4 คืน×1 คน = 400 + extra beds (1+2)×500 = 1500 → 5900
        $response->assertJsonPath('total_amount', 5900);
        $this->assertAmountInvariant($booking->fresh());
    }
}
