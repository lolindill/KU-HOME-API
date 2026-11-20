<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 📊 (05/10/26) excel-reports ticket 10 — addon pricing (spec §2.2/§3.4/§7)
 * 🍳 (19/11/26) breakfast reshape — toggle + เรทรายหัว:
 *
 *    - breakfast เป็น toggle (breakfast_enabled + breakfast_code จาก global_rates
 *      code ขึ้นต้น 'breakfast' — รองรับ 2 เรทขึ้นไป) คิดเงิน × คืน × หัว
 *      (จำนวนผู้เข้าพักของห้อง — guests ว่าง นับ 1)
 *    - extra-bed รายคืน (extra_beds_by_night) จำนวนต่อคืนไม่เท่ากันได้
 *    - legacy alias normalize: breakfast_sets (ชุดไหน > 0 → enabled ตามชุดนั้น) ·
 *      breakfast (int > 0) → enabled + code breakfast_200 · extra_bed/extra_beds (int) → flat map
 *    - validate breakfast_code ไม่อยู่ในเรท active → 422 · map คีย์นอกช่วง → 422 · qty เกิน max → 422
 *    - invariant Σ booking_rooms.amount == total_amount + RoundToTen ท้ายสุด
 */
class AddonPerNightPricingTest extends TestCase
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
            'name_en' => 'Deluxe',
            'name_th' => 'ดีลักซ์',
            'max_guests' => 3,
            'extra_bed_enabled' => true,
            'max_extra_beds' => 2,
            'extra_bed_price' => 999, // ⚠️ display-only — ต้องไม่ถูกใช้คิดเงิน (spec §2.3)
        ]);
        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $this->roomType->id,
            'room_number' => '9001',
            'status' => 'available',
        ]);

        GlobalRate::create([
            'rate_type' => 'daily', 'room_type_id' => $this->roomType->id, 'code' => null,
            'name_en' => 'Deluxe Daily', 'default_price' => 1000, 'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'breakfast_100',
            'name_en' => 'Breakfast Set 100', 'default_price' => 100, 'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'breakfast_200',
            'name_en' => 'Breakfast Set 200', 'default_price' => 200, 'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'addon', 'room_type_id' => null, 'code' => 'extra_bed',
            'name_en' => 'Extra Bed', 'default_price' => 500, 'is_active' => true,
        ]);
    }

    private function dates(): array
    {
        return [
            'check_in' => now()->addDays(2)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(), // 3 คืน: +2 +3 +4
        ];
    }

    public function test_breakfast_toggle_charged_per_night_per_guest(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'guests' => [['name' => 'สมชาย'], ['name' => 'สมหญิง']],
                    'addons' => ['breakfast_enabled' => true, 'breakfast_code' => 'breakfast_200'],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $addon = Addon::where('booking_room_id', $response->json('booking_rooms.0.id'))->firstOrFail();
        $this->assertSame(true, $addon->breakfast_enabled);
        $this->assertSame('breakfast_200', $addon->breakfast_code);
        // rate(200) × 3 คืน × 2 คน = 1200 — คิด × คืน × หัว
        $this->assertSame(1200, $addon->breakfast_price);
        // total = ห้อง 3000 + breakfast 1200 = 4200
        $this->assertSame(4200, $response->json('total_amount'));
        $this->assertAmountInvariant(Booking::findOrFail($response->json('booking_id')));
    }

    public function test_breakfast_toggle_without_guests_counts_one(): void
    {
        // guests ว่าง = นับ 1 คน (มติ owner 19/11/26 — กัน undercharge)
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['breakfast_enabled' => true, 'breakfast_code' => 'breakfast_200'],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $addon = Addon::where('booking_room_id', $response->json('booking_rooms.0.id'))->firstOrFail();
        $this->assertSame(200 * 3 * 1, $addon->breakfast_price);
    }

    public function test_breakfast_default_code_is_cheapest_active_rate(): void
    {
        // เปิด toggle แต่ไม่ส่ง code → default = เรทถูกสุดที่ active (breakfast_100)
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['breakfast_enabled' => true],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $addon = Addon::where('booking_room_id', $response->json('booking_rooms.0.id'))->firstOrFail();
        $this->assertSame('breakfast_100', $addon->breakfast_code);
        $this->assertSame(100 * 3 * 1, $addon->breakfast_price);
    }

    public function test_breakfast_invalid_code_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['breakfast_enabled' => true, 'breakfast_code' => 'breakfast_999'],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);
        $this->assertStringContainsString('breakfast_999', (string) $response->json('message'));
    }

    public function test_breakfast_disabled_no_charge(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'guests' => [['name' => 'สมชาย']],
                    'addons' => ['breakfast_enabled' => false],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $addon = Addon::where('booking_room_id', $response->json('booking_rooms.0.id'))->firstOrFail();
        $this->assertSame(false, $addon->breakfast_enabled);
        $this->assertSame(null, $addon->breakfast_code);
        $this->assertSame(0, $addon->breakfast_price);
        // total = ห้องล้วน 3000
        $this->assertSame(3000, $response->json('total_amount'));
    }

    public function test_legacy_breakfast_sets_map_to_code(): void
    {
        // legacy breakfast_sets — ชุดไหน > 0 → enabled + code ตามชุดนั้น
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['breakfast_sets' => ['set_100' => 1, 'set_200' => 0]],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $addon = Addon::where('booking_room_id', $response->json('booking_rooms.0.id'))->firstOrFail();
        $this->assertSame(true, $addon->breakfast_enabled);
        $this->assertSame('breakfast_100', $addon->breakfast_code);
        $this->assertSame(100 * 3 * 1, $addon->breakfast_price);
    }

    public function test_legacy_breakfast_and_extra_bed_normalize(): void
    {
        $dates = $this->dates();

        // legacy: breakfast (int > 0) → enabled + code breakfast_200 (เรทเดิม)
        //         extra_bed (int) → flat map ทุกคืน
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$dates,
                    'addons' => ['breakfast' => 2, 'extra_bed' => 1],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $addon = Addon::where('booking_room_id', $response->json('booking_rooms.0.id'))->firstOrFail();
        $this->assertSame(true, $addon->breakfast_enabled);
        $this->assertSame('breakfast_200', $addon->breakfast_code);
        $this->assertSame(200 * 3 * 1, $addon->breakfast_price); // × คืน × 1 (guests ว่าง = 1)
        $this->assertSame(1, $addon->extra_beds_max);
        $this->assertCount(3, $addon->extra_beds_by_night); // flat ทุกคืน
        $this->assertSame(500 * 1 * 3, $addon->extra_bed_price);
        // total = 3000 + 600 + 1500 = 5100
        $this->assertSame(5100, $response->json('total_amount'));
        $this->assertAmountInvariant(Booking::findOrFail($response->json('booking_id')));
    }

    public function test_extra_beds_by_night_varies_per_night(): void
    {
        $dates = $this->dates();
        $nights = [now()->addDays(2)->toDateString(), now()->addDays(3)->toDateString(), now()->addDays(4)->toDateString()];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$dates,
                    'addons' => ['extra_beds_by_night' => [$nights[0] => 1, $nights[1] => 2, $nights[2] => 1]],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $addon = Addon::where('booking_room_id', $response->json('booking_rooms.0.id'))->firstOrFail();
        $this->assertSame(2, $addon->extra_beds_max);
        $this->assertSame(4, $addon->extra_beds_total);
        // Σ qty รายคืน (1+2+1) × 500 = 2000
        $this->assertSame(2000, $addon->extra_bed_price);
        $this->assertSame(3000 + 2000, $response->json('total_amount'));
        $this->assertAmountInvariant(Booking::findOrFail($response->json('booking_id')));
    }

    public function test_extra_beds_key_outside_stay_range_rejected(): void
    {
        $nights = now()->addDays(9)->toDateString(); // นอกช่วง +2..+5

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['extra_beds_by_night' => [$nights => 1]],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);
        $this->assertStringContainsString('นอกช่วงคืนพัก', (string) $response->json('message'));
    }

    public function test_extra_beds_over_max_per_night_rejected(): void
    {
        $nights = now()->addDays(2)->toDateString(); // max_extra_beds = 2

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['extra_beds_by_night' => [$nights => 3]],
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['status' => 'error']);
        $this->assertStringContainsString('เกินขีดสุด', (string) $response->json('message'));
    }

    public function test_reprice_uses_global_rates_not_room_type_display_price(): void
    {
        // room_types.extra_bed_price = 999 (display-only) — คิดเงินต้องใช้ global_rates (500)
        $nights = now()->addDays(2)->toDateString();

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['extra_beds_by_night' => [$nights => 2]],
                ],
            ],
        ]);

        $response->assertStatus(201);
        // 2 เตียง × 500 (global_rates) = 1000 — ถ้าใช้ extra_bed_price (999) จะได้ 1998
        $addon = Addon::where('booking_room_id', $response->json('booking_rooms.0.id'))->firstOrFail();
        $this->assertSame(1000, $addon->extra_bed_price);
    }

    public function test_round_to_ten_applied_after_addon_totals(): void
    {
        // เรทชวนเลขไม่สวย — breakfast_100 = 7 → 1 ชุด × 7 × 3 คืน × 1 คน = 21
        GlobalRate::where('code', 'breakfast_100')->update(['default_price' => 7]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['breakfast_enabled' => true, 'breakfast_code' => 'breakfast_100'],
                ],
            ],
        ]);

        $response->assertStatus(201);
        // amount = 3000 + 21 = 3021 → RoundToTen ท้ายสุด = 3030
        $booking = Booking::findOrFail($response->json('booking_id'));
        $this->assertSame(3030, $booking->bookingRooms->first()->amount);
        $this->assertSame(3030, $booking->total_amount);
        $this->assertAmountInvariant($booking);
    }
}
