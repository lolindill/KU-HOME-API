<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): static
    {
        $admin = User::factory()->create(['role' => 'admin']);
        return $this->actingAs($admin, 'sanctum');
    }

    protected function actingAsUser(): static
    {
        $user = User::factory()->create(['role' => 'user']);
        return $this->actingAs($user, 'sanctum');
    }

    protected function actingAsGuestRole(): static
    {
        $guest = User::factory()->create(['role' => 'guest']);
        return $this->actingAs($guest, 'sanctum');
    }

    protected function createAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    protected function createUser(): User
    {
        return User::factory()->create(['role' => 'user']);
    }
}