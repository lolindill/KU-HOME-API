<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    // ❄️ (2026-11-11, api-route-hygiene): ลบ requestPayment + route POST /payments —
    //    410 tombstone (FROZEN 2026-11-03, Mock QR deprecated) ไม่มีผู้เรียกที่ถูกต้อง
    //    · 🌟 Refactor (18/06/26): requestPaymentForGuest() ถูกลบก่อนหน้านี้แล้ว

    // ❄️ FROZEN (24/07/26): ย้าย flow ยืนยันการชำระไป POST /bookings/{id}/confirm + admin verify แล้ว
    //    (payments/receipts tables freeze — read-only legacy)
    //    webhook เดิมไม่มี HMAC signature + เป็น mock → ปิดไปเลยเพื่อกันการใช้งานสับสน
    public function webhook(Request $request)
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Webhook deprecated — ใช้ POST /api/v1/bookings/{id}/confirm แทนค่ะนายท่าน (รองรับ admin verify ผ่าน booking_confirmations table)',
        ], 410);
    }
}
