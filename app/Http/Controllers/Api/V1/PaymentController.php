<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    // ❄️ FROZEN (2026-11-03): Mock QR deprecated — webhook เป็น 410 แล้ว
    //    ใช้ POST /api/v1/bookings/{id}/confirm หรือ front-desk recordPayment แทน
    public function requestPayment(Request $request)
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Mock QR payment request deprecated (410 Gone) — ใช้ POST /api/v1/bookings/{id}/confirm หรือ front-desk recordPayment แทนค่ะนายท่าน',
        ], 410);
    }

    // 🌟 Refactor (18/06/26): ลบ requestPaymentForGuest() ออก — non member/guest ใช้งานไม่ได้แล้ว ต้อง login ทุกกรณี

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
