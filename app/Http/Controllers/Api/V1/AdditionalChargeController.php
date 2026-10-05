<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdditionalChargeRequest;
use App\Http\Requests\UpdateAdditionalChargeRequest;
use App\Models\AdditionalCharge;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 💸 AdditionalChargeController — CRUD ledger ค่าเสียหาย/ค่ายืม (excel-reports spec §2.6)
 *
 *    ⚠️ **LEDGER รายงานล้วน — ยอดไม่เข้า booking**:
 *    - invariant Σ booking_rooms.amount == total_amount + ชั้น A/B ของแมป booking-payment-types
 *      ไม่ถูกแตะ · ไม่ไหลเข้า payments (ledger นั้นนิยาม "เงินของ booking")
 *    - บันทึกอิสระทุกเมื่อ ไม่ผูก flow checked_out
 *    - สิทธิ์ admin + staff (spec §2.6 — default รอ sign-off §9 · ถ้า owner จำกัด admin
 *      เท่านั้น แก้สิทธิ์จุดเดียวที่ routes/api.php)
 */
class AdditionalChargeController extends Controller
{
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

            return response()->json([
                'status' => 'success',
                'message' => 'บันทึกรายการค่าใช้จ่ายเรียบร้อยแล้วค่ะ 💸',
                'additional_charge' => $charge->fresh(['booking', 'recorder']),
            ], 201);
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
            $charge->update($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'แก้ไขรายการเรียบร้อยแล้วค่ะ',
                'additional_charge' => $charge->fresh(['booking', 'recorder']),
            ], 200);
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
            $charge->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'ลบรายการเรียบร้อยแล้วค่ะ 🗑️',
                'additional_charge_id' => $id,
            ], 200);
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
}
