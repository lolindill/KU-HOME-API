<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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