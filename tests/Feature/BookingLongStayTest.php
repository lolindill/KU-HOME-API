<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🗓️ (2026-09-24, SRS v2 REQ-014/026/027): การจองรายเดือน / จองเหมา (long stay)
 *    - stay_type derived: >= 30 คืน = 'monthly', >= 20 คืน = 'block' (จองเหมา —
 *      กติกาตามรายเดือน), อื่น ๆ = 'daily' (derived fields บน booking_rooms)
 *      🌙 (2026-10-22, srs-v2-gap-closure ticket 08/09): block_min_nights 21 → 20
 *
 * 🌙 (2026-10-22, ticket 09): เขตรายเดือนเป็นของ admin —
 *    - non-admin จองห้อง x >= 20 คืน → 422 reject (เขตรายเดือน admin-only)
 *    - admin จอง x >= 20 คืนได้ — ราคาคิดแบบรายเดือน intdiv(x × month, 30)
 *      (เดือนเต็ม + เศษคิดรายวันแบบเดือน month÷30 — เคสสูตรเต็มที่ PricingTierMonthlyTest)
 */
class BookingLongStayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Double',
            'name_th' => 'สแตนดาร์ด ดับเบิล',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $this->roomType->id,
            'code' => null,
            'name_en' => 'Standard Double Daily',
            'default_price' => 1500,
            'is_active' => true,
        ]);
        // 🌙 เรทรายเดือน — admin จองจากประตู 20 คืนขึ้นไปเข้าสูตร intdiv(x × month, 30)
        GlobalRate::create([
            'rate_type' => 'month',
            'room_type_id' => $this->roomType->id,
            'code' => 'month',
            'name_en' => 'Standard Double Monthly',
            'default_price' => 45000,
            'is_active' => true,
        ]);
        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $this->roomType->id,
            'room_number' => '201',
            'status' => 'available',
        ]);
    }

    private function bookingPayload(int $nights, ?User $asAdmin = null, ?User $targetUser = null): array
    {
        // non-admin ต้องจองล่วงหน้า >= 2 วัน — admin จองวันเดียวกันได้ (REQ-005/017)
        $checkIn = $asAdmin
            ? Carbon::today()->toDateString()
            : Carbon::today()->addDays(5)->toDateString();

        return array_filter([
            'source' => $asAdmin ? 'admin' : 'online',
            // โหมด A จองแทน — ใส่โค้ดส่วนลดได้ต้องมีเจ้าของบิล (discount_redemptions.user_id NOT NULL)
            'user' => $targetUser?->id,
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => Carbon::parse($checkIn)->addDays($nights)->toDateString(),
                ],
            ],
        ], fn ($v) => $v !== null);
    }

    public function test_user_cannot_create_20_nights_monthly_territory_is_admin_only(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload(20))
            ->assertStatus(422);
    }

    public function test_user_cannot_create_30_nights(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload(30))
            ->assertStatus(422);
    }

    public function test_user_19_nights_is_daily(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload(19))
            ->assertStatus(201)
            ->assertJsonPath('booking_rooms.0.nights', 19)
            ->assertJsonPath('booking_rooms.0.stay_type', 'daily');
    }

    public function test_20_nights_is_block_by_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload(20, $admin))
            ->assertStatus(201)
            ->assertJsonPath('booking_rooms.0.nights', 20)
            ->assertJsonPath('booking_rooms.0.stay_type', 'block');

        // 🌙 เข้าสูตรรายเดือน: intdiv(20 × 45,000, 30) = 30,000
        $this->assertDatabaseHas('booking_rooms', [
            'booking_id' => $response->json('booking_id'),
            'room_amount' => 30000,
        ]);
    }

    public function test_21_nights_is_block_same_day_by_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload(21, $admin))
            ->assertStatus(201)
            ->assertJsonPath('booking_rooms.0.nights', 21)
            ->assertJsonPath('booking_rooms.0.stay_type', 'block');
    }

    public function test_30_nights_is_monthly_by_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload(30, $admin))
            ->assertStatus(201)
            ->assertJsonPath('booking_rooms.0.nights', 30)
            ->assertJsonPath('booking_rooms.0.stay_type', 'monthly');
    }

    public function test_60_nights_is_not_capped_by_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload(60, $admin))
            ->assertStatus(201)
            ->assertJsonPath('booking_rooms.0.nights', 60)
            ->assertJsonPath('booking_rooms.0.stay_type', 'monthly');
    }

    public function test_monthly_booking_accepts_discount_code(): void
    {
        // 🎟️ REQ-026: การจองแบบรายเดือน "ทำส่วนลดราคาห้องพักได้"
        Discount::create([
            'code' => 'MONTHLY10',
            'type' => 'percent',
            'value' => 10,
            'max_uses' => 10,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $targetUser = User::factory()->create();
        $payload = $this->bookingPayload(30, $admin, $targetUser);
        $payload['discount_code'] = 'MONTHLY10';

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201);

        // 🌙 room_amount = intdiv(30 × 45,000, 30) = 45,000 — ส่วนลด 10% = 4,500 บาท (integer baht)
        $this->assertDatabaseHas('booking_rooms', [
            'booking_id' => $response->json('booking_id'),
            'room_amount' => 45000,
            'discount_amount' => 4500,
        ]);
        $this->assertEquals(40500, $response->json('total_amount'));
    }
}
