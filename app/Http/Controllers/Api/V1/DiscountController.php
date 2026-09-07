<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DiscountController extends Controller
{
    /**
     * 🎟️ Preview โค้ดส่วนลด (ไม่มี side-effect ไม่จับ slot)
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'check_in' => 'nullable|date',
            'check_out' => 'nullable|date|after:check_in',
        ]);

        $code = strtoupper(trim($request->code));
        $discount = Discount::where('code', $code)->first();

        if (! $discount) {
            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบโค้ดส่วนลด {$code} ในระบบค่ะ 🔎",
            ], 422);
        }

        if (! $discount->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => "โค้ด {$discount->code} ถูกปิดใช้งานแล้วค่ะ 🙅♀️",
            ], 422);
        }

        $now = Carbon::now();
        if ($discount->usable_from && $now->lt($discount->usable_from)) {
            return response()->json([
                'status' => 'error',
                'message' => "โค้ด {$discount->code} ยังไม่ถึงช่วงเวลาใช้งานค่ะ (ใช้ได้ตั้งแต่ {$discount->usable_from->format('Y-m-d H:i')})",
            ], 422);
        }

        if ($discount->usable_until && $now->gt($discount->usable_until)) {
            return response()->json([
                'status' => 'error',
                'message' => "โค้ด {$discount->code} หมดเขตใช้งานแล้วค่ะ (ใช้ได้ถึง {$discount->usable_until->format('Y-m-d H:i')})",
            ], 422);
        }

        if ($request->filled('check_in') && $request->filled('check_out')) {
            $checkIn = Carbon::parse($request->check_in);
            $checkOut = Carbon::parse($request->check_out);

            if ($discount->stay_from !== null && $discount->stay_until !== null) {
                if ($checkIn->lt($discount->stay_from) || $checkOut->gt($discount->stay_until)) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "ช่วงเข้าพักไม่อยู่ในเขตของโค้ดค่ะ (พักได้ {$discount->stay_from->format('Y-m-d')} ถึง {$discount->stay_until->format('Y-m-d')}) 📅",
                    ], 422);
                }
            }
        }

        $globalUsed = DiscountRedemption::where('discount_id', $discount->id)->count();
        $user = $request->user('sanctum');
        $userUsed = $user
            ? DiscountRedemption::where('discount_id', $discount->id)->where('user_id', $user->id)->count()
            : 0;

        $globalMax = $discount->max_uses;
        $perUserMax = $discount->max_uses_per_user;

        $globalRemaining = $globalMax !== null ? max(0, $globalMax - $globalUsed) : null;
        $perUserRemaining = $perUserMax !== null ? max(0, $perUserMax - $userUsed) : null;

        if ($globalMax !== null && $globalUsed >= $globalMax) {
            return response()->json([
                'status' => 'error',
                'message' => "โค้ด {$discount->code} ถูกใช้ครบโควตาแล้วค่ะ",
            ], 422);
        }

        if ($perUserMax !== null && $userUsed >= $perUserMax) {
            return response()->json([
                'status' => 'error',
                'message' => "นายท่านใช้โค้ด {$discount->code} ครบโควตาต่อคนแล้วค่ะ ({$perUserMax} ห้อง)",
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'โค้ดใช้งานได้ค่ะนายท่าน! ✨',
            'discount' => $discount,
            'quota' => [
                'global_used' => $globalUsed,
                'global_max' => $globalMax,
                'global_remaining' => $globalRemaining,
                'per_user_used' => $userUsed,
                'per_user_max' => $perUserMax,
                'per_user_remaining' => $perUserRemaining,
            ],
        ], 200);
    }

    /**
     * 👥 Admin: แสดงรายการส่วนลดทั้งหมด
     */
    public function index(Request $request): JsonResponse
    {
        $discounts = Discount::query()
            ->when($request->has('is_active'), function ($q) use ($request) {
                $isActive = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive !== null) {
                    $q->where('is_active', $isActive);
                }
            })
            ->when($request->filled('code'), function ($q) use ($request) {
                $code = strtoupper(trim((string) $request->query('code')));
                $q->where('code', $code);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = strtoupper(trim((string) $request->query('search')));
                $q->where('code', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'ดึงข้อมูลโค้ดส่วนลดเรียบร้อยแล้วค่ะ ✨',
            'discounts' => $discounts,
        ], 200);
    }

    /**
     * 👥 Admin: แสดงรายละเอียดโค้ดส่วนลด (ค้นหาด้วย Code Name หรือ UUID)
     */
    public function show(string $code): JsonResponse
    {
        $code = trim($code);
        $discount = Str::isUuid($code)
            ? Discount::where('id', $code)->orWhere('code', strtoupper($code))->first()
            : Discount::where('code', strtoupper($code))->first();

        if (! $discount) {
            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบโค้ดส่วนลด {$code} ค่ะ 🔎",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'ดึงข้อมูลโค้ดส่วนลดเรียบร้อยแล้วค่ะ ✨',
            'discount' => $discount,
        ], 200);
    }

    /**
     * 👥 Admin: สร้างโค้ดส่วนลดใหม่
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                function ($attribute, $value, $fail) {
                    // 🛡️ (27/08/26): เช็คซ้ำแบบ case-insensitive (mutator บังคับ UPPERCASE ตอนบันทึก)
                    //   DB unique rule จับไม่ได้ถ้า client ส่งต่าง case แล้วกลายเป็น QueryException 500
                    if (Discount::where('code', strtoupper(trim((string) $value)))->exists()) {
                        $fail('รหัสโค้ดส่วนลดนี้ถูกใช้แล้วค่ะ');
                    }
                },
            ],
            'type' => 'required|in:percent,fixed,set_room_price',
            'value' => [
                'required',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->input('type') === 'percent' && ($value < 1 || $value > 100)) {
                        $fail('สำหรับประเภท percent ค่าส่วนลดต้องอยู่ระหว่าง 1 ถึง 100 ค่ะ');
                    }
                },
            ],
            'room_type_ids' => 'nullable|array',
            'room_type_ids.*' => 'uuid|exists:room_types,id|distinct',
            'usable_from' => 'nullable|date',
            'usable_until' => 'nullable|date|after:usable_from',
            // 🛡️ (27/08/26): บังคับมาเป็นคู่เสมอ — isEligible() ตีความ half-set window เป็น "ไม่ eligible"
            'stay_from' => 'nullable|date|required_with:stay_until',
            'stay_until' => 'nullable|date|after_or_equal:stay_from|required_with:stay_from',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_user' => 'nullable|integer|min:1',
        ]);

        $discount = Discount::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'สร้างโค้ดส่วนลดเรียบร้อยแล้วค่ะ! ✨',
            'discount' => $discount->fresh(),
        ], 201);
    }

    /**
     * 👥 Admin: แก้ไขโค้ดส่วนลด
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $discount = Discount::findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                function ($attribute, $value, $fail) use ($discount) {
                    $newCode = strtoupper(trim((string) $value));
                    if ($newCode === $discount->code) {
                        return; // ส่งชื่อเดิมกลับมา = no-op อนุญาต
                    }

                    // 🛡️ (27/08/26): ห้าม rename ถ้ามี redemption ผูกอยู่ — bookings.discount_code เป็น
                    //   string snapshot reprice() จะหาโค้ดไม่เจอ → hold ค้างแต่ส่วนลดหาย (draft พัง)
                    if ($discount->redemptions()->exists()) {
                        $fail('ไม่สามารถเปลี่ยนรหัสโค้ดได้ เนื่องจากมีการจองที่ถือสิทธิ์โค้ดนี้อยู่ค่ะ (ใช้ PATCH /discounts/{id}/toggle เพื่อปิดใช้งานแทน)');
                    }

                    // 🛡️ เช็คซ้ำแบบ case-insensitive (DB unique rule จับต่าง case ไม่ได้)
                    if (Discount::where('code', $newCode)->where('id', '!=', $discount->id)->exists()) {
                        $fail('รหัสโค้ดส่วนลดนี้ถูกใช้แล้วค่ะ');
                    }
                },
            ],
            'type' => 'sometimes|required|in:percent,fixed,set_room_price',
            'value' => [
                'required_with:type',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) use ($request, $discount) {
                    $effectiveType = $request->input('type', $discount->type);
                    if ($effectiveType === 'percent' && ($value < 1 || $value > 100)) {
                        $fail('สำหรับประเภท percent ค่าส่วนลดต้องอยู่ระหว่าง 1 ถึง 100 ค่ะ');
                    }
                },
            ],
            'room_type_ids' => 'nullable|array',
            'room_type_ids.*' => 'uuid|exists:room_types,id|distinct',
            'usable_from' => 'nullable|date',
            'usable_until' => 'nullable|date|after:usable_from',
            // 🛡️ (27/08/26): บังคับมาเป็นคู่เสมอ — isEligible() ตีความ half-set window เป็น "ไม่ eligible"
            'stay_from' => 'nullable|date|required_with:stay_until',
            'stay_until' => 'nullable|date|after_or_equal:stay_from|required_with:stay_from',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_user' => 'nullable|integer|min:1',
        ], [
            'value.required_with' => 'เปลี่ยนประเภทส่วนลดต้องส่ง value มาพร้อมกันเสมอค่ะ',
        ]);

        $discount->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'แก้ไขโค้ดส่วนลดเรียบร้อยแล้วค่ะ! ✨',
            'discount' => $discount->fresh(),
        ], 200);
    }

    /**
     * 👥 Admin: เปิด/ปิดการใช้งานโค้ดส่วนลด
     */
    public function toggleActive(Request $request, string $id): JsonResponse
    {
        $discount = Discount::findOrFail($id);
        $discount->update(['is_active' => ! $discount->is_active]);

        return response()->json([
            'status' => 'success',
            'message' => 'เปลี่ยนสถานะโค้ดส่วนลดเรียบร้อยแล้วค่ะ! ✨',
            'discount' => $discount->fresh(),
        ], 200);
    }
}
