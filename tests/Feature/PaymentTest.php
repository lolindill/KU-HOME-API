<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function createBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'status' => 'draft',
            'guest_name' => 'Payment Guest',
            'guest_email' => 'pay@example.com',
            'guest_phone' => '0812345678',
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'total_amount' => 4500,
        ], $overrides));
    }

    // ============================================
    // 🔐 Admin: Request payment
    // ============================================

    public function test_admin_can_request_payment(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking();

        $response = $this->postJson('/api/v1/payments', [
            'booking_id' => $booking->id,
            'amount' => 4500,
            'payment_method' => 'transfer',
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'amount' => 4500,
        ]);
    }

    public function test_non_admin_cannot_request_payment(): void
    {
        $this->actingAsUser();
        $booking = $this->createBooking();

        $response = $this->postJson('/api/v1/payments', [
            'booking_id' => $booking->id,
            'amount' => 4500,
            'payment_method' => 'transfer',
        ]);
        $response->assertStatus(403);
    }

    // ============================================
    // 🔓 Webhook (no auth)
    // ============================================

    public function test_webhook_can_update_payment_status(): void
    {
        $booking = $this->createBooking(['status' => 'draft']);
        $payment = Payment::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'amount' => 4500,
            'payment_method' => 'transfer',
            'status' => 'pending',
            'reference_number' => 'TXN-' . Str::random(10),
        ]);

        $response = $this->postJson('/api/v1/payment/webhook', [
            'payment_id' => $payment->id,
            'status' => 'success',
            'reference_number' => 'VERIFY-123',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('completed', $payment->fresh()->status);
        // Webhook should also transition booking from draft → paid
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    public function test_webhook_returns_404_for_unknown_payment(): void
    {
        $response = $this->postJson('/api/v1/payment/webhook', [
            'payment_id' => Str::uuid(),
            'status' => 'success',
            'reference_number' => 'VERIFY-123',
        ]);
        // Validation fails (exists:payments,id) → 422, not 404
        $response->assertStatus(422);
    }

    // ============================================
    // 🔐 Guest: Request payment for guest booking
    // ============================================

    public function test_guest_can_request_payment_with_matching_email(): void
    {
        // requestPaymentForGuest is a PUBLIC route — identity via email/phone, not auth
        $booking = $this->createBooking(['status' => 'draft', 'guest_email' => 'guest@example.com']);

        $response = $this->postJson("/api/v1/bookings/{$booking->id}/request-payment", [
            'guest_email' => 'guest@example.com',
        ]);

        $response->assertStatus(201);
    }

    public function test_guest_cannot_request_payment_with_wrong_email(): void
    {
        $booking = $this->createBooking(['status' => 'draft', 'guest_email' => 'guest@example.com']);

        $response = $this->postJson("/api/v1/bookings/{$booking->id}/request-payment", [
            'guest_email' => 'wrong@example.com',
        ]);

        $response->assertStatus(403);
    }
}