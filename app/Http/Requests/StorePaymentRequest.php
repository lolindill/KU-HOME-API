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
            'booking_id'        => 'sometimes|uuid|exists:bookings,id',
            'amount'            => 'required|integer|min:0',
            'payment_method'    => 'required|string|in:cash,credit_card,transfer',
            // 🌟 Fix M4 (03/07/26): ลบ dead validation — status ถูก hardcode ใน controller ทั้งคู่ (pending/completed)
            'reference_number'  => 'nullable|string|max:255',
            'received_by'       => 'nullable|uuid|exists:users,id',
        ];
    }
}