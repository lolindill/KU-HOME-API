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
 * 🌙 (2026-10-22, srs-v2-gap-closure ticket 09): pricing 2 เส้นทาง + สูตรรายเดือน
 *
 * มติ owner 2026-10-22 (ตามเอกสารหลักเกณฑ์ของโรงแรม):
 *   - non-admin: daily เส้นตรงเฉพาะ x < 20 คืน · ห้อง x >= 20 → 422 (เขตรายเดือน admin-only)
 *   - admin: ห้อง x >= 20 คืน → intdiv(x × month_rate, 30)
 *     (เดือนเต็ม 30 วัน + เศษคิดรายวันแบบเดือน month÷30 — เช่น Deluxe month 18,000:
 *      45 คืน = 18,000 + 15×600 = 27,000 ตรงตัวอย่างในเอกสาร)
 *   - กฎ "เศษ >= 20 คืน ยกบล็อกเดือน" (amendment 2026-10-20) หมดไป — สูตรนี้ monotonic
 *   - group min_5/min_10 ใช้กับบิลสั้น (< 20 คืน) และเป็น fallback เมื่อไม่มีเรท month
 *   - set_room_price ชนะเด็ดขาดข้ามสูตรรายเดือน (net = set_price × x)
 *   - ไม่มีเรท month → fallback daily เส้นตรง
 *
 * Fixture อิงเรทจริงของ Deluxe: daily 1,200 · daily_ku 1,000 · group 900/750 · month 18,000
 */
class PricingTierMonthlyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private User $kuMember;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->user = User::factory()->create(['role' => 'user']);
        $this->kuMember = User::factory()->create(['role' => 'ku_member']);

        $this->roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe',
            'name_th' => 'ดีลักซ์',
            'max_guests' => 3,
            'extra_bed_enabled' => true,
        ]);

        $rates = [
            ['rate_type' => 'daily', 'code' => 'daily', 'price' => 1200],
            ['rate_type' => 'daily_ku', 'code' => 'daily_ku', 'price' => 1000],
            ['rate_type' => 'group', 'code' => 'min_5_rooms', 'price' => 900],
            ['rate_type' => 'group', 'code' => 'min_10_rooms', 'price' => 750],
            ['rate_type' => 'month', 'code' => 'month', 'price' => 18000],
        ];
        foreach ($rates as $rate) {
            GlobalRate::create([
                'rate_type' => $rate['rate_type'],
                'room_type_id' => $this->roomType->id,
                'code' => $rate['code'],
                'name_en' => 'Deluxe '.$rate['code'],
                'default_price' => $rate['price'],
                'is_active' => true,
            ]);
        }

        // 12 ห้องกายภาพ — รองรับเคส 6/11 ห้อง
        for ($i = 0; $i < 12; $i++) {
            Room::create([
                'room_type_id' => $this->roomType->id,
                'room_number' => (string) (300 + $i),
                'status' => 'available',
            ]);
        }
    }

    /**
     * payload จอง $rooms ห้อง ห้องละ $nights คืน (admin จองวันนี้ / user จอง +5 วัน)
     */
    private function payload(int $nights, int $rooms = 1, ?User $targetUser = null, ?string $discountCode = null): array
    {
        $asAdmin = true; // ทุกเคสในไฟล์นี้ยิงผ่าน actingAs($this->admin) ยกเว้นเคส user จองเอง
        $checkIn = $asAdmin
            ? Carbon::today()->toDateString()
            : Carbon::today()->addDays(5)->toDateString();

        $bookingRooms = [];
        for ($i = 0; $i < $rooms; $i++) {
            $bookingRooms[] = [
                'room_type_id' => $this->roomType->id,
                'check_in' => $checkIn,
                'check_out' => Carbon::parse($checkIn)->addDays($nights)->toDateString(),
            ];
        }

        return array_filter([
            'source' => 'admin',
            'user' => $targetUser?->id,
            'booking_rooms' => $bookingRooms,
            'discount_code' => $discountCode,
        ], fn ($v) => $v !== null);
    }

    private function payloadForUser(int $nights): array
    {
        $checkIn = Carbon::today()->addDays(5)->toDateString();

        return [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => Carbon::parse($checkIn)->addDays($nights)->toDateString(),
                ],
            ],
        ];
    }

    // =========================================================
    // 🌙 เส้นทาง admin — สูตรรายเดือน intdiv(x × month, 30)
    // =========================================================

    public function test_admin_45_nights_uses_month_formula_like_hotel_document(): void
    {
        // ตัวอย่างในเอกสาร: Deluxe 45 คืน = 1 เดือน (30 วัน) + 15 คืน = 18,000 + (600 × 15)
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(45))
            ->assertStatus(201)
            ->assertJsonPath('booking_rooms.0.stay_type', 'monthly');

        $this->assertDatabaseHas('booking_rooms', [
            'booking_id' => $response->json('booking_id'),
            'room_amount' => 27000,
            'discount_amount' => 0,
        ]);
        $this->assertEquals(27000, $response->json('total_amount'));
    }

    public function test_admin_50_nights_is_monotonic_no_month_block_rule(): void
    {
        // เศษ 20 คืน = 600 × 20 = 12,000 ต่ำกว่าบล็อกเดือน — ต้องคิด prorate ตรง ๆ
        // (กฎ b = floor(y/20) เดิมทำให้ 50 คืน = 36,000 แพงกว่า 49 คืน = 29,400 → เลิกแล้ว)
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(50))
            ->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', ['room_amount' => intdiv(50 * 18000, 30)]);
    }

    public function test_admin_25_nights_in_block_window_is_prorated(): void
    {
        // 20-29 คืน = เขตรายเดือน (admin-only) — คิดราคาต่อวันแบบเดือน: 25 × 600 = 15,000
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(25))
            ->assertStatus(201)
            ->assertJsonPath('booking_rooms.0.stay_type', 'block');

        $this->assertDatabaseHas('booking_rooms', ['room_amount' => 15000]);
    }

    public function test_admin_29_and_30_nights_are_monotonic(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(29))
            ->assertStatus(201);
        $this->assertDatabaseHas('booking_rooms', ['room_amount' => intdiv(29 * 18000, 30)]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(30))
            ->assertStatus(201);
        $this->assertDatabaseHas('booking_rooms', ['room_amount' => 18000]);
    }

    public function test_admin_below_gate_uses_daily_straight_line(): void
    {
        // 19 คืน — ต่ำกว่าประตู 20 → daily เส้นตรง 19 × 1,200 = 22,800
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(19))
            ->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', ['room_amount' => 22800]);
    }

    public function test_admin_month_territory_ignores_ku_member_daily_ku(): void
    {
        // ส่วนรายเดือนใช้เรท month ล้วน — ไม่ปน daily_ku ของเจ้าของบิล
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(45, targetUser: $this->kuMember))
            ->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', ['room_amount' => 27000]);
    }

    public function test_admin_short_bill_target_ku_member_uses_daily_ku(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(10, targetUser: $this->kuMember))
            ->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', ['room_amount' => 10000]);
    }

    public function test_admin_group_rate_does_not_touch_month_territory(): void
    {
        // 6 ห้อง × 45 คืน — เขตเดือนคิด month ล้วน group ไม่เข้ามา
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(45, rooms: 6))
            ->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', [
            'booking_id' => $response->json('booking_id'),
            'room_amount' => 27000,
        ]);
        $this->assertEquals(27000 * 6, $response->json('total_amount'));
    }

    public function test_admin_6_rooms_short_bill_uses_group_min_5(): void
    {
        // 6 ห้อง × 3 คืน — ชั้นจำนวนห้อง → group min_5: 900 × 3 = 2,700/ห้อง
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(3, rooms: 6))
            ->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', [
            'booking_id' => $response->json('booking_id'),
            'room_amount' => 2700,
        ]);
        $this->assertEquals(2700 * 6, $response->json('total_amount'));
    }

    public function test_admin_11_rooms_short_bill_uses_group_min_10(): void
    {
        // 11 ห้อง × 3 คืน — group min_10: 750 × 3 = 2,250/ห้อง
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(3, rooms: 11))
            ->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', [
            'booking_id' => $response->json('booking_id'),
            'room_amount' => 2250,
        ]);
        $this->assertEquals(2250 * 11, $response->json('total_amount'));
    }

    // =========================================================
    // 🚫 เส้นทาง non-admin — เขตรายเดือนปิดทุก write path
    // =========================================================

    public function test_user_create_20_nights_rejected(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payloadForUser(20))
            ->assertStatus(422);
    }

    public function test_user_add_rooms_20_nights_rejected(): void
    {
        $bookingId = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payloadForUser(10))
            ->assertStatus(201)
            ->json('booking_id');

        $checkIn = Carbon::today()->addDays(40)->toDateString();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingId}/rooms", [
                'booking_rooms' => [[
                    'room_type_id' => $this->roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => Carbon::parse($checkIn)->addDays(20)->toDateString(),
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_user_single_edit_extending_to_20_nights_rejected(): void
    {
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payloadForUser(10))
            ->assertStatus(201);

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/bookings/'.$created->json('booking_id').'/rooms/'.$created->json('booking_rooms.0.id'), [
                'check_out' => Carbon::parse($created->json('booking_rooms.0.check_in'))->addDays(20)->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_user_batch_edit_extending_to_20_nights_rejected(): void
    {
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payloadForUser(10))
            ->assertStatus(201);

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/bookings/'.$created->json('booking_id').'/rooms', [
                'booking_rooms' => [[
                    'booking_room_id' => $created->json('booking_rooms.0.id'),
                    'check_out' => Carbon::parse($created->json('booking_rooms.0.check_in'))->addDays(25)->toDateString(),
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_user_addon_only_edit_on_admin_created_monthly_booking_allowed(): void
    {
        // admin จอง 45 คืนแทน user — เจ้าของแก้ field อื่น (ไม่แตะวันที่) ได้ ราคาคงเดิม
        $created = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(45, targetUser: $this->user))
            ->assertStatus(201);

        $bookingId = $created->json('booking_id');
        $bookingRoomId = $created->json('booking_rooms.0.id');

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}/rooms/{$bookingRoomId}", [
                'billing_comment' => 'ออกใบเสร็จชื่อบริษัท',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('booking_rooms', [
            'id' => $bookingRoomId,
            'room_amount' => 27000,
        ]);
    }

    // =========================================================
    // 🎟️ ส่วนลดกับยอด prorate
    // =========================================================

    public function test_set_room_price_wins_across_month_formula(): void
    {
        // ticket 08 edge 2: net = set_price × x ตรง ๆ — 500 × 45 = 22,500
        Discount::create([
            'code' => 'CONTRACT500',
            'type' => 'set_room_price',
            'value' => 500,
            'max_uses' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(45, targetUser: $this->user, discountCode: 'CONTRACT500'))
            ->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', [
            'booking_id' => $response->json('booking_id'),
            'room_amount' => 27000,
            'discount_amount' => 4500,
        ]);
        $this->assertEquals(22500, $response->json('total_amount'));
    }

    public function test_percent_discount_bases_on_prorated_amount(): void
    {
        Discount::create([
            'code' => 'MONTHLY10',
            'type' => 'percent',
            'value' => 10,
            'max_uses' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(45, targetUser: $this->user, discountCode: 'MONTHLY10'))
            ->assertStatus(201);

        // ฐาน = ยอด prorate 27,000 → ลด 2,700 → net 24,300
        $this->assertDatabaseHas('booking_rooms', [
            'booking_id' => $response->json('booking_id'),
            'discount_amount' => 2700,
        ]);
        $this->assertEquals(24300, $response->json('total_amount'));
    }

    // =========================================================
    // 🛟 Fallback เมื่อไม่มีเรท month
    // =========================================================

    public function test_missing_month_rate_falls_back_to_daily_straight_line(): void
    {
        $monthRow = GlobalRate::where('room_type_id', $this->roomType->id)
            ->where('rate_type', 'month')
            ->firstOrFail();
        $monthRow->is_active = false;
        $monthRow->save();

        // มติ owner 2026-10-22: month ไม่มี/inactive → daily เส้นตรง 45 × 1,200 = 54,000
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(45))
            ->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', ['room_amount' => 54000]);
    }
}
