<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoomStatePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        // auth:sanctum + role:admin,staff อยู่ที่ route · guard แยก kind ใน controller
        return true;
    }

    public function rules(): array
    {
        return [
            // PATCH แก้ได้เฉพาะช่วงวันที่ — ทุก PATCH re-run กฎครบชุดใน service (ticket 04)
            'start_date' => 'sometimes|date',
            // absent = คงเดิม · explicit null = ปิดปลาย row เดิมให้เป็นเปิดปลาย (ticket 05) ·
            // ใส่ค่า = ต้อง >= วันนี้ (ห้าม period หมดทั้งช่วง)
            'end_date' => 'sometimes|nullable|date|after_or_equal:today',
            // kind immutable — เปลี่ยนใจ = ลบสร้างใหม่ (ticket 04)
            'kind' => 'prohibited',
        ];
    }
}
