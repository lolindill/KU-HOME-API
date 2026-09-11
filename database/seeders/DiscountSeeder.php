<?php

namespace Database\Seeders;

use App\Models\Discount;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

class DiscountSeeder extends Seeder
{
    /**
     * 🎟️ Seed comprehensive discount codes covering all types & patterns
     */
    public function run(): void
    {
        // ค้นหา ID ห้อง Deluxe (ถ้ามีการรัน RoomSeeder มาก่อน)
        $deluxe = RoomType::where('name_en', 'Deluxe')->first();

        // 1. Percent: ส่วนลด 10% จากค่าห้องพักหลัก
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

        // 2. Fixed: ลดคงที่ 200 บาท (integer baht) ต่อห้อง
        Discount::updateOrCreate(
            ['code' => 'SAVE200'],
            [
                'type' => 'fixed',
                'value' => 200,
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

        // 3. Set Room Price (Room Type Targeted): เหมาจ่ายห้อง Deluxe คืนละ 990 บาท (integer baht จากปกติ 1,200 บาท)
        Discount::updateOrCreate(
            ['code' => 'DELUXE990'],
            [
                'type' => 'set_room_price',
                'value' => 990,
                'room_type_ids' => $deluxe ? [$deluxe->id] : null,
                'usable_from' => null,
                'usable_until' => null,
                'stay_from' => null,
                'stay_until' => null,
                'max_uses' => null,
                'max_uses_per_user' => null,
                'is_active' => true,
            ]
        );

        // 4. Quota Limited: ลดคงที่ 100 บาท (integer baht) จำกัด 50 สิทธิ์แรก (1 สิทธิ์ต่อคน)
        Discount::updateOrCreate(
            ['code' => 'LIMITED50'],
            [
                'type' => 'fixed',
                'value' => 100,
                'room_type_ids' => null,
                'usable_from' => null,
                'usable_until' => null,
                'stay_from' => null,
                'stay_until' => null,
                'max_uses' => 50,
                'max_uses_per_user' => 1,
                'is_active' => true,
            ]
        );
    }
}
