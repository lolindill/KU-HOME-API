<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmBookingRequest;
use App\Http\Requests\ReviewConfirmationRequest;
use App\Models\Booking;
use App\Models\BookingConfirmation;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 🌟 Refactor (24/07/26): Booking Confirmation Controller
 *
 * แทนที่ payments/receipts table (frozen) ด้วย flow ใหม่:
 *   1. user POST /bookings/{id}/confirm  → สร้าง confirmation (status=pending) + booking draft→paid
 *   2. admin PUT /booking-confirmations/{id}/verify  → pending→verified + booking paid→confirmed
 *   3. admin PUT /booking-confirmations/{id}/reject  → pending→rejected, booking ค้าง paid
 *   4. admin GET  /booking-confirmations/pending     → dashboard list
 *
 * 1:N — 1 booking มีได้หลาย confirmation (history) แต่มี pending ได้ทีละ 1 row (guard กัน spam)
 */
class BookingConfirmationController extends Controller
{
    // ============================================================
    // 💳 confirm — user ส่งหลักฐานการชำระ (slip + time)
    // ============================================================
    public function confirm(ConfirmBookingRequest $request, string $bookingId)
    {
        $user = $request->user('sanctum');
        if (! $user) {
            return $this->error('ต้อง login ก่อนถึงจะส่งหลักฐานการชำระได้ค่ะนายท่าน', 401);
        }

        $booking = Booking::findOrFail($bookingId);

        // ownership check (เจ้าของหรือ admin เท่านั้น)
        if ($user->role !== 'admin' && $booking->user_id !== $user->id) {
            return $this->error('ไม่มีสิทธิ์ส่งหลักฐานการชำระสำหรับ booking นี้ค่ะ', 403);
        }

        // state guard — รับเฉพาะ draft หรือ paid (paid = re-submit หลัง reject)
        if (! in_array($booking->status, ['draft', 'paid'])) {
            return $this->error(
                "ไม่สามารถส่งหลักฐานได้เพราะ booking อยู่ในสถานะ '{$booking->status}' (รับเฉพาะ draft/paid เท่านั้น)",
                422
            );
        }

        // payment_deadline guard (เหมือน webhook เดิม)
        if ($booking->payment_deadline && Carbon::now()->isAfter($booking->payment_deadline)) {
            return $this->error('หมดเวลาชำระเงินแล้วค่ะ ไม่สามารถดำเนินการได้', 422);
        }

        // 🚦 1 pending max guard — กัน spam (ต้องรอ admin ตรวจก่อนถึงจะส่งใหม่ได้)
        $existingPending = BookingConfirmation::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->exists();
        if ($existingPending) {
            return $this->error(
                'มีหลักฐานการชำระที่รอตรวจสอบอยู่แล้ว — รอแอดมินตรวจสอบก่อนค่ะนายท่าน',
                422
            );
        }

        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // เก็บไฟล์ slip (บังคับเสมอ — validation required แล้ว)
            $slipPath = $request->file('slip_image')->store('slips', 'public');

            // สร้าง row ใหม่เสมอ (1:N history — ไม่ upsert)
            $confirmation = BookingConfirmation::create([
                'booking_id' => $booking->id,
                'slip_image' => $slipPath,
                'transfer_time' => $validated['transfer_time'] ?? null,
                'status' => 'pending',
            ]);

            // booking transition draft → paid (เฉพาะ draft; paid แล้วจะไม่ transition ซ้ำ)
            // 🌟 Fix 03/07/26: ใช้ PHP true + PgBoolean cast (ห้ามใช้ DB::raw('TRUE'))
            if ($booking->status === 'draft') {
                $booking->update(['is_paid' => true]);
                $booking->transitionStatus('paid', $user->role);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'ส่งหลักฐานการชำระเรียบร้อย — รอแอดมินตรวจสอบค่ะนายท่าน',
                'confirmation_id' => $confirmation->id,
                'confirmation_status' => $confirmation->status,
                'booking_status' => $booking->fresh()->status,
                'slip_image_url' => Storage::url($slipPath),
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Booking confirm failed: '.$e->getMessage());

            return $this->error('เกิดข้อผิดพลาดในการส่งหลักฐานการชำระ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭', 500);
        }
    }

