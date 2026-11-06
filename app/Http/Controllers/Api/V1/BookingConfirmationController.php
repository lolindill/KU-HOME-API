<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmBookingRequest;
use App\Http\Requests\ReviewConfirmationRequest;
use App\Models\Booking;
use App\Models\BookingConfirmation;
use App\Models\Payment;
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
 *
 * 💳 (06/11/26, owner — ย้อนมติ 25/09/26): บิล **deferred** รับสลิปผ่าน confirm ได้แล้วทุกสถานะหลัก
 *    (draft/verify_error/paid/confirmed — เก็บยอดค้างเป็นงวดด้วยสลิปได้) · ผู้ส่งเป็น **admin**
 *    → instant-verify (confirmation verified + ledger จุดที่ 4 + บิล confirmed ทันที) ·
 *    เจ้าของส่ง → flow เดิม (pending รอ verify) · บิล full/deposit คงเดิมทุกอย่าง
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

        // 💳 (06/11/26, owner — ย้อนมติ 25/09/26 "deferred บล็อกสลิป"): บิล deferred รับสลิปผ่าน endpoint นี้ได้แล้ว
        //    (FE AdminConfirm แนบสลิปทุกครั้งที่ add/update บิล) — state guard ขยายตาม payment_type
        $isDeferred = $booking->payment_type === 'deferred';

        // ownership check (เจ้าของหรือ admin เท่านั้น) — deferred โหมด B (user_id null) เหลือ admin เท่านั้น
        if ($user->role !== 'admin' && $booking->user_id !== $user->id) {
            return $this->error('ไม่มีสิทธิ์ส่งหลักฐานการชำระสำหรับ booking นี้ค่ะ', 403);
        }

        // state guard — บิลปกติรับเฉพาะ draft/verify_error (หลัง reject booking จะเป็น verify_error เพื่อส่งใหม่)
        // 💳 deferred รับเพิ่ม paid/confirmed (เก็บยอดค้างเป็นงวดด้วยสลิปได้) — 'pending' ยังกันด้วย
        //    existing-pending guard (สลิปค้างสูงสุด 1) · บิล full/deposit ที่จ่ายแล้วเก็บต่อผ่าน recordPayment เท่านั้น
        $allowedStates = $isDeferred
            ? ['draft', 'verify_error', 'paid', 'confirmed']
            : ['draft', 'verify_error'];
        if (! in_array($booking->status, $allowedStates, true)) {
            $hint = $isDeferred
                ? 'รับเฉพาะ draft, verify_error, paid หรือ confirmed (pending = มีสลิปรอตรวจอยู่)'
                : 'รับเฉพาะ draft หรือ verify_error เท่านั้น';

            return $this->error(
                "ไม่สามารถส่งหลักฐานได้เพราะ booking อยู่ในสถานะ '{$booking->status}' ({$hint})",
                422
            );
        }

        // payment_deadline guard (ตรวจเฉพาะ draft — verify_error ส่งใหม่ได้แม้หมด deadline)
        // 💳 (06/11/26): deferred ไม่มีวันหมดอายุ — CleanupExpiredDrafts ยกเว้น deferred อยู่แล้ว
        //    (ยึด slot ต่อแม้เลย 15 นาที) จึงต้องข้าม deadline guard ไม่งั้นบิล deferred เกิน 15 นาทีส่งสลิปไม่ได้
        if (! $isDeferred && $booking->status === 'draft' && $booking->payment_deadline && Carbon::now()->isAfter($booking->payment_deadline)) {
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

        // 👥 ชั้น 2 (REQ-007/019): ทุก booking_room ของ booking ต้องมี guests >= 1 คน
        foreach ($booking->bookingRooms as $room) {
            $guests = $room->guests;
            if (empty($guests) || ! is_array($guests) || count($guests) < 1) {
                return $this->error('กรุณาระบุข้อมูลผู้เข้าพักอย่างน้อย 1 คนต่อห้องก่อนส่งหลักฐานการชำระเงินค่ะ 🥺', 422);
            }
        }

        $validated = $request->validated();

        $slipPath = null;

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->where('id', $bookingId)->lockForUpdate()->firstOrFail();

            if (! in_array($booking->status, $allowedStates, true)) {
                throw new Exception("ไม่สามารถส่งหลักฐานได้เพราะ booking อยู่ในสถานะ '{$booking->status}'", 422);
            }

            $existingPending = BookingConfirmation::where('booking_id', $booking->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->exists();
            if ($existingPending) {
                throw new Exception('มีหลักฐานการชำระที่รอตรวจสอบอยู่แล้ว — รอแอดมินตรวจสอบก่อนค่ะนายท่าน', 422);
            }

            // 🖼️ (19/08/26): เก็บไฟล์ slip บน private disk (storage/app/private/slips — เว็บเปิดตรงๆ ไม่ได้)
            //    ดูรูปได้ผ่าน signed URL อายุ 15 นาทีเท่านั้น (เจ้าของ booking / admin)
            $slipFile = $request->file('slip_image');
            $slipPath = $slipFile->store('slips', 'local');

            // 💳 (06/11/26, owner): admin ส่งสลิปบิล deferred = **ยืนยันทันที** (instant-verify) —
            //    confirmation verified + เขียน ledger จุดที่ 4 + บิล confirmed ตาม state machine
            //    · เจ้าของส่ง หรือ บิล full/deposit → flow เดิม (pending รอ admin verify)
            $isInstantVerify = $isDeferred && $user->role === 'admin';

            $payment = null;
            if ($isInstantVerify) {
                // สร้าง row ใหม่เสมอ (1:N history — ไม่ upsert) — verified ทันที + ผู้รีวิว = admin ผู้ส่ง
                $confirmation = BookingConfirmation::create([
                    'booking_id' => $booking->id,
                    'transfer_time' => $validated['transfer_time'] ?? null,
                    'amount' => $validated['amount'] ?? null,
                    'status' => 'verified',
                    'reviewed_by' => $user->id,
                    'reviewed_at' => now(),
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

                // 💳 ledger จุดที่ 4 (จดใน cline.md — มติ 06/11/26): เขียนเมื่อแจ้ง amount เท่านั้น —
                //    deferred ไม่แจ้ง amount ≠ จ่ายครบ (ต่างจาก fallback ของ full ใน verify())
                //    · is_paid = SUM(payments) ≥ total + charges (pattern เดียวกับ verify)
                $ledgerAmount = $validated['amount'] ?? null;
                if ($ledgerAmount !== null) {
                    $payment = Payment::create([
                        'booking_id' => $booking->id,
                        'amount' => $ledgerAmount,
                        'status' => 'completed',
                        'reference_number' => $confirmation->id,
                        'received_by' => $user->id,
                    ]);

                    $booking->update(['is_paid' => $booking->paid_amount >= $booking->total_amount + $booking->charges_total]);
                }

                // transitions — เส้นทางที่ matrix อนุญาต (admin): draft→confirmed (walk-in เดิม)
                // · verify_error→pending→paid→confirmed · paid→confirmed · confirmed คงสถานะ
                if ($booking->status === 'draft') {
                    $booking->transitionStatus('confirmed', $user->role);
                } elseif ($booking->status === 'verify_error') {
                    $booking->transitionStatus('pending', $user->role);
                    $booking->transitionStatus('paid', $user->role);
                    $booking->transitionStatus('confirmed', $user->role);
                } elseif ($booking->status === 'paid') {
                    $booking->transitionStatus('confirmed', $user->role);
                }
            } else {
                // flow เดิม — สร้าง row ใหม่เสมอ (1:N history — ไม่ upsert)
                $confirmation = BookingConfirmation::create([
                    'booking_id' => $booking->id,
                    'transfer_time' => $validated['transfer_time'] ?? null,
                    // 💳 (25/09/26, ticket 04 ชั้น A): ยอดที่ user แจ้งต่อครั้งส่งสลิป (08/10/26 → nullable)
                    'amount' => $validated['amount'] ?? null,
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
            }

            DB::commit();

            $freshBooking = $booking->fresh();

            return response()->json([
                'status' => 'success',
                // 💳 (06/11/26): admin ส่งสลิป deferred = ยืนยันทันที ไม่ได้ "รอตรวจ"
                'message' => $isInstantVerify
                    ? 'ยืนยันการชำระเงินทันที (admin) — บิลอัปเดตสถานะตาม state machine แล้วค่ะนายท่าน'
                    : 'ส่งหลักฐานการชำระเรียบร้อย — รอแอดมินตรวจสอบค่ะนายท่าน',
                'confirmation_id' => $confirmation->id,
                'confirmation_status' => $confirmation->status,
                // 💳 (25/09/26, ticket 05 หัวข้อ 3): echo ยอดที่แจ้ง + ยอดที่ระบบคาด + สถานะ
                'amount' => $confirmation->amount,
                'expected_amount' => $freshBooking->expected_amount,
                'booking_status' => $freshBooking->status,
                // 💳 (06/11/26): ledger row ที่เขียนทันทีเมื่อ admin ส่งสลิป deferred พร้อมแจ้งยอด
                //    (null เมื่อไม่แจ้ง amount หรือไม่ใช่ instant-verify path)
                'payment_recorded' => $payment?->fresh(),
                // 💳 envelope 4 field ของ booking
                'payment_type' => $freshBooking->payment_type,
                'deposit_amount' => $freshBooking->deposit_amount,
                'paid_amount' => $freshBooking->paid_amount,
                'outstanding_amount' => $freshBooking->outstanding_amount,
                'slip_image_url' => $image->url,
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            // 🧹 best-effort: ไฟล์ไม่ถูกคุมด้วย DB transaction — เก็บกำพร้าที่เขียนไปก่อน rollback ทันที
            //    (ส่วนที่หลุดไปจริงๆ มี app:cleanup-images เก็บรอบ 02:30 อีกชั้น)
            if ($slipPath !== null) {
                Storage::disk('local')->delete($slipPath);
            }

            return $this->handleBusinessError($e);
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

            $confirmation = BookingConfirmation::with('booking')
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($confirmation->status !== 'pending') {
                throw new Exception("Confirmation is already {$confirmation->status}", 422);
            }

            $confirmation->transitionStatus('verified', $user->role);
            $confirmation->update([
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'review_note' => $request->input('review_note'),
            ]);

            $booking = $confirmation->booking;

            // 💳 (25/09/26, ticket 04/05): เขียน payments ledger — เงินเข้าจริงเมื่อ verify ผ่าน
            //    1 row = 1 เหตุการณ์เงิน · reference_number = confirmation UUID (จุดเขียน payments
            //    = 3 จุดเท่านั้น: verify / recordPayment / mock QR)
            //
            //    💳 (08/10/26, owner): amount เป็น nullable — สลิปที่ user ไม่แจ้งยอด:
            //    ไม่เขียน ledger (0 บาท ไม่ใช่เหตุการณ์เงิน) + **ไม่แตะ is_paid** (คงค่าเดิม —
            //    is_paid ตรวจเฉพาะเมื่อมีการแจ้งยอดเท่านั้น; admin ตัดสินจากภาพสลิปเอง)
            //
            //    💳 (22/10/26, owner — bug fix "บิล full ยังค้างชำระหลัง verify"): type **full**
            //    + สลิปไม่แจ้งยอด → verify = admin ยืนยันว่า "จ่ายครบ" → เขียน ledger = ยอดคงค้าง
            //    ณ ตอน verify (total + charges − จ่ายมาแล้ว — ไม่ซ้ำเกินเมื่อมีเงินเข้าก่อนหน้า)
            //    · outstanding = 0 อยู่แล้วไม่เขียน (0 บาทไม่ใช่เหตุการณ์เงิน) · **deposit
            //    คง soft เดิม** — ไม่เดายอดงวด (admin ตัดสินจากภาพสลิป + เก็บส่วนที่เหลือ
            //    ผ่าน recordPayment ภายหลัง)
            $ledgerAmount = $confirmation->amount;
            if ($ledgerAmount === null && $booking->payment_type === 'full' && $booking->outstanding_amount > 0) {
                $ledgerAmount = $booking->outstanding_amount;
            }

            $payment = null;
            if ($ledgerAmount !== null) {
                $payment = Payment::create([
                    'booking_id' => $booking->id,
                    'amount' => $ledgerAmount,
                    'status' => 'completed',
                    'reference_number' => $confirmation->id,
                    'received_by' => $user->id,
                ]);

                // 💳 is_paid = SUM(payments) ≥ total + charges (จ่ายครบจุดไหนจุดนั้น — deposit งวดแรกยัง false)
                //    💸 (15/10/26, ticket 13): charges (ค่าปรับ/ค่าเสียหาย/addon) บวกยอดจริงแล้ว
                //    full จ่ายเต็ม → true เหมือน flow เดิม (regression 0%)
                $booking->update(['is_paid' => $booking->paid_amount >= $booking->total_amount + $booking->charges_total]);
            }

            // 🌟 Refactor (25/08/26): pending → paid (ตอนนี้ 'paid' = admin ตรวจแล้ว + is_paid ตาม ledger)
            //    branch 'paid' เก็บไว้รองรับ legacy data ก่อน refactor (submit แล้วค้าง paid)
            if ($booking->status === 'pending') {
                $booking->transitionStatus('paid', 'admin');
            }

            // booking paid → confirmed (state machine อนุญาต role=admin)
            if ($booking->status === 'paid') {
                $booking->transitionStatus('confirmed', 'admin');
            }

            DB::commit();

            $freshBooking = $confirmation->booking->fresh();

            return response()->json([
                'status' => 'success',
                'message' => 'ยืนยันการชำระเงินเรียบร้อย — booking confirmed',
                'confirmation' => $confirmation->fresh(['slipImage']),
                // 💳 (25/09/26, ticket 05 หัวข้อ 4): expected vs claimed (soft — admin ตัดสินเอง)
                'expected_amount' => $freshBooking->expected_amount,
                'claimed_amount' => $confirmation->amount,
                // 💳 ledger row ที่เพิ่งเขียน (null เมื่อไม่มีเหตุการณ์เงิน — เช่น deposit สลิปไม่แจ้งยอด)
                'payment_recorded' => $payment?->fresh(),
                'booking_status' => $freshBooking->status,
                'booking_is_paid' => $freshBooking->is_paid,
                // 💳 envelope 4 field ของ booking
                'payment_type' => $freshBooking->payment_type,
                'deposit_amount' => $freshBooking->deposit_amount,
                'paid_amount' => $freshBooking->paid_amount,
                'outstanding_amount' => $freshBooking->outstanding_amount,
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

            $confirmation = BookingConfirmation::with('booking')
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($confirmation->status !== 'pending') {
                throw new Exception("Confirmation is already {$confirmation->status}", 422);
            }

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

            $freshBooking = $confirmation->booking->fresh();

            return response()->json([
                'status' => 'success',
                'message' => 'ปฏิเสธสลิป — booking เปลี่ยนสถานะเป็น verify_error รอผู้จองแจ้งใหม่',
                'confirmation' => $confirmation->fresh(['slipImage']),
                'booking_status' => $freshBooking->status,
                // 💳 (25/09/26, ticket 05 หัวข้อ 5): envelope 4 field — ค่าไม่เปลี่ยน (ไม่มี payments row)
                //    ส่งเพื่อ consistency กับ verify/response อื่น
                'payment_type' => $freshBooking->payment_type,
                'deposit_amount' => $freshBooking->deposit_amount,
                'paid_amount' => $freshBooking->paid_amount,
                'outstanding_amount' => $freshBooking->outstanding_amount,
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

        // 💳 (25/09/26, ticket 05 หัวข้อ 6): booking ถูก eager load แล้ว → accessor expected_amount
        //    (appends ของ BookingConfirmation) ทำงานโดยไม่เพิ่ม query — admin เห็นคู่
        //    expected (ระบบคำนวณ) vs amount (claimed ที่ user แจ้ง) ตั้งแต่หน้า dashboard
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
