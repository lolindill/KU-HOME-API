<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\GlobalRate;
use App\Models\Organization;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🏛️ Org booking — wayfinder/organization-bookings ticket 03/06
 *
 * โหมด org บน POST /bookings เดิม: `organize` = erp code → lookup เป็น FK organization_id
 * · user×organize mutually exclusive (422) · organize บังคับ customer_name (phone/email nullable)
 * · เฉพาะ role:admin + source='admin' · snapshot 3 columns บน bookings
 * · กฎผลพวง user_id=null สืบทอดโหมด B ของ ticket 02 (ไม่ dedup · ราคา daily · ไม่มี cap)
 */
class OrgBookingTest extends TestCase
{
    use RefreshDatabase;

    private static int $roomSeq = 0;

    private function createRoomTypeWithRates(int $dailyBaht = 1200, ?int $dailyKuBaht = 1000): RoomType
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe Room',
            'name_th' => 'ห้องดีลักซ์',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'code' => null,
            'name_en' => 'Deluxe Daily General',
            'default_price' => $dailyBaht,
            'is_active' => true,
        ]);

        if ($dailyKuBaht !== null) {
            GlobalRate::create([
                'rate_type' => 'daily_ku',
                'room_type_id' => $rt->id,
                'code' => null,
                'name_en' => 'Deluxe Daily KU Member',
                'default_price' => $dailyKuBaht,
                'is_active' => true,
            ]);
        }

        return $rt;
    }

    private function createRoom(RoomType $roomType): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '30'.(++self::$roomSeq),
            'status' => 'available',
        ]);
    }

    private function bookingPayload(string $roomTypeId, string $checkIn, string $checkOut): array
    {
        return [
            'booking_rooms' => [
                [
                    'room_type_id' => $roomTypeId,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ];
    }

    public function test_org_booking_links_organization_and_snapshots_contact(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $org = Organization::create(['erp' => 'ORG001', 'name' => 'บริษัท ตัวอย่าง จำกัด', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(7)->toDateString(); // 2 nights

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'admin',
            'organize' => 'ORG001',
            'customer_name' => 'สมชาย ใจดี',
            'customer_phone' => '0812345678',
            'customer_email' => 'somchai@example.com',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)));

        $response->assertStatus(201)
            ->assertJsonPath('user_id', null)
            ->assertJsonPath('organization_id', $org->id)
            ->assertJsonPath('customer_name', 'สมชาย ใจดี')
            ->assertJsonPath('customer_phone', '0812345678')
            ->assertJsonPath('customer_email', 'somchai@example.com');

        $booking = Booking::findOrFail($response->json('booking_id'));
        $this->assertNull($booking->user_id);
        $this->assertSame($org->id, $booking->organization_id);
        $this->assertSame('สมชาย ใจดี', $booking->customer_name);
        $this->assertSame('0812345678', $booking->customer_phone);
        $this->assertSame('somchai@example.com', $booking->customer_email);
    }

    public function test_org_booking_unknown_erp_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'admin',
            'organize' => 'NO_SUCH_ERP',
            'customer_name' => 'สมชาย ใจดี',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)))
            ->assertStatus(422);
    }

    public function test_org_booking_inactive_organization_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Organization::create(['erp' => 'ORG002', 'name' => 'องค์กรปิดใช้งาน', 'is_active' => false]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'admin',
            'organize' => 'ORG002',
            'customer_name' => 'สมชาย ใจดี',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)))
            ->assertStatus(422);
    }

    public function test_user_and_organize_are_mutually_exclusive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);
        Organization::create(['erp' => 'ORG003', 'name' => 'องค์กร A', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'admin',
            'user' => $target->id,
            'organize' => 'ORG003',
            'customer_name' => 'สมชาย ใจดี',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)))
            ->assertStatus(422);
    }

    public function test_organize_requires_customer_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Organization::create(['erp' => 'ORG004', 'name' => 'องค์กร B', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'admin',
            'organize' => 'ORG004',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)))
            ->assertStatus(422);
    }

    public function test_organize_requires_source_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Organization::create(['erp' => 'ORG005', 'name' => 'องค์กร C', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'online',
            'organize' => 'ORG005',
            'customer_name' => 'สมชาย ใจดี',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)))
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_send_organize_or_customer_name(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        Organization::create(['erp' => 'ORG006', 'name' => 'องค์กร D', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'online',
            'organize' => 'ORG006',
            'customer_name' => 'สมชาย ใจดี',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)))
            ->assertStatus(403);

        // customer_name โดยไม่มี organize (โหมด B) ก็เป็น field โหมด admin เหมือนกัน
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'online',
            'customer_name' => 'สมชาย ใจดี',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)))
            ->assertStatus(403);
    }

    public function test_org_booking_has_no_draft_dedup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Organization::create(['erp' => 'ORG007', 'name' => 'องค์กร E', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $payload = array_merge([
            'source' => 'admin',
            'organize' => 'ORG007',
            'customer_name' => 'สมชาย ใจดี',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut));

        // องค์กรเดียวกันจองซ้อนหลายบิลได้ (group booking ปกติ) — ไม่โดน draft-dedup
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', $payload)->assertStatus(201);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', $payload)->assertStatus(201);

        $org = Organization::where('erp', 'ORG007')->first();
        $this->assertSame(2, Booking::where('organization_id', $org->id)->count());
    }

    public function test_org_booking_prices_with_daily_rate_never_daily_ku(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Organization::create(['erp' => 'ORG008', 'name' => 'องค์กร F', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates(1200, 1000);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(7)->toDateString(); // 2 nights

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'admin',
            'organize' => 'ORG008',
            'customer_name' => 'สมชาย ใจดี',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)));

        $response->assertStatus(201);

        // ราคา daily (1,200) เสมอ — org ไม่มี role ให้ getEffectiveDailyRate ดู จึงไม่มีทางโดน daily_ku
        $booking = Booking::findOrFail($response->json('booking_id'));
        $this->assertSame(2400, $booking->total_amount);
    }

    public function test_org_booking_primary_guest_name_falls_back_to_customer_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Organization::create(['erp' => 'ORG009', 'name' => 'องค์กร G', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'admin',
            'organize' => 'ORG009',
            'customer_name' => 'สมหญิง รักงาน',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)));

        $response->assertStatus(201);

        // fallback chain ของ getPrimaryGuestName: user?->name → customer_name → 'Customer'
        $booking = Booking::with('bookingRooms')->findOrFail($response->json('booking_id'));
        $this->assertSame('สมหญิง รักงาน', $booking->primary_guest_name);
    }

    public function test_mode_b_customer_name_without_organize_stores_snapshot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'admin',
            'customer_name' => 'คุณเฮดเปล่า',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)));

        $response->assertStatus(201)
            ->assertJsonPath('user_id', null)
            ->assertJsonPath('organization_id', null)
            ->assertJsonPath('customer_name', 'คุณเฮดเปล่า');

        $booking = Booking::findOrFail($response->json('booking_id'));
        $this->assertNull($booking->user_id);
        $this->assertNull($booking->organization_id);
        $this->assertSame('คุณเฮดเปล่า', $booking->customer_name);
    }

    public function test_normal_booking_has_no_org_fields(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', array_merge([
            'source' => 'online',
        ], $this->bookingPayload($roomType->id, $checkIn, $checkOut)));

        $response->assertStatus(201)
            ->assertJsonPath('organization_id', null)
            ->assertJsonPath('customer_name', null);

        $booking = Booking::findOrFail($response->json('booking_id'));
        $this->assertNull($booking->organization_id);
        $this->assertNull($booking->customer_name);
        $this->assertNull($booking->customer_phone);
        $this->assertNull($booking->customer_email);
    }
}
