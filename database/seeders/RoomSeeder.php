<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Str;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 🏨 1. สร้าง Room Types 3 แบบ และเก็บ UUID ไว้เชื่อมกับห้องค่ะ
        $standardId = Str::uuid();
        $deluxeId = Str::uuid();
        $suiteId = Str::uuid();

        RoomType::create([
            'id' => $standardId,
            'name_en' => 'Standard Room',
            'name_th' => 'ห้องมาตรฐาน',
            'max_guests' => 2,
            'extra_bed_enabled' => 'false',
            'max_extra_beds' => 0,
            'extra_bed_price' => 0,
            'rate_daily_general' => 1000,
        ]);

        RoomType::create([
            'id' => $deluxeId,
            'name_en' => 'Deluxe Room',
            'name_th' => 'ห้องดีลักซ์',
            'max_guests' => 2,
            'extra_bed_enabled' =>'true',
            'max_extra_beds' => 1,
            'extra_bed_price' => 500,
            'rate_daily_general' => 1800,
        ]);

        RoomType::create([
            'id' => $suiteId,
            'name_en' => 'Family Suite',
            'name_th' => 'ห้องแฟมิลี่สวีท',
            'max_guests' => 4,
            'extra_bed_enabled' => 'true',
            'max_extra_beds' => 2,
            'extra_bed_price' => 600,
            'rate_daily_general' => 3500,
        ]);

        // 🚪 2. สร้าง Rooms ทั้งหมด 8 ห้อง โดยเชื่อมกับ RoomType ด้านบนค่ะ!

        // ห้อง Standard (3 ห้อง)
        $standardRooms = ['101', '102', '103'];
        foreach ($standardRooms as $roomNumber) {
            Room::create([
                'id' => Str::uuid(),
                'room_type_id' => $standardId,
                'room_number' => $roomNumber,
                'status' => 'available',
            ]);
        }

        // ห้อง Deluxe (3 ห้อง)
        $deluxeRooms = ['201', '202', '203'];
        foreach ($deluxeRooms as $roomNumber) {
            Room::create([
                'id' => Str::uuid(),
                'room_type_id' => $deluxeId,
                'room_number' => $roomNumber,
                'status' => 'available',
            ]);
        }

        // ห้อง Family Suite (2 ห้อง)
        $suiteRooms = ['301', '302'];
        foreach ($suiteRooms as $roomNumber) {
            Room::create([
                'id' => Str::uuid(),
                'room_type_id' => $suiteId,
                'room_number' => $roomNumber,
                'status' => 'available',
            ]);
        }
        
        $this->command->info('เย้! น้องเมดสร้างประเภทห้องพัก 3 แบบ และห้องพัก 8 ห้องให้นายท่านเรียบร้อยแล้วค่ะ! 🎉');
    }
}