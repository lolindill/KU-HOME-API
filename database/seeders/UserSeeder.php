<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Super Admin',
            'email' => 'admin@kuhome.com',
            'password' => 'password123',
            'role' => 'admin',
        ]);

        // 🧹 Phase A (15/07/26): เพิ่ม housekeeping user — แม่บ้าน accept งานผ่าน dashboard
        User::create([
            'name' => 'Nong Maid',
            'email' => 'maid@kuhome.com',
            'password' => 'password123',
            'role' => 'housekeeping',
        ]);
    }
}
