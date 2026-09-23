<?php

namespace Database\Seeders;

use App\Models\GlobalRate;
use Illuminate\Database\Seeder;

/**
 * 🌟 Refactor (22/07/26): GlobalRateSeeder (renamed from AddonRateSeeder)
 *
 * Seed เฉพาะ addon rate rows เท่านั้น (rate_type = 'addon')
 * ส่วน room rate rows จะถูก seed อัตโนมัติโดย migration 2026_07_22_100100
 * (อ่านค่าจาก room_types.rate_daily_general ในขณะนั้น)
 */
class GlobalRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            [
                'rate_type' => 'addon',
                'code' => 'breakfast',
                'name_en' => 'Breakfast',
                'name_th' => 'อาหารเช้า',
                'default_price' => 200, // 200 บาท (integer baht)
                'is_active' => true,
            ],
            [
                'rate_type' => 'addon',
                'code' => 'early_checkin',
                'name_en' => 'Early Check-in',
                'name_th' => 'เช็คอินก่อนเวลา',
                'default_price' => 100, // 100 บาท (integer baht) — 🌟 ปรับลดจาก 300 บาท (26/08/26)
                'is_active' => true,
            ],
            [
                'rate_type' => 'addon',
                'code' => 'late_checkout',
                'name_en' => 'Late Check-out',
                'name_th' => 'เช็คเอาท์ล่าช้า',
                'default_price' => 100, // 100 บาท (integer baht) — 🌟 ปรับลดจาก 300 บาท (26/08/26)
                'is_active' => true,
            ],
            [
                'rate_type' => 'addon',
                'code' => 'extra_bed',
                'name_en' => 'Extra Bed',
                'name_th' => 'เตียงเสริม',
                'default_price' => 500, // 500 บาท (integer baht)
                'is_active' => true,
            ],
        ];

        foreach ($rates as $rate) {
            GlobalRate::updateOrCreate(
                ['code' => $rate['code']],
                $rate
            );
        }
    }
}
