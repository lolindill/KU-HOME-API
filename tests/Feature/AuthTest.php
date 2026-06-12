<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(201)
            ->assertJsonStructure(['access_token', 'token_type']);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_register_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/register', []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_register_prevents_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_returns_token(): void
    {
        User::factory()->create(['email' => 'login@example.com', 'password' => bcrypt('password123')]);
        $response = $this->postJson('/api/v1/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'token_type']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'wrong@example.com', 'password' => bcrypt('password123')]);
        $response = $this->postJson('/api/v1/login', [
            'email' => 'wrong@example.com',
            'password' => 'wrongpassword',
        ]);
        $response->assertStatus(401);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/logout');
        $response->assertStatus(200);
        // Token should be revoked
        $this->assertEquals(0, $user->fresh()->tokens()->count());
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create(['name' => 'Me Test']);
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/me');
        $response->assertStatus(200)
            ->assertJsonPath('user.name', 'Me Test');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/me');
        $response->assertStatus(401);
    }

    /*
      🛡️ Regression test for #15: Register must NOT allow role escalation.
      Even though StoreUserRequest accepts 'role', AuthController::register()
      must ignore it — always defaulting to 'user'.
     */
    public function test_register_ignores_role_field_prevents_admin_escalation(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Hacker',
            'email' => 'hacker@evil.com',
            'password' => 'password123',
            'role' => 'admin',       // 🛑 พยายามตั้งเป็น admin
            'ver' => true,           // 🛑 พยายาม verify ตัวเอง
            'is_ku_member' => true,  // 🛑 พยายามตั้งสถานะสมาชิก KU
        ]);

        $response->assertStatus(201);

        // ต้องเป็น 'user' เท่านั้น ไม่ใช่ 'admin'
        $this->assertDatabaseHas('users', [
            'email' => 'hacker@evil.com',
            'role' => 'user',
        ]);

        // ver ต้องเป็น default (false) ไม่ใช่ true
        $user = User::where('email', 'hacker@evil.com')->first();
        $this->assertFalse($user->ver);
    }
}
