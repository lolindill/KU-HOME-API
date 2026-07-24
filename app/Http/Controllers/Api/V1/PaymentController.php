<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    // 🌸 สร้างรายการชำระเงิน (Request Payment)
    public function requestPayment(StorePaymentRequest $request)
    {
        $validated = $request->validated();

        $booking = Booking::findOrFail($validated['booking_id']);

        if ($booking->is_paid) {
            return response()->json([
                'status' => 'error',
                'message' => 'This booking is already paid.'
            ], 400);
        }

        // 🌟 Fix H5 (03/07/26): Payment = 1 per booking (QR scenario)
        // ถ้ามี payment pending อยู่แล้ว → reject ไม่สร้างซ้ำ (กัน orphan pending payments)
        $existingPending = Payment::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->exists();
        if ($existingPending) {
            return response()->json([
                'status' => 'error',
                'message' => 'มีรายการชำระเงินที่รอดำเนินการอยู่แล้วค่ะนายท่าน กรุณารอให้รายการเดิมเสร็จสิ้นก่อนนะคะ'
            ], 422);
        }

        try {
            DB::beginTransaction();

            $payment = Payment::create([
                'booking_id' => $booking->id,
                'amount' => $booking->total_amount,
                'payment_method' => $validated['payment_method'],
                'status' => 'pending' 
            ]);

            DB::commit();

            $paymentUrl = "https://gateway.mockbank.com/pay/" . $payment->id;

            return response()->json([
                'status' => 'success',
                'message' => 'Payment request created',
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'payment_url' => $paymentUrl
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Payment request failed: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการสร้างรายการชำระเงิน กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭'
            ], 500);
        }
    }

    // 🌟 Refactor (18/06/26): ลบ requestPaymentForGuest() ออก — non member/guest ใช้งานไม่ได้แล้ว ต้อง login ทุกกรณี

    // ❄️ FROZEN (24/07/26): ย้าย flow ยืนยันการชำระไป POST /bookings/{id}/confirm + admin verify แล้ว
    //    (payments/receipts tables freeze — read-only legacy)
    //    webhook เดิมไม่มี HMAC signature + เป็น mock → ปิดไปเลยเพื่อกันการใช้งานสับสน
    public function webhook(Request $request)
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'Webhook deprecated — ใช้ POST /api/v1/bookings/{id}/confirm แทนค่ะนายท่าน (รองรับ admin verify ผ่าน booking_confirmations table)',
        ], 410);
    }
}