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
 * 📊 (05/10/26) excel-reports ticket 10 — addon pricing ใหม่ (spec §2.2/§3.4/§7)
 *
 *    - breakfast แยก 2 ชุด (breakfast_100/breakfast_200) คิดเงิน × คืน
 *    - extra-bed รายคืน (extra_beds_by_night) จำนวนต่อคืนไม่เท่ากันได้
 *    - legacy alias normalize: breakfast (int) → set_200 · extra_bed/extra_beds (int) → flat map ทุกคืน
 *    - validate map คีย์นอกช่วง [check_in, check_out) → 422 · qty เกิน max_extra_beds → 422
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

    public function test_breakfast_two_sets_charged_per_night(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['breakfast_sets' => ['set_100' => 1, 'set_200' => 2]],
                ],
            ],
        ]);

        $response->assertStatus(201);

        $addon = Addon::where('booking_room_id', $response->json('booking_rooms.0.id'))->firstOrFail();
        $this->assertSame(1, $addon->breakfast_set_100);
        $this->assertSame(2, $addon->breakfast_set_200);
        // (1×100 + 2×200) × 3 คืน = 1500 — แก้ undercharge เดิมคิดครั้งเดียว
        $this->assertSame(1500, $addon->breakfast_price);
        // total = ห้อง 3000 + breakfast 1500 = 4500
        $this->assertSame(4500, $response->json('total_amount'));
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

    public function test_legacy_breakfast_and_extra_bed_normalize(): void
    {
        $dates = $this->dates();

        // legacy: breakfast (int) → set_200 · extra_bed (int) → flat map ทุกคืน
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
        $this->assertSame(0, $addon->breakfast_set_100);
        $this->assertSame(2, $addon->breakfast_set_200);
        $this->assertSame(200 * 2 * 3, $addon->breakfast_price); // × คืน
        $this->assertSame(1, $addon->extra_beds_max);
        $this->assertCount(3, $addon->extra_beds_by_night); // flat ทุกคืน
        $this->assertSame(500 * 1 * 3, $addon->extra_bed_price);
        // total = 3000 + 1200 + 1500 = 5700
        $this->assertSame(5700, $response->json('total_amount'));
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
        // เรทชวนเลขไม่สวย — breakfast_100 = 7 → 2 ชุด × 7 × 3 คืน = 42
        GlobalRate::where('code', 'breakfast_100')->update(['default_price' => 7]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    ...$this->dates(),
                    'addons' => ['breakfast_sets' => ['set_100' => 2]],
                ],
            ],
        ]);

        $response->assertStatus(201);
        // amount = 3000 + 42 = 3042 → RoundToTen ท้ายสุด = 3050
        $booking = Booking::findOrFail($response->json('booking_id'));
        $this->assertSame(3050, $booking->bookingRooms->first()->amount);
        $this->assertSame(3050, $booking->total_amount);
        $this->assertAmountInvariant($booking);
    }
}
