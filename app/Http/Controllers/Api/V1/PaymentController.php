<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

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

    /**
      * 💳 Guest ขอลิงก์ชำระเงินสำหรับบุ๊กกิ้งของตัวเอง (ไม่ต้องล็อกอิน)
     * ✅ #28 Fixed: บังคับ guest_email required + AND logic เหมือน lookupBooking()
     */
    public function requestPaymentForGuest(Request $request, $id)
    {
        $request->validate([
            'guest_email' => 'required|string|email',
            'guest_phone' => 'nullable|string',
        ]);

        try {
            $booking = Booking::findOrFail($id);

            // ✅ #28 Fixed: ยืนยันตัวตนด้วย AND logic (email บังคับ, phone เสริม)
            $identityMatch = $booking->guest_email === $request->guest_email;
            if ($request->guest_phone) {
                $identityMatch = $identityMatch && $booking->guest_phone === $request->guest_phone;
            }

            if (!$identityMatch) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ข้อมูลไม่ตรงกับรายการจองนะคะนายท่าน กรุณาตรวจสอบอีกครั้งค่ะ 🔒',
                ], 403);
            }

            // ตรวจสอบสถานะบุ๊กกิ้ง
            if ($booking->is_paid) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'รายการจองนี้ชำระเงินแล้วค่ะ',
                ], 400);
            }

            if ($booking->status !== 'draft') {
                return response()->json([
                    'status' => 'error',
                    'message' => "รายการจองนี้สถานะเป็น '{$booking->status}' ไม่สามารถขอชำระเงินได้ค่ะ",
                ], 400);
            }

            // ตรวจสอบ deadline
            if ($booking->payment_deadline && Carbon::now()->isAfter($booking->payment_deadline)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'หมดเวลาชำระเงินแล้วค่ะนายท่าน กรุณาสร้างรายการจองใหม่นะคะ',
                ], 400);
            }

            // สร้าง Payment record
            $payment = Payment::create([
                'booking_id' => $booking->id,
                'amount' => $booking->total_amount,
                'payment_method' => 'online',
                'status' => 'pending',
            ]);

            // สร้าง payment URL (mock — จริงๆ ต้องเรียก Payment Gateway)
            $paymentUrl = "https://gateway.mockbank.com/pay/" . $payment->id;

            return response()->json([
                'status' => 'success',
                'message' => 'สร้างรายการชำระเงินเรียบร้อยแล้วค่ะ กรุณาชำระเงินภายในเวลาที่กำหนดนะคะ',
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'payment_url' => $paymentUrl,
                'payment_deadline' => $booking->payment_deadline?->toDateTimeString(),
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบรายการจองนี้ในระบบค่ะนายท่าน',
            ], 404);
        } catch (\Exception $e) {
            Log::error("Guest payment request failed: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    // 🌸 Webhook: ธนาคารแจ้งผลกลับ (draft → paid เท่านั้น, admin confirm เอง)
    public function webhook(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|uuid|exists:payments,id',
            'status' => 'required|string',
            'reference_number' => 'nullable|string'
        ]);

        $payment = Payment::findOrFail($request->payment_id);
        $booking = Booking::findOrFail($payment->booking_id);

        if ($request->status === 'success') {
            try {
                DB::beginTransaction();

                // ✅ #29 Fixed: เช็ค payment_deadline ก่อน process — ป้องกัน expired booking ถูกจ่าย
                if ($booking->payment_deadline && Carbon::now()->isAfter($booking->payment_deadline)) {
                    $payment->update(['status' => 'failed']);
                    DB::commit();
                    return response()->json([
                        'status' => 'error',
                        'message' => 'หมดเวลาชำระเงินแล้วค่ะ ไม่สามารถดำเนินการได้',
                    ], 422);
                }

                // อัปเดต Payment เป็น completed
                $payment->update([
                    'status' => 'completed',
                    'reference_number' => $request->reference_number
                ]);

                // ✅ #33 Fixed: ใช้ PHP boolean แทน DB::raw('TRUE') — portable ข้าม database
                $booking->update(['is_paid' => true]);

                // 🌟 ใช้ state machine เปลี่ยนสถานะ booking → paid (system role)
                $booking->transitionStatus('paid', 'system');

                // ✅ #19 Fixed: ใช้ atomic counter แทน rand() สร้าง receipt number
                $receiptNo = Receipt::generateUniqueReceiptNo();
                
                Receipt::create([
                    'receipt_no' => $receiptNo,
                    'booking_id' => $booking->id,
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'billing_name' => $booking->guest_name ?? 'Customer',
                ]);

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment processed successfully. Booking is now paid — waiting for admin to confirm.'
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Internal Server Error'
                ], 500);
            }
        }

        // กรณีจ่ายไม่สำเร็จ
        $payment->update(['status' => 'failed']);
        return response()->json([
            'status' => 'success',
            'message' => 'Payment failed recorded'
        ], 200);
    }
}