<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 🌟 Fix H4 (03/07/26): sometimes เพราะ front-desk payment รับ booking_id จาก URL param
            // (PaymentController::requestPayment ยังต้องการใน body — สองกรณีใช้ rule นี้ร่วมกันได้)
            'booking_id' => 'sometimes|uuid|exists:bookings,id',
            // 💳 (25/09/26, booking-payment-types ticket 05 หัวข้อ 7): min:1 — 0 ไม่ใช่เหตุการณ์เงิน
            'amount' => 'required|integer|min:1',
            // 🌟 Refactor (19/08/26): ลบ payment_method — flow เหลือสลิปอย่างเดียว
            // 🌟 Fix M4 (03/07/26): ลบ dead validation — status ถูก hardcode ใน controller ทั้งคู่ (pending/completed)
            'reference_number' => 'nullable|string|max:255',
            'received_by' => 'nullable|uuid|exists:users,id',
        ];
    }

    /**
     * 🌟 ข้อความแจ้งเตือนภาษาไทย
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'กรุณาระบุยอดเงินที่รับชำระ (บาท) ค่ะ 💳',
            'amount.integer' => 'ยอดเงินที่รับชำระต้องเป็นตัวเลขจำนวนเต็ม (บาท) ค่ะ 💳',
            'amount.min' => 'ยอดเงินที่รับชำระต้องมากกว่า 0 บาทค่ะ 💳',
        ];
    }
}
