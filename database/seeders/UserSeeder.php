<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 🌟 Default users สำหรับทุก role — ใช้สำหรับ dev/testing
 *
 * Pattern:
 *   email    = {role}@kuhome.com
 *   password = password123  (เดียวกันหมด — จำง่ายสำหรับ local/dev)
 *
 * Roles: user, guest, ku_member, staff, admin, housekeeping, system
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        // ✅ Default accounts — one per role
        // 🔒 NOTE: password ทุกคนคือ "password123"
        $defaults = [
            [
                'name' => 'Default User',
                'email' => 'user@kuhome.com',
                'role' => 'user',
                'is_ku_member' => false,
            ],
            [
                'name' => 'Default Guest',
                'email' => 'guest@kuhome.com',
                'role' => 'guest',
                'is_ku_member' => false,
            ],
            [
                'name' => 'Default KU Member',
                'email' => 'kumember@kuhome.com',
                'role' => 'ku_member',
                'is_ku_member' => true, // 🌟 KU member flag = true ตามบริบท role
            ],
            [
                'name' => 'Default Staff',
                'email' => 'staff@kuhome.com',
                'role' => 'staff',
                'is_ku_member' => false,
            ],
            [
                'name' => 'Super Admin',
                'email' => 'admin@kuhome.com',
                'role' => 'admin',
                'is_ku_member' => false,
            ],
            // 🧹 Phase A (15/07/26): housekeeping user — แม่บ้าน accept งานผ่าน dashboard
            [
                'name' => 'Nong Maid',
                'email' => 'housekeeping@kuhome.com',
                'role' => 'housekeeping',
                'is_ku_member' => false,
            ],
            [
                'name' => 'System Account',
                'email' => 'system@kuhome.com',
                'role' => 'system',
                'is_ku_member' => false,
            ],
        ];

        foreach ($defaults as $data) {
            User::create(array_merge($data, [
                'password' => 'password123',
            ]));
        }
    }
}
