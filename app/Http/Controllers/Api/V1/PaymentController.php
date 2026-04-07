<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PaymentController extends Controller
{
    // 🌸 เฟส 1: สร้างรายการชำระเงิน (Request Payment)
    public function requestPayment(Request $request)
    {
        $request->validate([
            'booking_id' => 'required|uuid|exists:bookings,id',
            'payment_method' => 'required|string' // เช่น promptpay, credit_card
        ]);

        $booking = Booking::findOrFail($request->booking_id);

        // เช็คก่อนว่าจ่ายไปหรือยัง จะได้ไม่ซ้ำซ้อนค่ะนายท่าน!
        if ($booking->is_paid) {
            return response()->json([
                'status' => 'error',
                'message' => 'This booking is already paid.'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // สร้างรายการ Payment สถานะ 'pending' ตาม Sequence Diagram
            $payment = Payment::create([
                'booking_id' => $booking->id,
                'amount' => $booking->total_amount,
                'payment_method' => $request->payment_method,
                'status' => 'pending' 
            ]);

            DB::commit();

            // 🎁 จำลองการสร้าง URL หรือ QR Code สำหรับจ่ายเงิน
            $paymentUrl = "https://gateway.mockbank.com/pay/" . $payment->id;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'payment_url' => $paymentUrl
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 🌸 เฟส 3: ธนาคารแจ้งผลกลับมาที่ระบบ (Webhook)
    public function webhook(Request $request)
    {
        // ในสถานการณ์จริง นายท่านต้อง Validate Signature จากธนาคารด้วยนะคะ
        // แต่ตอนนี้หนูทำรับค่าเบื้องต้นให้ก่อนค่ะ
        $request->validate([
            'payment_id' => 'required|uuid|exists:payments,id',
            'status' => 'required|string', // เช่น success, failed
            'reference_number' => 'nullable|string'
        ]);

        $payment = Payment::findOrFail($request->payment_id);
        $booking = Booking::findOrFail($payment->booking_id);

        // ถ้าธนาคารส่งมาว่าจ่ายสำเร็จ (Success)
        if ($request->status === 'success') {
            try {
                DB::beginTransaction();

                // 1. อัปเดต PAYMENTS ให้สถานะเป็น 'completed'
                $payment->update([
                    'status' => 'completed',
                    'reference_number' => $request->reference_number
                ]);

                // 2. อัปเดต BOOKINGS ให้ is_paid = true และเปลี่ยนสถานะเป็น confirmed
                $booking->update([
                    'is_paid' => true,
                    'status' => 'confirmed'
                ]);

                // 3. สร้างข้อมูลลงตาราง RECEIPTS
                $receiptNo = 'REC-' . Carbon::now()->format('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                Receipt::create([
                    'receipt_no' => $receiptNo,
                    'booking_id' => $booking->id,
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'billing_name' => 'Customer Name', // สามารถรับเพิ่มจาก Frontend ตอนจ่ายเงินได้ค่ะ
                ]);

                DB::commit();

                // 4. ตอบกลับ 200 OK ให้ธนาคารทราบว่ารับข้อมูลแล้ว
                return response()->json(['message' => 'Payment processed successfully'], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['message' => 'Internal Server Error'], 500);
            }
        }

        // กรณีจ่ายไม่สำเร็จ (Failed)
        $payment->update(['status' => 'failed']);
        return response()->json(['message' => 'Payment failed recorded'], 200);
    }
}