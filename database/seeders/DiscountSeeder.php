<?php

namespace Database\Seeders;

use App\Models\Discount;
use Illuminate\Database\Seeder;

class DiscountSeeder extends Seeder
{
    /**
     * 🎟️ Seed initial discount code
     */
    public function run(): void
    {
        Discount::updateOrCreate(
            ['code' => 'WELCOME10'],
            [
                'type' => 'percent',
                'value' => 10,
                'room_type_ids' => null,
                'usable_from' => null,
                'usable_until' => null,
                'stay_from' => null,
                'stay_until' => null,
                'max_uses' => null,
                'max_uses_per_user' => null,
                'is_active' => true,
            ]
        );
    }
}
