<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ⏱️ (2026-09-24, SRS v2 REQ-001.4): Sanctum token อายุ 8 ชั่วโมง
 *    config/sanctum.php `expiration` = 480 นาที — เกินกำหนดแล้ว request ด้วย token เดิม
 *    ต้องโดน 401 (บังคับล็อกเอาต์อัตโนมัติ)
 *    หมายเหตุ: เป็น hard expiry นับจาก created_at ของ token ไม่ใช่ sliding inactivity
 */
class SanctumTokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: string} [user, plainTextToken]
     */
    private function loginUser(): array
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(200);

        return [$user, $response->json('access_token')];
    }

    public function test_fresh_token_can_access_protected_route(): void
    {
        [, $token] = $this->loginUser();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(200);
    }

    public function test_token_still_valid_within_8_hours(): void
    {
        [, $token] = $this->loginUser();

        $this->travel(479)->minutes();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(200);
    }

    public function test_token_expired_after_8_hours_returns_401(): void
    {
        [, $token] = $this->loginUser();

        $this->travel(481)->minutes();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(401);
    }
}
