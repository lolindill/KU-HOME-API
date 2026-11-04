<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

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
            ],
            [
                'name' => 'Default Guest',
                'email' => 'guest@kuhome.com',
                'role' => 'guest',
            ],
            [
                'name' => 'Default KU Member',
                'email' => 'kumember@kuhome.com',
                'role' => 'ku_member', // 🌟 สถานะสมาชิก = role (column is_ku_member ถูก drop แล้ว 2026-09-08)
            ],
            [
                'name' => 'Default Staff',
                'email' => 'staff@kuhome.com',
                'role' => 'staff',
            ],
            [
                'name' => 'Super Admin',
                'email' => 'admin@kuhome.com',
                'role' => 'admin',
            ],
            // 🧹 Phase A (15/07/26): housekeeping user — แม่บ้าน accept งานผ่าน dashboard
            [
                'name' => 'Nong Maid',
                'email' => 'housekeeping@kuhome.com',
                'role' => 'housekeeping',
            ],
            [
                'name' => 'System Account',
                'email' => 'system@kuhome.com',
                'role' => 'system',
            ],
        ];

        foreach ($defaults as $data) {
            User::create(array_merge($data, [
                'password' => 'password123',
            ]));
        }
    }
}
