<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_code' => 'nullable|string|max:100',
            'item_name' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'quantity' => 'nullable|integer|min:0',
            'unit' => 'nullable|string|max:50',
            'reorder_point' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'reason' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'item_name.required' => 'กรุณาระบุชื่อสินค้าหรือวัสดุสิ้นเปลืองค่ะนายท่าน ✨',
            'quantity.integer' => 'จำนวนคงเหลือต้องเป็นตัวเลขจำนวนเต็มค่ะ',
            'quantity.min' => 'จำนวนคงเหลือต้องไม่ติดลบค่ะ',
            'reorder_point.integer' => 'จุดสั่งซื้อต้องเป็นตัวเลขจำนวนเต็มค่ะ',
            'reorder_point.min' => 'จุดสั่งซื้อต้องไม่ติดลบค่ะ',
            'max_stock.integer' => 'สต็อกสูงสุดต้องเป็นตัวเลขจำนวนเต็มค่ะ',
            'max_stock.min' => 'สต็อกสูงสุดต้องไม่ติดลบค่ะ',
        ];
    }
}
