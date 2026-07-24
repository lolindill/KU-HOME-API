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
    // ❄️ FROZEN (24/07/26): webhook deprecated
    // ============================================
    // webhook flow ทั้งหมด (draft→paid + receipt creation) ย้ายไป
    // POST /bookings/{id}/confirm + admin verify ผ่าน booking_confirmations table แล้ว
    // — test ยืนยันว่า webhook returns 410 GONE อยู่ใน BookingConfirmationTest
    // — tests เดิม (webhook_can_update / 422_unknown / receipt_idempotent / does_not_crash_when_paid)
    //   ถูกลบออกเพราะ flow deprecated แล้ว

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

    // 🌟 Refactor (18/06/26): requestPaymentForGuest route ถูกลบแล้ว — non-member ไม่สามารถจอง/ชำระได้โดยตรง
    // ทุกคนต้อง login และใช้ POST /payments (admin) หรือ POST /front-desk/{id}/payment
}