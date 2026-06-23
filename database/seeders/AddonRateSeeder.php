<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\AddonRate;

class AddonRateSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            [
                'code' => 'breakfast',
                'name_en' => 'Breakfast',
                'name_th' => 'อาหารเช้า',
                'default_price' => 20000, // 200 THB (satang)
                'is_active' => 'true',
            ],
            [
                'code' => 'early_checkin',
                'name_en' => 'Early Check-in',
                'name_th' => 'เช็คอินก่อนเวลา',
                'default_price' => 30000, // 300 THB (satang)
                'is_active' => 'true',
            ],
            [
                'code' => 'late_checkout',
                'name_en' => 'Late Check-out',
                'name_th' => 'เช็คเอาท์ล่าช้า',
                'default_price' => 30000, // 300 THB (satang)
                'is_active' => 'true',
            ],
            [
                'code' => 'extra_bed',
                'name_en' => 'Extra Bed',
                'name_th' => 'เตียงเสริม',
                'default_price' => 50000, // 500 THB (satang)
                'is_active' => 'true',
            ],
        ];

        foreach ($rates as $rate) {
            AddonRate::updateOrCreate(
                ['code' => $rate['code']],
                $rate
            );
        }
    }
}