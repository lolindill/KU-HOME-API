<?php

namespace Tests\Feature;

use App\Models\GlobalRate;
use Database\Seeders\GlobalRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🌟 (26/08/26): ตรวจค่า default rate ที่ GlobalRateSeeder seed
 * early_checkin / late_checkout ปรับลดจาก 300 → 100 บาท (integer baht)
 * 📊 (05/10/26, excel-reports ticket 10): breakfast แยก 2 ชุด — breakfast_100 / breakfast_200
 * (code breakfast เดิมถูกแทน — migration ปิดใช้งาน, seeder ไม่สร้างแล้ว)
 */
class GlobalRateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_addon_rates_with_default_prices(): void
    {
        $this->seed(GlobalRateSeeder::class);

        $this->assertSame(100, GlobalRate::getPrice('early_checkin'));
        $this->assertSame(100, GlobalRate::getPrice('late_checkout'));
        $this->assertSame(100, GlobalRate::getPrice('breakfast_100'));
        $this->assertSame(200, GlobalRate::getPrice('breakfast_200'));
        $this->assertSame(500, GlobalRate::getPrice('extra_bed'));
        // code breakfast เดิมไม่ถูก seed อีกต่อไป
        $this->assertSame(0, GlobalRate::getPrice('breakfast'));
    }
}
