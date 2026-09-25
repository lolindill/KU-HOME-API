<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 🏛️ Organization CRUD — admin only (wayfinder/organization-bookings ticket 01/05)
 *
 * ไม่มี DELETE ตาม precedent discounts — เลิกใช้องค์กร = PATCH .../toggle
 * (booking เก่าอ่าน snapshot ของตัวเอง จึงปิดใช้งานได้อิสระโดยไม่กระทบประวัติ)
 */
class OrganizationController extends Controller
{
    /**
     * 🔒 defense-in-depth — route มี role:admin คุมอยู่แล้ว แต่ re-check ใน controller ตาม layer rules
     */
    private function failIfNotAdmin(Request $request): void
    {
        $user = $request->user('sanctum');
        if (! $user || $user->role !== 'admin') {
            throw new \Exception('การจัดการองค์กรสำหรับแอดมินเท่านั้นค่ะนายท่าน 🔒', 403);
        }
    }

    private function errorResponse(\Exception $e): JsonResponse
    {
        // 🛡️ validation error ปล่อยให้ Laravel render 422 มาตรฐาน (errors per field)
        if ($e instanceof ValidationException) {
            throw $e;
        }

        $code = $e->getCode();
        if (in_array($code, [401, 403, 422], true)) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $code);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'ไม่พบข้อมูลองค์กรที่ระบุค่ะ 🔎',
        ], 404);
    }

    /**
     * 👥 Admin: รายการองค์กรทั้งหมด (search ค้น name / erp)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $this->failIfNotAdmin($request);

            $organizations = Organization::query()
                ->when($request->has('is_active'), function ($q) use ($request) {
                    $isActive = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($isActive !== null) {
                        $q->where('is_active', $isActive);
                    }
                })
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = trim((string) $request->query('search'));
                    $q->where(function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%")
                            ->orWhere('erp', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->get();

            return response()->json([
                'status' => 'success',
                'message' => 'ดึงข้อมูลองค์กรเรียบร้อยแล้วค่ะ ✨',
                'organizations' => $organizations,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * 👥 Admin: ดูรายละเอียดองค์กรรายตัว
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $this->failIfNotAdmin($request);

            $organization = Organization::findOrFail($id);

            return response()->json([
                'status' => 'success',
                'message' => 'ดึงข้อมูลองค์กรเรียบร้อยแล้วค่ะ ✨',
                'organization' => $organization,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * 👥 Admin: สร้างองค์กรใหม่
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $this->failIfNotAdmin($request);

            $validated = $request->validate([
                'erp' => [
                    'nullable',
                    'string',
                    'max:100',
                    function ($attribute, $value, $fail) {
                        // 🛡️ เช็คซ้ำแบบ trim ก่อนเทียบ (DB unique จับค่าที่ต่างกันด้วยช่องว่างไม่ได้)
                        if (Organization::where('erp', trim((string) $value))->exists()) {
                            $fail('รหัส ERP นี้ถูกใช้กับองค์กรอื่นแล้วค่ะ');
                        }
                    },
                ],
                'name' => 'required|string|max:255',
            ]);

            if (array_key_exists('erp', $validated) && $validated['erp'] !== null) {
                $validated['erp'] = trim($validated['erp']);
            }

            $organization = Organization::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'สร้างองค์กรเรียบร้อยแล้วค่ะ! ✨',
                'organization' => $organization->fresh(),
            ], 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * 👥 Admin: แก้ไของค์กร
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $this->failIfNotAdmin($request);

            $organization = Organization::findOrFail($id);

            $validated = $request->validate([
                'erp' => [
                    'sometimes',
                    'nullable',
                    'string',
                    'max:100',
                    function ($attribute, $value, $fail) use ($organization) {
                        $newErp = trim((string) $value);
                        if ($newErp === '' || $newErp === $organization->erp) {
                            return; // ส่งค่าเดิม/ค่าว่างกลับมา = no-op อนุญาต
                        }

                        if (Organization::where('erp', $newErp)->where('id', '!=', $organization->id)->exists()) {
                            $fail('รหัส ERP นี้ถูกใช้กับองค์กรอื่นแล้วค่ะ');
                        }
                    },
                ],
                'name' => 'sometimes|required|string|max:255',
            ]);

            if (array_key_exists('erp', $validated) && $validated['erp'] !== null) {
                $validated['erp'] = trim($validated['erp']);
            }

            $organization->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'แก้ไของค์กรเรียบร้อยแล้วค่ะ! ✨',
                'organization' => $organization->fresh(),
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * 👥 Admin: เปิด/ปิดการใช้งานองค์กร
     *
     * ปิดแล้ว = สร้าง booking ใหม่ที่อ้างองค์กรนี้โดน 422 (ticket 03/06)
     * แต่ booking เก่าไม่กระทบ (อ่าน snapshot ของตัวเอง)
     */
    public function toggle(Request $request, string $id): JsonResponse
    {
        try {
            $this->failIfNotAdmin($request);

            $organization = Organization::findOrFail($id);
            $organization->update(['is_active' => ! $organization->is_active]);

            return response()->json([
                'status' => 'success',
                'message' => 'เปลี่ยนสถานะองค์กรเรียบร้อยแล้วค่ะ! ✨',
                'organization' => $organization->fresh(),
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }
}
