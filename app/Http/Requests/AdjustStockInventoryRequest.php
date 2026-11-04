<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delta' => 'required_without:quantity|nullable|integer',
            'quantity' => 'required_without:delta|nullable|integer|min:0',
            'reason' => 'required|string|max:255',
            'housekeeping_task_id' => 'nullable|uuid|exists:housekeeping_tasks,id',
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'delta.required_without' => 'กรุณาระบุจำนวนเปลี่ยนแปลง (delta) หรือจำนวนคงเหลือใหม่ (quantity) ค่ะ',
            'quantity.required_without' => 'กรุณาระบุจำนวนเปลี่ยนแปลง (delta) หรือจำนวนคงเหลือใหม่ (quantity) ค่ะ',
            'delta.integer' => 'จำนวนเปลี่ยนแปลง (delta) ต้องเป็นตัวเลขจำนวนเต็มค่ะ',
            'quantity.integer' => 'จำนวนคงเหลือใหม่ (quantity) ต้องเป็นตัวเลขจำนวนเต็มค่ะ',
            'quantity.min' => 'จำนวนคงเหลือใหม่ต้องไม่ติดลบค่ะ',
            'reason.required' => 'กรุณาระบุเหตุผลในการปรับสต็อกสินค้าค่ะ (เช่น restock, damage, used, audit)',
            'housekeeping_task_id.exists' => 'ไม่พบงานแม่บ้านที่ระบุค่ะ',
        ];
    }
}
