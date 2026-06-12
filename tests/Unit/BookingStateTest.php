<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BookingStateTest extends TestCase
{
    use RefreshDatabase;

    private function createBooking(string $status = 'draft'): Booking
    {
        return Booking::create([
            'status' => $status,
            'guest_name' => 'Test Guest',
            'guest_email' => 'test@example.com',
            'guest_phone' => '0812345678',
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'total_amount' => 1000,
        ]);
    }

    // ============================================
    // ✅ Valid Transitions
    // ============================================

    public function test_draft_to_paid_by_user(): void
    {
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('paid', 'user');
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    public function test_draft_to_paid_by_guest(): void
    {
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('paid', 'guest');
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    public function test_draft_to_paid_by_admin(): void
    {
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('paid', 'admin');
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    public function test_draft_to_paid_by_system(): void
    {
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('paid', 'system');
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    public function test_draft_to_checked_in_by_admin(): void
    {
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('checked_in', 'admin');
        $this->assertEquals('checked_in', $booking->fresh()->status);
    }

    public function test_draft_to_deleted_by_user(): void
    {
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('deleted', 'user');
        $this->assertEquals('deleted', $booking->fresh()->status);
    }

    public function test_paid_to_confirmed_by_admin(): void
    {
        $booking = $this->createBooking('paid');
        $booking->transitionStatus('confirmed', 'admin');
        $this->assertEquals('confirmed', $booking->fresh()->status);
    }

    public function test_paid_to_cancelled_by_admin(): void
    {
        $booking = $this->createBooking('paid');
        $booking->transitionStatus('cancelled', 'admin');
        $this->assertEquals('cancelled', $booking->fresh()->status);
    }

    public function test_confirmed_to_checked_in_by_admin(): void
    {
        $booking = $this->createBooking('confirmed');
        $booking->transitionStatus('checked_in', 'admin');
        $this->assertEquals('checked_in', $booking->fresh()->status);
    }

    public function test_confirmed_to_cancelled_by_admin(): void
    {
        $booking = $this->createBooking('confirmed');
        $booking->transitionStatus('cancelled', 'admin');
        $this->assertEquals('cancelled', $booking->fresh()->status);
    }

    public function test_confirmed_to_no_show_by_admin(): void
    {
        $booking = $this->createBooking('confirmed');
        $booking->transitionStatus('no_show', 'admin');
        $this->assertEquals('no_show', $booking->fresh()->status);
    }

    public function test_checked_in_to_checked_out_by_admin(): void
    {
        $booking = $this->createBooking('checked_in');
        $booking->transitionStatus('checked_out', 'admin');
        $this->assertEquals('checked_out', $booking->fresh()->status);
    }

    // ============================================
    // ❌ Invalid Transitions (wrong flow)
    // ============================================

    public function test_cannot_go_from_draft_to_confirmed(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('confirmed', 'admin');
    }

    public function test_cannot_go_from_draft_to_checked_out(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('checked_out', 'admin');
    }

    public function test_cannot_go_from_paid_to_checked_in(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('paid');
        $booking->transitionStatus('checked_in', 'admin');
    }

    public function test_cannot_go_from_checked_out_to_anything(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('checked_out');
        $booking->transitionStatus('draft', 'admin');
    }

    public function test_cannot_go_from_cancelled_to_anything(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('cancelled');
        $booking->transitionStatus('draft', 'admin');
    }

    // ============================================
    // 🔒 Role Restrictions
    // ============================================

    public function test_draft_to_checked_in_rejected_for_user(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ไม่มีสิทธิ์');
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('checked_in', 'user');
    }

    public function test_draft_to_checked_in_rejected_for_system(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('checked_in', 'system');
    }

    public function test_paid_to_confirmed_rejected_for_user(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('paid');
        $booking->transitionStatus('confirmed', 'user');
    }

    public function test_paid_to_cancelled_rejected_for_user(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('paid');
        $booking->transitionStatus('cancelled', 'user');
    }

    public function test_confirmed_to_checked_in_rejected_for_user(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('confirmed');
        $booking->transitionStatus('checked_in', 'user');
    }

    public function test_checked_in_to_checked_out_rejected_for_user(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('checked_in');
        $booking->transitionStatus('checked_out', 'user');
    }
}