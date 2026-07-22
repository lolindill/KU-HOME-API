<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GlobalRate;
use Illuminate\Http\Request;

/**
 * 🌟 Refactor (22/07/26): GlobalRateController (renamed from AddonRateController)
 *
 * จัดการ CRUD สำหรับ global_rates ซึ่งเก็บทั้ง room rate (daily/group/month)
 * และ addon rate (breakfast, extra_bed, ...) ในตารางเดียวกัน
 */
class GlobalRateController extends Controller
{
    /**
     * ดึงรายการ rates ทั้งหมด
     * รองรับ filter ผ่าน query param: ?rate_type= และ ?room_type_id=
     */
    public function index(Request $request)
    {
        $rateType = $request->query('rate_type');
        $roomTypeId = $request->query('room_type_id');

        $rates = GlobalRate::query()
            ->when($rateType, fn($q) => $q->where('rate_type', $rateType))
            ->when($roomTypeId, fn($q) => $q->where('room_type_id', $roomTypeId))
            ->orderBy('rate_type')
            ->orderBy('code')
            ->orderBy('name_en')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'ดึงรายการ Global Rates เรียบร้อยแล้วค่ะ! ✨',
            'rates' => $rates,
        ], 200);
    }

    public function show(string $id)
    {
        $rate = GlobalRate::findOrFail($id);

        return response()->json([
            'status' => 'success',
            'message' => 'ดึงข้อมูล Global Rate เรียบร้อยแล้วค่ะ! ✨',
            'rate' => $rate,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'rate_type'     => 'sometimes|in:daily,group,month,addon',
            'room_type_id'  => 'sometimes|nullable|uuid|exists:room_types,id',
            'code'          => 'sometimes|nullable|string|max:255',
            'name_en'       => 'sometimes|string|max:255',
            'name_th'       => 'sometimes|nullable|string|max:255',
            'default_price' => 'sometimes|integer|min:0',
            'is_active'     => 'sometimes|boolean',
        ]);

        $rate = GlobalRate::findOrFail($id);

        // 🌟 Validation: rate_type กับ code/room_type_id ต้องสอดคล้องกัน
        // addon → ต้องมี code, ห้ามมี room_type_id
        // daily/group/month → ต้องมี room_type_id, code ไม่ใช้ (null)
        $effectiveRateType = $validated['rate_type'] ?? $rate->rate_type;
        if ($effectiveRateType === 'addon') {
            $validated['room_type_id'] = null;
        } else {
            $validated['code'] = null;
        }

        $rate->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'อัปเดต Global Rate เรียบร้อยแล้วค่ะ! ✨',
            'rate' => $rate->fresh(),
        ], 200);
    }

    public function toggleActive(string $id)
    {
        $rate = GlobalRate::findOrFail($id);
        $rate->update(['is_active' => !$rate->is_active]);

        $state = $rate->is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

        return response()->json([
            'status' => 'success',
            'message' => "{$state} Global Rate เรียบร้อยแล้วค่ะ! ✨",
            'rate' => $rate->fresh(),
        ], 200);
    }
}
