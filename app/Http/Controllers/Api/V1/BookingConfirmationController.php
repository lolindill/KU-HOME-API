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
 *   1. user POST /bookings/{id}/confirm  → สร้าง confirmation (status=pending) + booking draft/verify_error→pending
 *   2. admin PUT /booking-confirmations/{id}/verify  → pending→verified + booking pending→paid→confirmed
 *   3. admin PUT /booking-confirmations/{id}/reject  → pending→rejected + booking pending→verify_error
 *   4. admin GET  /booking-confirmations/pending     → dashboard list
 *
 * 🌟 Refactor (25/08/26): booking container มี state 'pending' แล้ว — mirror กับ confirmation
 *    (ก่อนหน้านี้ submit สลิป = draft→paid ทันทีทั้งที่ยังไม่มีใครตรวจ; ตอนนี้ 'paid' = admin ตรวจแล้วเท่านั้น
 *     และ is_paid ถูก set ตอน verify ไม่ใช่ตอน submit)
 * 🌟 Refactor (25/08/26): reject เปลี่ยน booking เป็น 'verify_error' แทนการกลับ draft — ไม่ถูก cleanup ลบ
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

        // state guard — รับเฉพาะ draft หรือ verify_error (หลัง reject booking จะเป็น verify_error เพื่อส่งใหม่)
        if (! in_array($booking->status, ['draft', 'verify_error'], true)) {
            return $this->error(
                "ไม่สามารถส่งหลักฐานได้เพราะ booking อยู่ในสถานะ '{$booking->status}' (รับเฉพาะ draft หรือ verify_error เท่านั้น)",
                422
            );
        }

        // payment_deadline guard (ตรวจเฉพาะ draft — verify_error ส่งใหม่ได้แม้หมด deadline)
        if ($booking->status === 'draft' && $booking->payment_deadline && Carbon::now()->isAfter($booking->payment_deadline)) {
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

        $slipPath = null;

        try {
            DB::beginTransaction();

            // 🖼️ (19/08/26): เก็บไฟล์ slip บน private disk (storage/app/private/slips — เว็บเปิดตรงๆ ไม่ได้)
            //    ดูรูปได้ผ่าน signed URL อายุ 15 นาทีเท่านั้น (เจ้าของ booking / admin)
            $slipFile = $request->file('slip_image');
            $slipPath = $slipFile->store('slips', 'local');

            // สร้าง row ใหม่เสมอ (1:N history — ไม่ upsert)
            $confirmation = BookingConfirmation::create([
                'booking_id' => $booking->id,
                'transfer_time' => $validated['transfer_time'] ?? null,
                'status' => 'pending',
            ]);

            // 🖼️ metadata รูปลง images table — morph ผูกกับ confirmation นี้
            $image = $confirmation->slipImage()->create([
                'path' => $slipPath,
                'disk' => 'local',
                'mime_type' => $slipFile->getMimeType(),
                'size' => $slipFile->getSize(),
                'original_name' => $slipFile->getClientOriginalName(),
                'uploaded_by' => $user->id,
            ]);

            // booking transition draft → pending (รอ admin ตรวจสลิป)
            // 🌟 Refactor (25/08/26): 'paid' + is_paid จะเกิดตอน admin verify เท่านั้น ไม่ใช่ตอน submit
            $booking->transitionStatus('pending', $user->role);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'ส่งหลักฐานการชำระเรียบร้อย — รอแอดมินตรวจสอบค่ะนายท่าน',
                'confirmation_id' => $confirmation->id,
                'confirmation_status' => $confirmation->status,
                'booking_status' => $booking->fresh()->status,
                'slip_image_url' => $image->url,
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            // 🧹 best-effort: ไฟล์ไม่ถูกคุมด้วย DB transaction — เก็บกำพร้าที่เขียนไปก่อน rollback ทันที
            //    (ส่วนที่หลุดไปจริงๆ มี app:cleanup-images เก็บรอบ 02:30 อีกชั้น)
            if ($slipPath !== null) {
                Storage::disk('local')->delete($slipPath);
            }

            Log::error('Booking confirm failed: '.$e->getMessage());

            return $this->error('เกิดข้อผิดพลาดในการส่งหลักฐานการชำระ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭', 500);
        }
    }

    // ============================================================
    // ✅ verify — admin ยืนยันสลิป (pending → verified + booking pending → paid → confirmed)
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

            $booking = $confirmation->booking;

            // 🌟 Refactor (25/08/26): pending → paid (ตอนนี้ 'paid' = admin ตรวจแล้ว + set is_paid)
            //    branch 'paid' เก็บไว้รองรับ legacy data ก่อน refactor (submit แล้วค้าง paid)
            if ($booking->status === 'pending') {
                // 🌟 Fix 03/07/26: ใช้ PHP true + PgBoolean cast (ห้ามใช้ DB::raw('TRUE'))
                $booking->update(['is_paid' => true]);
                $booking->transitionStatus('paid', 'admin');
            }

            // booking paid → confirmed (state machine อนุญาต role=admin)
            if ($booking->status === 'paid') {
                $booking->transitionStatus('confirmed', 'admin');
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'ยืนยันการชำระเงินเรียบร้อย — booking confirmed',
                'confirmation' => $confirmation->fresh(['slipImage']),
                'booking_status' => $confirmation->booking->fresh()->status,
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return $this->handleBusinessError($e);
        }
    }

    // ============================================================
    // ❌ reject — admin ปฏิเสธสลิป (pending → rejected + booking pending → draft)
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

            // 🌟 Refactor (25/08/26): booking เปลี่ยนเป็น verify_error — user ส่ง slip ใหม่ได้ (= row ใหม่)
            //    (verify_error ไม่ถูก CleanupExpiredDrafts ลบ — รอ user ส่งสลิปใหม่เมื่อไหร่ก็ได้)
            if ($confirmation->booking->status === 'pending') {
                $confirmation->booking->transitionStatus('verify_error', 'admin');
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'ปฏิเสธสลิป — booking เปลี่ยนสถานะเป็น verify_error รอผู้จองแจ้งใหม่',
                'confirmation' => $confirmation->fresh(['slipImage']),
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

        $confirmations = BookingConfirmation::with(['booking.user', 'reviewer', 'slipImage'])
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