    // ============================================================
    // ✅ verify — admin ยืนยันสลิป (pending → verified + booking paid → confirmed)
    // ============================================================
    public function verify(ReviewConfirmationRequest $request, string $id)
    {
        $user = $request->user('sanctum');
        if (! $user || $user->role !== 'admin') {
            return $this->error('ต้องเป็นแอดมินเท่านั้นถึงจะยืนยันได้ค่ะนายท่าน', 403);
        }

        $confirmation = BookingConfirmation::with('booking')->findOrFail($id);

        try {
            DB::beginTransaction();

            $confirmation->transitionStatus('verified', $user->role);
            $confirmation->update([
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_note' => $request->input('review_note'),
            ]);

            // booking paid → confirmed (state machine อนุญาต role=admin)
            if ($confirmation->booking->status === 'paid') {
                $confirmation->booking->transitionStatus('confirmed', 'admin');
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'ยืนยันการชำระเงินเรียบร้อย — booking confirmed',
                'confirmation' => $confirmation->fresh(),
                'booking_status' => $confirmation->booking->fresh()->status,
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return $this->handleBusinessError($e);
        }
    }

    // ============================================================
    // ❌ reject — admin ปฏิเสธสลิป (pending → rejected, booking ค้าง paid)
    // ============================================================
    public function reject(ReviewConfirmationRequest $request, string $id)
    {
        $user = $request->user('sanctum');
        if (! $user || $user->role !== 'admin') {
            return $this->error('ต้องเป็นแอดมินเท่านั้นถึงจะปฏิเสธได้ค่ะนายท่าน', 403);
        }

        $confirmation = BookingConfirmation::with('booking')->findOrFail($id);

        try {
            DB::beginTransaction();

            $confirmation->transitionStatus('rejected', $user->role);
            $confirmation->update([
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_note' => $request->input('review_note'),
            ]);

            // ❗ booking ยังคง paid — ไม่ transition (รอ user ส่ง slip ใหม่ = row ใหม่)
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'ปฏิเสธสลิป — booking ยังคง paid รอผู้จองแจ้งใหม่',
                'confirmation' => $confirmation->fresh(),
                'booking_status' => $confirmation->booking->fresh()->status,
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return $this->handleBusinessError($e);
        }
    }

    // ============================================================
    // 📋 pending — admin list หลักฐานที่รอตรวจ (dashboard)
    // ============================================================
    public function pending(Request $request)
    {
        $user = $request->user('sanctum');
        if (! $user || $user->role !== 'admin') {
            return $this->error('ต้องเป็นแอดมินเท่านั้นค่ะนายท่าน', 403);
        }

        $confirmations = BookingConfirmation::with(['booking.user', 'reviewer'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc') // เก่าก่อน (FIFO)
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'confirmations' => $confirmations,
        ], 200);
    }

    // ============================================================
    // 🔧 helpers
    // ============================================================
    private function error(string $message, int $code)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], $code);
    }

    /**
     * แยก business logic error (422/403 จาก state machine) จาก unexpected error (500)
     * - state machine throw Exception พร้อม code 422/403 → ส่ง message ได้
     * - exception อื่น → ซ่อน message, log ไว้ debug (✅ #40 fix)
     */
    private function handleBusinessError(Exception $e)
    {
        $code = (int) $e->getCode();

        if (in_array($code, [422, 403], true)) {
            return $this->error($e->getMessage(), $code);
        }

        Log::error('Booking confirmation review failed: '.$e->getMessage());

        return $this->error('เกิดข้อผิดพลาดบางอย่าง กรุณาลองใหม่ค่ะนายท่าน 😭', 500);
    }
}
