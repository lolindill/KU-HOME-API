<?php

namespace Tests\Feature;

use App\Models\BookingRoom;
use App\Models\Discount;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Discount\DiscountService;
use Database\Seeders\DiscountSeeder;
use Database\Seeders\RoomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiscountSeederTest extends TestCase
{
    use RefreshDatabase;

    private function createRoomTypeWithDailyRate(int $dailyRateSatang = 120000): RoomType
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe King',
            'name_th' => 'ดีลักซ์ คิง',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'code' => null,
            'name_en' => 'Deluxe King Daily',
            'default_price' => $dailyRateSatang,
            'is_active' => true,
        ]);

        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $rt->id,
            'room_number' => '101',
            'status' => 'available',
        ]);

        return $rt;
    }

    public function test_seeds_all_discount_types_and_quota_rules(): void
    {
        $this->seed(RoomSeeder::class);
        $this->seed(DiscountSeeder::class);

        $deluxe = RoomType::where('name_en', 'Deluxe')->firstOrFail();

        // 1. Assert WELCOME10 (percent)
        $welcome = Discount::where('code', 'WELCOME10')->firstOrFail();
        $this->assertSame('percent', $welcome->type);
        $this->assertSame(10, $welcome->value);
        $this->assertNull($welcome->room_type_ids);
        $this->assertNull($welcome->max_uses);
        $this->assertNull($welcome->max_uses_per_user);
        $this->assertTrue((bool) $welcome->is_active);

        // 2. Assert SAVE200 (fixed)
        $save200 = Discount::where('code', 'SAVE200')->firstOrFail();
        $this->assertSame('fixed', $save200->type);
        $this->assertSame(20000, $save200->value);
        $this->assertNull($save200->room_type_ids);
        $this->assertNull($save200->max_uses);
        $this->assertNull($save200->max_uses_per_user);
        $this->assertTrue((bool) $save200->is_active);

        // 3. Assert DELUXE990 (set_room_price + targeted to Deluxe room type)
        $deluxe990 = Discount::where('code', 'DELUXE990')->firstOrFail();
        $this->assertSame('set_room_price', $deluxe990->type);
        $this->assertSame(99000, $deluxe990->value);
        $this->assertSame([$deluxe->id], $deluxe990->room_type_ids);
        $this->assertNull($deluxe990->max_uses);
        $this->assertNull($deluxe990->max_uses_per_user);
        $this->assertTrue((bool) $deluxe990->is_active);

        // 4. Assert LIMITED50 (quota limited)
        $limited50 = Discount::where('code', 'LIMITED50')->firstOrFail();
        $this->assertSame('fixed', $limited50->type);
        $this->assertSame(10000, $limited50->value);
        $this->assertSame(50, $limited50->max_uses);
        $this->assertSame(1, $limited50->max_uses_per_user);
        $this->assertTrue((bool) $limited50->is_active);

        $this->assertSame(4, Discount::count());
    }

    public function test_discount_seeder_is_idempotent(): void
    {
        $this->seed(DiscountSeeder::class);
        $this->seed(DiscountSeeder::class);

        $this->assertSame(4, Discount::count());
    }

    public function test_seeded_discounts_compute_expected_reductions(): void
    {
        $this->seed(RoomSeeder::class);
        $this->seed(DiscountSeeder::class);
        $service = app(DiscountService::class);

        $welcome = Discount::where('code', 'WELCOME10')->firstOrFail();
        $save200 = Discount::where('code', 'SAVE200')->firstOrFail();
        $deluxe990 = Discount::where('code', 'DELUXE990')->firstOrFail();
        $limited50 = Discount::where('code', 'LIMITED50')->firstOrFail();

        // สมมติห้องราคา 1,200 บาท/คืน (120,000 satang) พัก 2 คืน = รวม 240,000 satang
        $rate = 120000;
        $nights = 2;

        // WELCOME10: 10% จาก 240,000 = 24,000 satang (240 บาท)
        $this->assertSame(24000, $service->computeForRoom($welcome, $rate, $nights));

        // SAVE200: ลด 20,000 satang คงที่ต่อห้อง (200 บาท)
        $this->assertSame(20000, $service->computeForRoom($save200, $rate, $nights));

        // DELUXE990: ลดส่วนต่าง (120,000 - 99,000 = 21,000 satang ต่อคืน) x 2 คืน = 42,000 satang (420 บาท)
        $this->assertSame(42000, $service->computeForRoom($deluxe990, $rate, $nights));

        // LIMITED50: ลด 10,000 satang คงที่ต่อห้อง (100 บาท)
        $this->assertSame(10000, $service->computeForRoom($limited50, $rate, $nights));
    }

    public function test_deluxe990_targets_only_deluxe_rooms(): void
    {
        $this->seed(RoomSeeder::class);
        $this->seed(DiscountSeeder::class);
        $service = app(DiscountService::class);

        $deluxe = RoomType::where('name_en', 'Deluxe')->firstOrFail();
        $superior = RoomType::where('name_en', 'Superior')->firstOrFail();
        $deluxe990 = Discount::where('code', 'DELUXE990')->firstOrFail();

        $deluxeBr = new BookingRoom(['room_type_id' => $deluxe->id, 'check_in' => '2026-10-01', 'check_out' => '2026-10-03']);
        $superiorBr = new BookingRoom(['room_type_id' => $superior->id, 'check_in' => '2026-10-01', 'check_out' => '2026-10-03']);

        $this->assertTrue($service->isEligible($deluxe990, $deluxeBr));
        $this->assertFalse($service->isEligible($deluxe990, $superiorBr));
    }
}
