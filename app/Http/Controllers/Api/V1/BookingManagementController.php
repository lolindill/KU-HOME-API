<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBookingPaymentRequest;
use App\Models\Booking;
use App\Models\Organization;
use App\Models\StatusChangeLog;
use App\Services\Discount\DiscountService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingManagementController extends Controller
{
    /**
     * 💳🏛️ (25/09/26, booking-payment-types ticket 01/05 + organization-bookings ticket 07) — PUT /bookings/{id}
     *
     * Endpoint ระดับ booking container สำหรับแก้ payment fields + customer identity fields:
     *   💳 payment_type · deposit_amount (ส่ง null = revert effective 50%) · discount_code (reuse
     *   DiscountService — ค่าว่าง = ลบโค้ด, แก้แล้ว reconcile total_amount) · payment_deadline
     *   🏛️ user (UUID) · organize (erp code → FK) · customer_name/phone/email (snapshot ผู้ติดต่อ)
     *
     * Guards (ticket 07 — แยกตาม field-group):
     * - role admin/system (route role:admin + re-check ตาม defense-in-depth)
     * - 💳 payment group — booking ต้อง draft เท่านั้น — หลังส่งสลิป/verify แล้ว frozen (422)
     * - 🏛️ customer identity group — ได้ตั้งแต่ draft จนถึง checked_in (จนก่อน complete/no_show)
     * - deposit_amount มีความหมายเฉพาะ type deposit (422 ถ้า type ปัจจุบัน/ใหม่ ≠ deposit)
     * - identity: user×organize mutually exclusive · organize บังคับ customer_name · erp lookup
     *   ไม่เจอ/inactive → 422 (ชุดเดียวกับ POST /bookings, ticket 03) · ส่ง identity ใหม่ = แทนที่
     *   ทั้งชุด · ส่ง user/organize = null ชัด ๆ = ถอดกลับเฮดเปล่า · ไม่ re-run draft-dedup ตอน attach
     * - total_amount ไม่รับจาก input — reprice เฉพาะตอน draft ผ่าน DiscountService::reprice()
     *   (attach/ถอด user เปลี่ยนเรท daily_ku/daily ผ่าน chokepoint $booking->user · หลัง draft ขึ้นไป
     *   = เขียน identity เท่านั้น ราคาคงเดิม)
     */
    public function updateBookingPayment(UpdateBookingPaymentRequest $request, string $id)
    {
        try {
            $user = $request->user('sanctum');
            if (! $user || ! in_array($user->role, ['admin', 'system'], true)) {
                throw new \Exception('ต้องเป็นแอดมินเท่านั้นถึงจะแก้ประเภทการชำระเงินได้ค่ะนายท่าน 🔒', 403);
            }

            $booking = Booking::findOrFail($id);

            $validated = $request->validated();

            // 🏛️ ticket 07 — guard แยก field-group (ตรวจทั้งก่อน lock และหลัง lock กัน race)
            $touchesPayment = collect(['payment_type', 'deposit_amount', 'discount_code', 'payment_deadline'])
                ->contains(fn ($field) => $request->has($field));
            $touchesCustomer = collect(['user', 'organize', 'customer_name', 'customer_phone', 'customer_email'])
                ->contains(fn ($field) => $request->has($field));
            $this->assertUpdatePaymentGroupGuards($booking->status, $touchesPayment, $touchesCustomer);

            DB::beginTransaction();
            try {
                // 🌟 lock + re-check กัน race กับ confirm/verify ระหว่างแก้ไข
                $locked = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();
                $this->assertUpdatePaymentGroupGuards($locked->status, $touchesPayment, $touchesCustomer);

                // type ใหม่ที่ effective = ค่าที่ส่งมา ไม่งั้นค่าเดิม
                $effectiveType = $validated['payment_type'] ?? $locked->payment_type;

                // cross-field: deposit_amount มีความหมายเฉพาะ deposit
                if ($request->has('deposit_amount') && $validated['deposit_amount'] !== null && $effectiveType !== 'deposit') {
                    throw new \Exception('ส่ง deposit_amount ได้เฉพาะเมื่อ payment_type = deposit เท่านั้นค่ะ 💳', 422);
                }

                // 🏛️ ticket 07 — attach/switch/detach identity (validation ชุดเดียวกับ POST /bookings, ticket 03)
                //    "แทนที่" = เคลียร์ identity เดิมทั้งชุดก่อน แล้วเขียนด้วย field ที่ส่งมา (ไม่ส่ง phone/email = null)
                $attachUserId = $validated['user'] ?? null;
                $organizeCode = trim((string) ($validated['organize'] ?? ''));
                $identityChanged = false;

                if ($attachUserId !== null && $organizeCode !== '') {
                    throw new \Exception('ส่ง user และ organize พร้อมกันไม่ได้ — เลือกจองแทน user หรือจองให้องค์กรอย่างใดอย่างหนึ่งค่ะ 🏛️', 422);
                }

                if ($attachUserId !== null) {
                    // 🏛️ attach/switch จองแทน — link target user · org เดิม (ถ้ามี) ถูกแทนที่
                    $locked->user_id = $attachUserId;
                    $locked->organization_id = null;
                    $locked->customer_name = $validated['customer_name'] ?? null;
                    $locked->customer_phone = $validated['customer_phone'] ?? null;
                    $locked->customer_email = $validated['customer_email'] ?? null;
                    $identityChanged = true;
                } elseif ($organizeCode !== '') {
                    // 🏛️ attach/switch org — erp lookup เป็น FK (replaceability contract, ticket 01)
                    if (($validated['customer_name'] ?? null) === null || trim((string) $validated['customer_name']) === '') {
                        throw new \Exception('จองให้องค์กรต้องระบุ customer_name (ผู้ติดต่อ/ผู้เข้าพัก) ด้วยค่ะ 🏛️', 422);
                    }
                    $organization = Organization::where('erp', $organizeCode)->first();
                    if (! $organization || ! $organization->is_active) {
                        throw new \Exception('ไม่พบองค์กรตามรหัสที่ระบุ หรือองค์กรนี้ปิดใช้งานอยู่ค่ะ 🏛️', 422);
                    }
                    $locked->user_id = null;
                    $locked->organization_id = $organization->id;
                    $locked->customer_name = $validated['customer_name'];
                    $locked->customer_phone = $validated['customer_phone'] ?? null;
                    $locked->customer_email = $validated['customer_email'] ?? null;
                    $identityChanged = true;
                } elseif ($request->has('user') || $request->has('organize')) {
                    // 🏛️ ส่ง user/organize = null ชัด ๆ — ถอด identity กลับเฮดเปล่า (เคลียร์ snapshot ครบ)
                    $locked->user_id = null;
                    $locked->organization_id = null;
                    $locked->customer_name = null;
                    $locked->customer_phone = null;
                    $locked->customer_email = null;
                    $identityChanged = true;
                } else {
                    // แก้ snapshot ผู้ติดต่อล้วน (ไม่ส่ง user/organize) — แก้เฉพาะ field ที่ส่ง
                    if ($request->has('customer_name')) {
                        $locked->customer_name = $validated['customer_name'] ?? null;
                    }
                    if ($request->has('customer_phone')) {
                        $locked->customer_phone = $validated['customer_phone'] ?? null;
                    }
                    if ($request->has('customer_email')) {
                        $locked->customer_email = $validated['customer_email'] ?? null;
                    }
                }

                // 💳 payment_type — ไม่ส่ง = ไม่แตะค่าเดิม
                if ($validated['payment_type'] ?? null) {
                    $locked->payment_type = $validated['payment_type'];
                }

                // 💳 deposit_amount — ส่ง null ชัด ๆ = revert ไป effective 50% (column → null)
                //    (อ่านจาก input ตรง ๆ เพื่อแยก "ไม่ส่ง" ออกจาก "ส่ง null")
                if ($request->has('deposit_amount')) {
                    $locked->deposit_amount = $validated['deposit_amount'];
                }

                // ⏱️ payment_deadline — ต่อ/ลดเวลาชำระ · ไม่ส่ง = คงเดิม
                if ($validated['payment_deadline'] ?? null) {
                    $locked->payment_deadline = Carbon::parse($validated['payment_deadline']);
                }

                // 📊 (05/10/26, excel-reports §2.1) booking attributes — admin เท่านั้น
                //    (route role:admin) · tag-only ไม่แตะยอดเงิน/state machine · ไม่ส่ง = คงเดิม
                //    (ส่ง null ชัด ๆ = เคลียร์)
                if ($request->has('invoice_requested_at')) {
                    $locked->invoice_requested_at = $validated['invoice_requested_at'] !== null
                        ? Carbon::parse($validated['invoice_requested_at'])
                        : null;
                }
                if ($request->has('special_request')) {
                    $locked->special_request = $validated['special_request'];
                }
                if ($request->has('comment')) {
                    $locked->comment = $validated['comment'];
                }
                if ($request->has('is_complimentary')) {
                    $locked->is_complimentary = (bool) $validated['is_complimentary'];
                }

                $locked->save();

                // 🎟️ discount_code — reuse กลไก DiscountService เดิม (reconcile total_amount ครบ)
                //    ส่งค่าว่าง/null = ลบโค้ด · ไม่ส่ง field = คงโค้ดเดิม
                if (array_key_exists('discount_code', $validated)) {
                    if (empty($validated['discount_code'])) {
                        if ($locked->discount_code) {
                            app(DiscountService::class)->removeFromDraft($locked);
                        }
                    } else {
                        app(DiscountService::class)->applyToDraft($locked, $validated['discount_code']);
                    }
                }

                // 🏛️ ticket 07 — reprice เฉพาะตอน draft: attach/ถอด user เปลี่ยนเรท daily_ku/daily
                //    ผ่าน chokepoint $booking->user ใน DiscountService::reprice() · หลัง draft ขึ้นไป
                //    = เขียน identity เท่านั้น ราคาคงเดิม (ไม่ไปยุ่งยอดที่สลิป/การชำระ lock ไว้)
                if ($identityChanged && $locked->status === 'draft') {
                    app(DiscountService::class)->reprice($locked);
                }

                DB::commit();

                $fresh = $locked->fresh(['bookingRooms.addon', 'confirmations.slipImage']);

                return response()->json([
                    'status' => 'success',
                    'message' => $touchesCustomer
                        ? 'อัปเดตข้อมูลการชำระเงิน/ผู้เข้าพักเรียบร้อยแล้วค่ะ 💳🏛️'
                        : 'แก้ไขข้อมูลการชำระเงินเรียบร้อยแล้วค่ะ 💳',
                    'booking' => $fresh,
                    'total_amount' => $fresh->total_amount,
                    'payment_type' => $fresh->payment_type,
                    'deposit_amount' => $fresh->deposit_amount,
                    'paid_amount' => $fresh->paid_amount,
                    'outstanding_amount' => $fresh->outstanding_amount,
                    'payment_deadline' => $fresh->payment_deadline?->toDateTimeString(),
                    // 🏛️ ticket 07 — identity หลัง attach/switch/detach
                    'user_id' => $fresh->user_id,
                    'organization_id' => $fresh->organization_id,
                    'customer_name' => $fresh->customer_name,
                    'customer_phone' => $fresh->customer_phone,
                    'customer_email' => $fresh->customer_email,
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $code = $e->getCode();
            if (in_array($code, [401, 403, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่พบรายการจองที่ระบุค่ะ',
                ], 404);
            }

            Log::error('Failed to update booking payment fields: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการแก้ไขข้อมูลการชำระเงิน กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    /**
     * 🏛️ (25/09/26, organization-bookings ticket 07) — guard แยก field-group ของ PUT /bookings/{id}
     *
     * 💳 payment group — draft เท่านั้น (หลังส่งสลิป/verify แล้ว frozen)
     * 🏛️ customer identity group — ได้ตั้งแต่ draft จนถึง checked_in (จนก่อน complete/no_show)
     *    — รองรับ front desk ที่เจอลูกค้าถึงเคาน์เตอร์แล้วค่อยรู้ว่าเป็นขององค์กรไหน
     */
    private function assertUpdatePaymentGroupGuards(string $status, bool $touchesPayment, bool $touchesCustomer): void
    {
        if ($touchesPayment && $status !== 'draft') {
            throw new \Exception('แก้ไขประเภทการชำระเงินได้เฉพาะการจองสถานะ draft เท่านั้นค่ะ (หลังส่งสลิป/ยืนยันแล้วแก้ไม่ได้) 📝', 422);
        }

        if ($touchesCustomer && in_array($status, ['complete', 'no_show'], true)) {
            throw new \Exception('แก้ไขข้อมูลผู้เข้าพัก/องค์กรได้จนถึง checked_in เท่านั้น — การจองสถานะ complete/no_show แก้ไม่ได้แล้วค่ะ 🏛️', 422);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            // 🌟 Refactor (25/06/26): booking container states เท่านั้น
            // (checked_in/checked_out/no_show อยู่ที่ BookingRoom)
            'status' => 'required|string|in:draft,paid,confirmed,complete',
        ]);

        try {
            $booking = Booking::findOrFail($id);
            $newStatus = $request->status;

            $user = $request->user('sanctum');
            $userRole = $user ? $user->role : 'guest';

            $booking->transitionStatus($newStatus, $userRole);

            $fresh = $booking->fresh();

            return response()->json([
                'status' => 'success',
                'message' => "อัปเดตสถานะเป็น {$newStatus} โดยคุณ {$userRole} เรียบร้อยแล้วค่ะ",
                'booking_id' => $booking->id,
                'booking_status' => $fresh->status,
                // 💳 (25/09/26) envelope 4 field — deferred อนุมัติผ่าน endpoint นี้ (draft → confirmed)
                'payment_type' => $fresh->payment_type,
                'deposit_amount' => $fresh->deposit_amount,
                'paid_amount' => $fresh->paid_amount,
                'outstanding_amount' => $fresh->outstanding_amount,
            ], 200);

        } catch (ModelNotFoundException $e) {
            $modelName = class_basename($e->getModel());

            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ",
            ], 404);

        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            Log::error('Failed to update booking status: '.$e->getMessage());

            // 🧾 (2026-11-11, api-route-hygiene): relay message เฉพาะ 4xx domain error
            //    (transitionStatus โยน 422) — 5xx ไม่ leak $e->getMessage() ออก client
            //    (คอนเวนชัน AGENTS.md: generic message + Log::error)
            $message = $statusCode >= 400 && $statusCode < 500
                ? $e->getMessage()
                : 'เกิดข้อผิดพลาดภายในระบบ โปรดลองอีกครั้งในภายหลังค่ะ';

            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], $statusCode);
        }
    }

    /**
     * 📝 Audit log (04/08/26): ประวัติการเปลี่ยนสถานะของ booking
     *
     * ดึง log ของ booking container + ทุก booking_room ที่อยู่ใต้ booking นี้
     * เรียงตามเวลา (เก่า → ใหม่) เพื่อให้เห็นลำดับเหตุการณ์เต็มๆ
     *
     * Route: GET /api/v1/bookings/{id}/status-logs (admin only)
     */
    public function statusLogs(string $id)
    {
        try {
            // โหลด booking + booking_rooms (เพื่อเอา BR ids สำหรับ query log)
            $booking = Booking::with('bookingRooms')->findOrFail($id);
            $brIds = $booking->bookingRooms->pluck('id');

            // เก็บ log ของ booking container + ทุก booking_room ที่อยู่ใต้ booking นี้
            $logs = StatusChangeLog::where(function ($q) use ($id, $brIds) {
                $q->where(function ($qq) use ($id) {
                    $qq->where('entity_type', 'booking')
                        ->where('entity_id', $id);
                })->orWhere(function ($qq) use ($brIds) {
                    $qq->where('entity_type', 'booking_room')
                        ->whereIn('entity_id', $brIds);
                });
            })
                ->orderBy('created_at', 'asc')
                ->get([
                    'id', 'entity_type', 'entity_id',
                    'from_status', 'to_status', 'role', 'causer_id',
                    'note', 'created_at',
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Status change logs retrieved',
                'booking_id' => $booking->id,
                'logs' => $logs,
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูล Booking ที่ระบุในระบบค่ะนายท่าน',
            ], 404);
        }
    }
}
