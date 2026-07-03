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

    /**
     * 🌟 Refactor (25/06/26): bookings ไม่มี check_in/check_out แล้ว — ย้ายไป BR-level
     */
    private function createBooking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        return Booking::create(array_merge([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
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

    public function test_webhook_returns_422_for_unknown_payment(): void
    {
        $response = $this->postJson('/api/v1/payment/webhook', [
            'payment_id' => Str::uuid(),
            'status' => 'success',
            'reference_number' => 'VERIFY-123',
        ]);
        // Validation fails (exists:payments,id) → 422
        $response->assertStatus(422);
    }

    // ============================================
    // 🌟 Fix H5 (03/07/26): Payment = 1 per booking (QR scenario)
    // ============================================

    public function test_request_payment_rejects_when_pending_exists(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking();

        // สร้าง pending payment แรกไปแล้ว
        Payment::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'amount' => 4500,
            'payment_method' => 'transfer',
            'status' => 'pending',
        ]);

        // ยิง request อีกครั้ง → ต้อง reject (กัน orphan pending payments)
        $response = $this->postJson('/api/v1/payments', [
            'booking_id' => $booking->id,
            'amount' => 4500,
            'payment_method' => 'transfer',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('รอดำเนินการ', $response->json('message'));
        // ต้องมี payment เดียว (ไม่สร้างซ้ำ)
        $this->assertEquals(1, Payment::where('booking_id', $booking->id)->count());
    }

    // ============================================
    // 🌟 Fix M2 (03/07/26): Idempotency — webhook duplicate ต้องไม่สร้าง receipt ซ้ำ
    // ============================================

    public function test_receipt_idempotent_on_duplicate_webhook(): void
    {
        $booking = $this->createBooking(['status' => 'draft']);
        $payment = Payment::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'amount' => 4500,
            'payment_method' => 'transfer',
            'status' => 'pending',
        ]);

        // ยิง webhook ครั้งที่ 1
        $this->postJson('/api/v1/payment/webhook', [
            'payment_id' => $payment->id,
            'status' => 'success',
        ])->assertStatus(200);

        // ยิง webhook ซ้ำ (duplicate) อีกครั้ง
        $this->postJson('/api/v1/payment/webhook', [
            'payment_id' => $payment->id,
            'status' => 'success',
        ])->assertStatus(200);

        // ต้องมี receipt แค่ 1 ใบเท่านั้น (idempotent)
        $receiptCount = \App\Models\Receipt::where('payment_id', $payment->id)->count();
        $this->assertEquals(1, $receiptCount, 'Duplicate webhook must not create a second receipt');
    }

    // ============================================
    // 🌟 Fix M3 (03/07/26): webhook กับ booking ที่จ่ายแล้ว ต้องไม่ throw 500
    // ============================================

    public function test_webhook_does_not_crash_when_booking_already_paid(): void
    {
        $booking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);
        $payment = Payment::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'amount' => 4500,
            'payment_method' => 'transfer',
            'status' => 'pending',
        ]);

        // webhook ยิงมาอีกทีทั้งที่ booking เป็น paid แล้ว → ต้องไม่ throw
        $response = $this->postJson('/api/v1/payment/webhook', [
            'payment_id' => $payment->id,
            'status' => 'success',
        ]);

        $response->assertStatus(200);
        // booking ยังคงเป็น paid (ไม่พัง)
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    // 🌟 Refactor (18/06/26): requestPaymentForGuest route ถูกลบแล้ว — non-member ไม่สามารถจอง/ชำระได้โดยตรง
    // ทุกคนต้อง login และใช้ POST /payments (admin) หรือ POST /front-desk/{id}/payment
}