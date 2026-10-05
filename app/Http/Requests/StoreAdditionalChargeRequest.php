<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdditionalChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // สิทธิ์ admin+staff ที่ route (spec §2.6 — default §9 รอ sign-off)
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_id' => 'required|uuid|exists:bookings,id',
            'transaction_date' => 'required|date',
            // รหัสรายการ — free text (ชีต sample เป็น placeholder "xxxx")
            'item_code' => 'nullable|string|max:100',
            'item_name' => 'required|string|max:255',
            'qty' => 'nullable|integer|min:1',
            'unit' => 'nullable|string|max:50',
            // money = integer บาท (convention 2026-09-11) — ราคารวมของรายการ
            'price' => 'required|integer|min:0',
            'charge_type' => 'required|in:damage,rental',
        ];
    }

    public function messages(): array
    {
        return [
            'booking_id.required' => 'กรุณาระบุ booking ที่ผูกค่าใช้จ่ายค่ะ',
            'booking_id.exists' => 'ไม่พบ booking ที่ระบุค่ะ',
            'transaction_date.required' => 'กรุณาระบุวันที่ทำรายการค่ะ',
            'item_name.required' => 'กรุณาระบุชื่อรายการ (เช่น "ค่าเสียหาย ปลอกหมอก") ค่ะ',
            'price.required' => 'กรุณาระบุราคา (integer บาท) ค่ะ',
            'price.integer' => 'ราคาต้องเป็นตัวเลขจำนวนเต็ม (บาท) ค่ะ',
            'charge_type.in' => 'charge_type ต้องเป็น damage (ค่าเสียหาย) หรือ rental (ค่ายืม) เท่านั้นค่ะ',
        ];
    }
}
