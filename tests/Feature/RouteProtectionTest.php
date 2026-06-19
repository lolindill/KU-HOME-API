<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RouteProtectionTest extends TestCase
{
    use RefreshDatabase;

    // ============================================
    // 🔓 Public routes (no auth needed)
    // ============================================

    public function test_public_room_listing_works_without_auth(): void
    {
        $response = $this->getJson('/api/v1/rooms');
        $response->assertStatus(200);
    }

    public function test_public_room_types_works_without_auth(): void
    {
        $response = $this->getJson('/api/v1/room-types');
        $response->assertStatus(200);
    }

    public function test_public_availability_works_without_auth(): void
    {
        $response = $this->getJson('/api/v1/availability');
        $response->assertStatus(200);
    }

    public function test_public_register_works_without_auth(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(201);
    }

    public function test_public_login_works_without_auth(): void
    {
        User::factory()->create(['email' => 'login@example.com', 'password' => bcrypt('password123')]);
        $response = $this->postJson('/api/v1/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(200);
    }

    // ============================================
    // 🔒 Protected routes require auth:sanctum
    // ============================================

    public function test_get_own_bookings_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/bookings');
        $response->assertStatus(401);
    }

    /**
     * 🌟 Refactor (18/06/26): POST /bookings ย้ายจาก public → protected
     * Non-member/guest ไม่สามารถจองได้โดยไม่ login อีกต่อไป
     */
    public function test_create_booking_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/bookings', []);
        $response->assertStatus(401);
    }

    public function test_me_requires_auth(): void
    {
        $response = $this->getJson('/api/v1/me');
        $response->assertStatus(401);
    }

    public function test_profile_requires_auth(): void
    {
        $response = $this->putJson('/api/v1/profile', []);
        $response->assertStatus(401);
    }

    public function test_logout_requires_auth(): void
    {
        $response = $this->postJson('/api/v1/logout');
        $response->assertStatus(401);
    }

    // ============================================
    // 🔐 Admin-only routes reject non-admin
    // ============================================

    public function test_user_list_rejects_non_admin(): void
    {
        $this->actingAsUser();
        $response = $this->getJson('/api/v1/users');
        $response->assertStatus(403);
    }

    public function test_user_create_rejects_non_admin(): void
    {
        $this->actingAsUser();
        $response = $this->postJson('/api/v1/users', [
            'name' => 'Hacker',
            'email' => 'hack@example.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(403);
    }

    public function test_booking_update_status_rejects_non_admin(): void
    {
        $this->actingAsUser();
        $response = $this->putJson('/api/v1/bookings/update/nonexistent', ['status' => 'paid']);
        $response->assertStatus(403);
    }


    public function test_room_status_update_rejects_non_admin(): void
    {
        $this->actingAsUser();
        $response = $this->putJson('/api/v1/rooms/nonexistent/status', ['status' => 'dirty']);
        $response->assertStatus(403);
    }

    public function test_front_desk_walkin_rejects_non_admin(): void
    {
        $this->actingAsUser();
        $response = $this->postJson('/api/v1/front-desk/walk-in', []);
        $response->assertStatus(403);
    }

    public function test_payments_create_rejects_non_admin(): void
    {
        $this->actingAsUser();
        $response = $this->postJson('/api/v1/payments', []);
        $response->assertStatus(403);
    }

    public function test_dashboard_cleaning_tasks_rejects_non_admin(): void
    {
        $this->actingAsUser();
        $response = $this->getJson('/api/v1/dashboard/cleaning-tasks');
        $response->assertStatus(403);
    }

    // ============================================
    // ✅ Admin can access admin routes
    // ============================================

    public function test_admin_can_access_user_list(): void
    {
        $this->actingAsAdmin();
        $response = $this->getJson('/api/v1/users');
        $response->assertStatus(200);
    }

    public function test_admin_can_list_all_bookings(): void
    {
        $this->actingAsAdmin();
        $response = $this->getJson('/api/v1/bookings');
        $response->assertStatus(200);
    }

    public function test_admin_can_access_dashboard(): void
    {
        $this->actingAsAdmin();
        $response = $this->getJson('/api/v1/dashboard/cleaning-tasks');
        $response->assertStatus(200);
    }
}