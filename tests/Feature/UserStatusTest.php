<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 🔐 User status (active | inactive) — แทน legacy `ver` (2026-11-27)
 *
 *    inactive = login ไม่ได้ทุกช่องทาง (403) + token เดิมถูกลบตอนปิด + Sanctum callback
 *    ปฏิเสธ token ของ user inactive ที่ยังค้าง (401) · เปลี่ยนสถานะได้ทาง PATCH /users/{id}/status เท่านั้น
 *
 *    ⚠️ test ที่ตรวจพฤติกรรม token ต้องใช้ Bearer token จริง — actingAs() ข้าม Sanctum guard
 */
class UserStatusTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('ku_home_auth_token')->plainTextToken];
    }

    public function test_schema_has_status_and_no_legacy_ver_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'status'));
        $this->assertFalse(Schema::hasColumn('users', 'ver'));
    }

    public function test_new_user_defaults_to_active(): void
    {
        $user = User::factory()->create();

        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    public function test_admin_can_deactivate_user_and_all_tokens_are_revoked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);
        $target->createToken('device-a');
        $target->createToken('device-b');

        $this->withHeaders($this->bearer($admin))
            ->patchJson("/api/v1/users/{$target->id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.status', 'inactive');

        $this->assertSame('inactive', $target->fresh()->status);
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_admin_can_reactivate_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['status' => User::STATUS_INACTIVE]);

        $this->withHeaders($this->bearer($admin))
            ->patchJson("/api/v1/users/{$target->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('user.status', 'active');

        $this->assertTrue($target->fresh()->isActive());
    }

    public function test_status_must_be_active_or_inactive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();

        $this->withHeaders($this->bearer($admin))
            ->patchJson("/api/v1/users/{$target->id}/status", ['status' => 'banned'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->withHeaders($this->bearer($admin))
            ->patchJson("/api/v1/users/{$target->id}/status", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $headers = $this->bearer($admin);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$admin->id}/status", ['status' => 'inactive'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertTrue($admin->fresh()->isActive());
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_non_admin_cannot_change_status(): void
    {
        foreach (['user', 'staff', 'housekeeping', 'ku_member'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $target = User::factory()->create();

            $this->withHeaders($this->bearer($actor))
                ->patchJson("/api/v1/users/{$target->id}/status", ['status' => 'inactive'])
                ->assertStatus(403);

            $this->assertTrue($target->fresh()->isActive(), "role {$role} ต้องปิดบัญชีคนอื่นไม่ได้");
        }
    }

    public function test_legacy_verify_route_is_gone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create();

        $this->withHeaders($this->bearer($admin))
            ->putJson("/api/v1/users/{$target->id}/verify", ['ver' => true])
            ->assertStatus(404);
    }

    public function test_inactive_user_cannot_login_with_password(): void
    {
        $user = User::factory()->create([
            'email' => 'closed@kuhome.com',
            'password' => 'password123',
            'status' => User::STATUS_INACTIVE,
        ]);

        $this->postJson('/api/v1/login', ['email' => 'closed@kuhome.com', 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJsonPath('status', 'error')
            ->assertJsonMissingPath('access_token');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_inactive_user_with_wrong_password_gets_401_not_403(): void
    {
        User::factory()->create([
            'email' => 'closed@kuhome.com',
            'password' => 'password123',
            'status' => User::STATUS_INACTIVE,
        ]);

        // ไม่บอกสถานะบัญชีกับคนที่ใส่รหัสผิด
        $this->postJson('/api/v1/login', ['email' => 'closed@kuhome.com', 'password' => 'wrong-pass'])
            ->assertStatus(401);
    }

    public function test_leftover_token_of_inactive_user_is_rejected(): void
    {
        $user = User::factory()->create();
        $headers = $this->bearer($user);

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertOk();

        // ปิดบัญชีทางอื่น (เช่น tinker/SQL) — token ไม่ถูกลบ แต่ Sanctum callback ต้องปฏิเสธ
        User::whereKey($user->id)->update(['status' => User::STATUS_INACTIVE]);
        $this->app['auth']->forgetGuards();

        $this->withHeaders($headers)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_status_cannot_be_set_via_profile_or_admin_user_crud(): void
    {
        $user = User::factory()->create();

        // ผู้ใช้แก้โปรไฟล์ตัวเอง — status ถูกเมิน
        $this->withHeaders($this->bearer($user))
            ->putJson('/api/v1/profile', ['name' => 'New Name', 'status' => 'inactive'])
            ->assertOk();
        $this->assertTrue($user->fresh()->isActive());

        $this->app['auth']->forgetGuards();
        $admin = User::factory()->create(['role' => 'admin']);
        $headers = $this->bearer($admin);

        // admin PUT /users/{id} — status ถูกเมิน (ต้องใช้ PATCH .../status ที่ revoke token)
        $this->withHeaders($headers)
            ->putJson("/api/v1/users/{$user->id}", ['status' => 'inactive'])
            ->assertOk();
        $this->assertTrue($user->fresh()->isActive());

        // admin POST /users — สร้างเป็น active เสมอ
        $this->withHeaders($headers)
            ->postJson('/api/v1/users', [
                'name' => 'Created',
                'email' => 'created@kuhome.com',
                'password' => 'password123',
                'status' => 'inactive',
            ])
            ->assertCreated()
            ->assertJsonPath('user.status', 'active');
    }

    public function test_admin_can_filter_users_by_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(2)->create(['status' => User::STATUS_INACTIVE]);

        $this->withHeaders($this->bearer($admin))
            ->getJson('/api/v1/users?status=inactive')
            ->assertOk()
            ->assertJsonCount(2, 'users.data')
            ->assertJsonPath('users.data.0.status', 'inactive');
    }
}
