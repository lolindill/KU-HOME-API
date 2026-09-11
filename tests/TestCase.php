<?php

namespace Tests;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * 🧾 (03/09/26) Invariant การเงิน: Σ booking_rooms.amount == bookings.total_amount
     *    (ทั้งสองฝั่ง net, integer baht) — บังคับด้วย test เท่านั้น ไม่มี runtime guard/observer
     *    เรียกหลังทุก mutation ที่ไหลผ่าน DiscountService::reprice()
     */
    protected function assertAmountInvariant(Booking $booking): void
    {
        $sum = (int) DB::table('booking_rooms')
            ->where('booking_id', $booking->id)
            ->sum('amount');
        $total = (int) DB::table('bookings')->where('id', $booking->id)->value('total_amount');

        $this->assertSame(
            $total,
            $sum,
            "Invariant แตกค่ะ: Σ booking_rooms.amount ({$sum}) ≠ bookings.total_amount ({$total}) สำหรับ booking {$booking->id}"
        );
    }

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

    // 🧹 Phase A (15/07/26): housekeeping role helpers
    protected function actingAsHousekeeping(): static
    {
        $hk = User::factory()->create(['role' => 'housekeeping']);

        return $this->actingAs($hk, 'sanctum');
    }

    protected function createAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    protected function createUser(): User
    {
        return User::factory()->create(['role' => 'user']);
    }

    protected function createHousekeeping(): User
    {
        return User::factory()->create(['role' => 'housekeeping']);
    }
}
