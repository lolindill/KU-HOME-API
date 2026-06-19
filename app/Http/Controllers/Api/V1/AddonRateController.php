<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AddonRate;
use Illuminate\Http\Request;

class AddonRateController extends Controller
{
    public function index()
    {
        $rates = AddonRate::orderBy('code')->get();

        return response()->json([
            'status' => 'success',
            'message' => 'ดึงรายการ Add-on Rates เรียบร้อยแล้วค่ะ! ✨',
            'rates' => $rates,
        ], 200);
    }

    public function show(string $id)
    {
        $rate = AddonRate::findOrFail($id);

        return response()->json([
            'status' => 'success',
            'message' => 'ดึงข้อมูล Add-on Rate เรียบร้อยแล้วค่ะ! ✨',
            'rate' => $rate,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'name_en'       => 'sometimes|string|max:255',
            'name_th'       => 'sometimes|nullable|string|max:255',
            'default_price' => 'sometimes|integer|min:0',
            'is_active'     => 'sometimes|boolean',
        ]);

        $rate = AddonRate::findOrFail($id);
        $rate->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'อัปเดต Add-on Rate เรียบร้อยแล้วค่ะ! ✨',
            'rate' => $rate->fresh(),
        ], 200);
    }

    public function toggleActive(string $id)
    {
        $rate = AddonRate::findOrFail($id);
        $rate->update(['is_active' => !$rate->is_active]);

        $state = $rate->is_active ? 'เปิดใช้งาน' : 'ปิดใช้งาน';

        return response()->json([
            'status' => 'success',
            'message' => "{$state} Add-on Rate เรียบร้อยแล้วค่ะ! ✨",
            'rate' => $rate->fresh(),
        ], 200);
    }
}