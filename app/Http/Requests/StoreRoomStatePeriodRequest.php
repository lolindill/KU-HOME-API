<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomStatePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        // auth:sanctum + role:admin,staff อยู่ที่ route · guard แยก kind (reserved = admin เท่านั้น)
        // ทำใน controller (defense-in-depth ตาม AGENTS.md)
        return true;
    }

    public function rules(): array
    {
        return [
            // kind = attribute ตัวเดียวใน body — เพิ่ม kind ใหม่ไม่ต้องแตะ route (ticket 04)
            'kind' => 'required|string|in:reserved,maintenance',
            // ย้อนอดีตได้ ("ท่อระเบิดคืนวาน admin มาบันทึกเช้านี้" — ticket 04)
            'start_date' => 'required|date',
            // เปิดปลายได้ทั้งสอง kind (null — ticket 05) · เมื่อใส่ต้อง >= วันนี้
            // (ห้าม period หมดแล้วทั้งช่วง) และ > start_date · end เป็น exclusive เหมือน check_out —
            // วัน end คือวันที่ห้องกลับมาขาย
            'end_date' => 'nullable|date|after_or_equal:today|after:start_date',
        ];
    }
}
