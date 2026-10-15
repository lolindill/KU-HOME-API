<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdditionalChargeRequest;
use App\Http\Requests\UpdateAdditionalChargeRequest;
use App\Models\AdditionalCharge;
use App\Models\Booking;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 💸 AdditionalChargeController — CRUD ค่าปรับ/ค่าเสียหาย/บริการเสริม (REQ-042/044 · ticket 12/13)
 *
 *    💳 (15/10/26 — ticket 13) ยอดบวกยอดจริงที่ booking แล้ว:
 *    - total_amount คงค่าห้องล้วน · Booking::charges_total = Σ charges ·
 *      outstanding/is_paid คิดจากยอดรวม (accessors ใน Booking) · recompute is_paid
 *      ผ่าน Booking::syncIsPaid() ทุกครั้งที่ charge เปลี่ยน (สร้าง/แก้/ลบ)
 *    - เก็บเงินหลัง charge ผ่าน recordPayment (front desk) เท่านั้น — ไม่มี endpoint ใหม่
 *    - บันทึกอิสระทุกเมื่อ ไม่ guard สถานะ (มติ 4a — staff คุมความถูกต้องเอง)
 *    - สิทธิ์ admin + staff (route + defense-in-depth ในทุก method)
 */
class AdditionalChargeController extends Controller
{
    /**
     * 🆕 (15/10/26, ticket 13) GET รายการต่อ booking — ปลดธรรมเนียม "ไม่มี GET อ่านผ่าน
     *     report อย่างเดียว" เพราะยอดเงินของ booking ตอนนี้ขึ้นกับ charges แล้ว หน้า front
     *     desk ต้องอ่านรายการ/ยอดรวมได้ทันที (จดเหตุผลใน cline.md)
     */
    public function index(Request $request, string $bookingId): JsonResponse
    {
        try {
            $user = $request->user('sanctum');

            if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ต้องเป็นแอดมินหรือพนักงานหน้าเคาน์เตอร์เท่านั้นค่ะ',
                ], 403);
            }

            $booking = Booking::findOrFail($bookingId);

            $charges = AdditionalCharge::with(['bookingRoom.room', 'recorder'])
                ->where('booking_id', $booking->id)
                ->orderBy('transaction_date')
                ->orderBy('created_at')
                ->get();

            return response()->json(array_merge([
                'status' => 'success',
                'message' => 'ดึงรายการค่าใช้จ่ายเรียบร้อยแล้วค่ะ',
                'additional_charges' => $charges,
            ], $this->moneySnapshot($booking)), 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบ booking ที่ระบุค่ะ',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to list additional charges: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการดึงรายการ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    public function store(StoreAdditionalChargeRequest $request): JsonResponse
    {
        try {
            $user = $request->user('sanctum');

            // 🛡️ defense-in-depth — route role:admin,staff อยู่แล้ว
            if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ต้องเป็นแอดมินหรือพนักงานหน้าเคาน์เตอร์เท่านั้นค่ะ',
                ], 403);
            }

            $validated = $request->validated();

            $charge = AdditionalCharge::create(array_merge($validated, [
                'qty' => $validated['qty'] ?? 1,
                'recorded_by' => $user->id,
            ]));

            // 💸 charge ทำยอดรวมโตเกิน paid → is_paid ต้องหลุดเป็น false ทันที
            $booking = Booking::findOrFail($validated['booking_id']);
            $booking->syncIsPaid();

            return response()->json(array_merge([
                'status' => 'success',
                'message' => 'บันทึกรายการค่าใช้จ่ายเรียบร้อยแล้วค่ะ 💸',
                'additional_charge' => $charge->fresh(['booking', 'bookingRoom.room', 'recorder']),
            ], $this->moneySnapshot($booking)), 201);
        } catch (\Exception $e) {
            Log::error('Failed to store additional charge: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการบันทึกรายการ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    public function update(UpdateAdditionalChargeRequest $request, string $id): JsonResponse
    {
        try {
            $user = $request->user('sanctum');

            if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ต้องเป็นแอดมินหรือพนักงานหน้าเคาน์เตอร์เท่านั้นค่ะ',
                ], 403);
            }

            $charge = AdditionalCharge::findOrFail($id);
            $previousBookingId = $charge->booking_id;

            $charge->update($request->validated());

            // 💸 recompute is_paid — booking_id เปลี่ยนได้ จึงคิดทั้ง booking เดิมและใหม่
            collect([$previousBookingId, $charge->booking_id])
                ->unique()
                ->each(fn (string $bookingId) => Booking::find($bookingId)?->syncIsPaid());

            $booking = Booking::findOrFail($charge->booking_id);

            return response()->json(array_merge([
                'status' => 'success',
                'message' => 'แก้ไขรายการเรียบร้อยแล้วค่ะ',
                'additional_charge' => $charge->fresh(['booking', 'bookingRoom.room', 'recorder']),
            ], $this->moneySnapshot($booking)), 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบรายการที่ระบุค่ะ',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to update additional charge: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการแก้ไขรายการ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $user = $request->user('sanctum');

            if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ต้องเป็นแอดมินหรือพนักงานหน้าเคาน์เตอร์เท่านั้นค่ะ',
                ], 403);
            }

            $charge = AdditionalCharge::findOrFail($id);
            $bookingId = $charge->booking_id;
            $charge->delete();

            // 💸 ลบ charge แล้ว paid อาจครบยอดใหม่ → recompute is_paid คืน true ได้
            $booking = Booking::findOrFail($bookingId);
            $booking->syncIsPaid();

            return response()->json(array_merge([
                'status' => 'success',
                'message' => 'ลบรายการเรียบร้อยแล้วค่ะ 🗑️',
                'additional_charge_id' => $id,
            ], $this->moneySnapshot($booking)), 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบรายการที่ระบุค่ะ',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to delete additional charge: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการลบรายการ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    /**
     * 📸 snapshot เงินของ booking หลัง mutation — ให้หน้างานอัปเดตรอบเดียว
     *    (รูปแบบคีย์ตรงกับ envelope ของ verify/recordPayment: booking_is_paid/paid_amount/...)
     */
    private function moneySnapshot(Booking $booking): array
    {
        $fresh = $booking->fresh();

        return [
            'booking_id' => $fresh->id,
            'total_amount' => $fresh->total_amount,
            'charges_total' => $fresh->charges_total,
            'paid_amount' => $fresh->paid_amount,
            'outstanding_amount' => $fresh->outstanding_amount,
            'booking_is_paid' => $fresh->is_paid,
            'booking_status' => $fresh->status,
        ];
    }
}
