<?php

namespace Tests\Feature;

use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🗓️ (2026-11-06): เพดานบนของเขตรายเดือน — booking.monthly_max_nights (90 คืน)
 *
 *    กติกา: ห้อง x > 90 คืน → 422 reject **ทุก role รวม admin**
 *    (จองรายเดือน/จองเหมายาวสุด 90 วัน — เกินนี้ต้องแบ่งเป็นหลาย booking)
 *    · บิลสั้น < block_min_nights (20) คืนไม่เกี่ยว
 *    · non-admin x >= 20 คืนยังโดน 422 เขต admin อยู่เดิม (ข้อความเขตแสดงก่อนข้อความเพดาน)
 *    · ปิดครบ 4 write paths (create / add-rooms / edit room / batch edit) —
 *      ทุก path ผ่าน BookingRule::guardMonthlyNights() จุดเดียว
 *
 * Fixture อิงเรทจริงของ Deluxe: daily 1,200 · month 18,000
 * (90 คืน = intdiv(90 × 18000, 30) = 54,000)
 */
class MonthlyMaxNightsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->user = User::factory()->create(['role' => 'user']);

        $this->roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe',
            'name_th' => 'ดีลักซ์',
            'max_guests' => 3,
            'extra_bed_enabled' => true,
        ]);

        $rates = [
            ['rate_type' => 'daily', 'code' => 'daily', 'price' => 1200],
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

        // 4 ห้องกายภาพ — พอสำหรับเคส create + add-rooms
        for ($i = 0; $i < 4; $i++) {
            Room::create([
                'room_type_id' => $this->roomType->id,
                'room_number' => (string) (400 + $i),
                'status' => 'available',
            ]);
        }
    }

    /**
     * payload จอง 1 ห้อง $nights คืน (admin จองวันนี้ / user จอง +5 วัน)
     */
    private function payload(int $nights, bool $asAdmin = true): array
    {
        $checkIn = $asAdmin
            ? Carbon::today()->toDateString()
            : Carbon::today()->addDays(5)->toDateString();

        return [
            'source' => $asAdmin ? 'admin' : 'online',
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
    // 🗓️ Boundary create — 90 ผ่าน / 91 โดน 422 (รวม admin)
    // =========================================================

    public function test_admin_create_90_nights_allowed(): void
    {
        // 90 คืน = intdiv(90 × 18000, 30) = 54,000 — เพดานพอดี
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(90))
            ->assertStatus(201)
            ->assertJsonPath('booking_rooms.0.stay_type', 'monthly');

        $this->assertDatabaseHas('booking_rooms', ['room_amount' => 54000]);
    }

    public function test_admin_create_91_nights_rejected(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(91))
            ->assertStatus(422);

        $this->assertStringContainsString(
            'สูงสุด 90 คืน',
            $response->json('message').' '.json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
        );
        $this->assertDatabaseCount('bookings', 0);
    }

    // =========================================================
    // 🗓️ ปิดครบ 4 write paths — add-rooms / edit room / batch edit
    // =========================================================

    public function test_admin_add_rooms_91_nights_rejected(): void
    {
        $created = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(30))
            ->assertStatus(201);

        $checkIn = Carbon::today()->toDateString();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/bookings/{$created->json('booking_id')}/rooms", [
                'booking_rooms' => [[
                    'room_type_id' => $this->roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => Carbon::parse($checkIn)->addDays(91)->toDateString(),
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_admin_edit_room_to_91_nights_rejected(): void
    {
        $created = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(45))
            ->assertStatus(201);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/bookings/'.$created->json('booking_id').'/rooms/'.$created->json('booking_rooms.0.id'), [
                'check_out' => Carbon::parse($created->json('booking_rooms.0.check_in'))->addDays(91)->toDateString(),
            ])
            ->assertStatus(422);

        // คืนเดิม (45) ต้องคงอยู่ — guard ยิงก่อนเขียน DB
        $this->assertDatabaseHas('booking_rooms', [
            'id' => $created->json('booking_rooms.0.id'),
            'room_amount' => intdiv(45 * 18000, 30),
        ]);
    }

    public function test_admin_batch_edit_to_91_nights_rejected(): void
    {
        $created = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(45))
            ->assertStatus(201);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/bookings/'.$created->json('booking_id').'/rooms', [
                'booking_rooms' => [[
                    'booking_room_id' => $created->json('booking_rooms.0.id'),
                    'check_out' => Carbon::parse($created->json('booking_rooms.0.check_in'))->addDays(91)->toDateString(),
                ]],
            ])
            ->assertStatus(422);
    }

    // =========================================================
    // 🌙 เส้นทาง non-admin — เขต admin (>=20) แสดงก่อนเพดาน, บิลสั้นไม่เกี่ยว
    // =========================================================

    public function test_user_91_nights_gets_monthly_zone_message(): void
    {
        // non-admin x > 90 ยังโดนข้อความเขต admin (>=20) เป็นอันดับแรก — พฤติกรรมคงเดิม
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(91, asAdmin: false))
            ->assertStatus(422);

        $this->assertStringContainsString(
            'เขตบริการของผู้ดูแลระบบ',
            json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_user_short_booking_unaffected(): void
    {
        // 10 คืน — ต่ำกว่าเขตรายเดือนทั้งสองกฎ → ปกติ
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(10, asAdmin: false))
            ->assertStatus(201)
            ->assertJsonPath('booking_rooms.0.stay_type', 'daily');
    }

    // =========================================================
    // 🗓️ เพดานปรับได้ผ่าน config — พิสูจน์ว่าอ่านจาก booking.monthly_max_nights
    // =========================================================

    public function test_max_nights_config_is_tunable(): void
    {
        config(['booking.monthly_max_nights' => 30]);

        // 30 คืน = พอดีเพดานใหม่ → ผ่าน (intdiv(30 × 18000, 30) = 18,000)
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(30))
            ->assertStatus(201);

        // 31 คืน → โดนเพดานที่ปรับแล้ว
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload(31))
            ->assertStatus(422);
    }
}
