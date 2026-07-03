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

                // 🌟 Fix M3 (03/07/26): guard state machine — กัน webhook duplicate พัง
                // ถ้า booking จ่ายแล้ว (paid/confirmed) จะไม่ transition ซ้ำ (draft → paid เท่านั้น)
                if ($booking->status === 'draft') {
                    $booking->transitionStatus('paid', 'system');
                }

                // 🌟 Fix M2 (03/07/26): Idempotency guard กัน duplicate receipt
                // (ถ้า payment_id เดิมเคยออกใบเสร็จแล้ว จะไม่สร้างซ้ำ)
                if (!Receipt::where('payment_id', $payment->id)->exists()) {
                    // ✅ #19 Fixed: ใช้ atomic counter แทน rand() สร้าง receipt number
                    $receiptNo = Receipt::generateUniqueReceiptNo();

                    // 🌟 Refactor (18/06/26): ใช้ primary_guest_name (จาก booking_rooms) แทน guest_name ที่ถูกลบไปแล้ว
                    Receipt::create([
                        'receipt_no' => $receiptNo,
                        'booking_id' => $booking->id,
                        'payment_id' => $payment->id,
                        'amount' => $payment->amount,
                        'billing_name' => $booking->primary_guest_name ?? 'Customer',
                    ]);
                }

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