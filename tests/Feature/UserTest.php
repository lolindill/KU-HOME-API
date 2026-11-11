<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    // ============================================
    // ✅ Admin CRUD
    // ============================================

    public function test_admin_can_list_users(): void
    {
        User::factory()->count(3)->create();
        $this->actingAsAdmin();
        $response = $this->getJson('/api/v1/users');
        $response->assertStatus(200);
    }

    public function test_admin_can_create_user(): void
    {
        $this->actingAsAdmin();
        $response = $this->postJson('/api/v1/users', [
            'name' => 'Created User',
            'email' => 'created@example.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'created@example.com']);
    }

    public function test_admin_can_update_user(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create(['name' => 'Old Name']);
        $response = $this->putJson("/api/v1/users/{$user->id}", [
            'name' => 'New Name',
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }

    public function test_admin_can_delete_user(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $response = $this->deleteJson("/api/v1/users/{$user->id}");
        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    // ============================================
    // ✅ Role assignment (staff / housekeeping / ku_member)
    // ============================================

    public function test_admin_can_assign_extended_roles(): void
    {
        $this->actingAsAdmin();

        foreach (['staff', 'housekeeping', 'ku_member'] as $role) {
            $user = User::factory()->create(['role' => 'user']);
            $response = $this->putJson("/api/v1/users/{$user->id}", [
                'role' => $role,
            ]);
            $response->assertStatus(200);
            $this->assertDatabaseHas('users', ['id' => (string) $user->id, 'role' => $role]);
        }
    }

    public function test_admin_cannot_assign_roles_outside_allowlist(): void
    {
        $this->actingAsAdmin();

        foreach (['system', 'guest', 'superadmin'] as $role) {
            $user = User::factory()->create(['role' => 'user']);
            $response = $this->putJson("/api/v1/users/{$user->id}", [
                'role' => $role,
            ]);
            $response->assertStatus(422);
            $this->assertDatabaseHas('users', ['id' => (string) $user->id, 'role' => 'user']);
        }
    }

    // ============================================
    // ✅ Profile update (authenticated user)
    // ============================================

    public function test_user_can_update_own_profile(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        $this->actingAs($user, 'sanctum');
        $response = $this->putJson('/api/v1/profile', [
            'name' => 'Updated Name',
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Name']);
    }

    public function test_profile_update_accepts_own_unchanged_email(): void
    {
        // 🎫 ตรวจสอบว่า user (password) อัปเดตโปรไฟล์โดยส่ง email เดิมของตัวเองแล้วต้องไม่ติด validation unique
        $user = User::factory()->create([
            'name' => 'เดิม',
            'email' => 'self@example.com',
        ]);
        $this->actingAs($user, 'sanctum');

        $response = $this->putJson('/api/v1/profile', [
            'name' => 'ใหม่',
            'email' => $user->email,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'ใหม่',
            'email' => $user->email,
        ]);
    }

    public function test_sso_user_cannot_change_email_via_profile(): void
    {
        // 🔒 (11/11/26) SSO user ห้ามแก้ email ผ่าน PUT /profile — email เป็น identity anchor
        //    ที่ SSO service ใช้ค้นหา account ตอน login (แก้แล้ว login ถัดไปจะสร้าง user ใหม่ซ้ำ)
        //    ทดสอบทั้ง ku_sso และ google รวมถึงเคสส่ง email เดิมของตัวเอง (ก็ยังล็อก)
        $twin = User::factory()->create([
            'email' => 'sso@ku.th', // password twin email เดียวกับ SSO user (ต่าง provider จึง unique ผ่าน)
            'auth_provider' => 'password',
        ]);

        foreach (['ku_sso', 'google'] as $provider) {
            $ssoUser = User::factory()->create([
                'email' => 'sso@ku.th',
                'auth_provider' => $provider,
            ]);

            $response = $this->actingAs($ssoUser, 'sanctum')
                ->putJson('/api/v1/profile', [
                    'name' => 'SSO Updated',
                    'email' => 'sso@ku.th', // email เดิมของตัวเองก็ยังห้าม
                ]);

            $response->assertStatus(422);
            $this->assertDatabaseHas('users', [
                'id' => $ssoUser->id,
                'email' => 'sso@ku.th',
                'auth_provider' => $provider,
            ]);
            // ทั้ง request fail → name ต้องไม่เปลี่ยนตาม
            $this->assertNotEquals('SSO Updated', $ssoUser->fresh()->name);
        }
    }

    public function test_sso_user_can_update_other_profile_fields(): void
    {
        // 🔒 SSO user แก้อย่างอื่นได้ปกติ (name/title/phone/nationality) — ล็อกเฉพาะ email
        $ssoUser = User::factory()->create([
            'auth_provider' => 'ku_sso',
            'name' => 'KU User',
        ]);

        $response = $this->actingAs($ssoUser, 'sanctum')
            ->putJson('/api/v1/profile', [
                'name' => 'Renamed KU User',
                'title' => 'นิสิต',
                'phone' => '0812345678',
                'nationality' => 'Thai',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $ssoUser->id,
            'name' => 'Renamed KU User',
            'title' => 'นิสิต',
            'phone' => '0812345678',
            'email' => $ssoUser->email,
        ]);
    }

    public function test_password_user_can_change_email_via_profile(): void
    {
        // 🔓 password user เปลี่ยน email เองได้ (unique scope ต่อ provider ตาม ticket 09)
        $user = User::factory()->create([
            'auth_provider' => 'password',
            'email' => 'old@example.com',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', [
                'email' => 'new@example.com',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'new@example.com',
        ]);
    }

    public function test_admin_can_change_sso_user_email_via_users_endpoint(): void
    {
        // 🔓 admin PUT /users/{id} ไม่โดน SSO email lock (ล็อกเฉพาะ /profile)
        $this->actingAsAdmin();

        $ssoUser = User::factory()->create([
            'auth_provider' => 'ku_sso',
            'email' => 'sso@ku.th',
        ]);

        $response = $this->putJson("/api/v1/users/{$ssoUser->id}", [
            'email' => 'fixed@ku.th',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $ssoUser->id,
            'email' => 'fixed@ku.th',
        ]);
    }

    // ============================================
    // 🔴 ROLE ESCALATION VULNERABILITY TEST
    // ============================================

    public function test_user_cannot_escalate_role_via_profile_update(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user, 'sanctum');

        $response = $this->putJson('/api/v1/profile', [
            'name' => 'Hacker',
            'role' => 'admin',
        ]);

        // This test documents the CURRENT VULNERABLE behavior:
        // A regular user can set their own role to 'admin' via PUT /profile
        // After the fix, the role should be ignored/removed
        $freshUser = $user->fresh();

        if ($freshUser->role === 'admin') {
            // VULNERABILITY CONFIRMED: user escalated to admin
            $this->fail('🔴 ROLE ESCALATION VULNERABILITY: User was able to set role to admin via PUT /profile');
        }

        // After fix: role should remain 'user'
        $response->assertStatus(200);
        $this->assertEquals('user', $freshUser->role);
    }
}
