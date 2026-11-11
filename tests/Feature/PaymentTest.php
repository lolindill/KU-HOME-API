<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaymentTest extends TestCase
{
    // ============================================
    // 🧹 (2026-11-11, api-route-hygiene): POST /payments ถูกลบพร้อม
    //    PaymentController::requestPayment — เดิมเป็น 410 tombstone (FROZEN 2026-11-03)
    //    ที่คอมเมนต์ route "สร้างรายการชำระ" หลอกผู้อ่าน
    //    flow ชำระเงินจริง = POST /bookings/{id}/confirm + POST /front-desk/{bookingId}/payment
    // ============================================

    public function test_payments_route_removed(): void
    {
        $this->actingAsAdmin();

        // 🧾 pin สัญญาใหม่: route ไม่มีแล้ว → 404 (เดิม tombstone 410 ถูกถอดตามมติ scrutinize)
        $this->postJson('/api/v1/payments', [
            'booking_id' => '00000000-0000-0000-0000-000000000000',
            'amount' => 100,
        ])->assertStatus(404);
    }

    // ============================================
    // ❄️ FROZEN (24/07/26): webhook deprecated
    // ============================================
    // webhook flow ทั้งหมด (draft→paid + receipt creation) ย้ายไป
    // POST /bookings/{id}/confirm + admin verify ผ่าน booking_confirmations table แล้ว
    // — test ยืนยันว่า webhook returns 410 GONE อยู่ใน BookingConfirmationTest (คง route 410
    //   ไว้เป็น contract รอ gateway — มติ scrutinize 2026-11-11)
    // — tests เดิม (webhook_can_update / 422_unknown / receipt_idempotent / does_not_crash_when_paid)
    //   ถูกลบออกเพราะ flow deprecated แล้ว

    // 🌟 Refactor (18/06/26): requestPaymentForGuest route ถูกลบแล้ว — non-member ไม่สามารถจอง/ชำระได้โดยตรง
    // ทุกคนต้อง login และใช้ POST /bookings/{id}/confirm หรือ POST /front-desk/{id}/payment
}
