<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_id' => 'sometimes|required|uuid|exists:bookings,id',
            'amount' => 'sometimes|required|numeric|min:0',
            // 🌟 Refactor (19/08/26): ลบ payment_method — flow เหลือสลิปอย่างเดียว
            // 🌟 Fix M4 (03/07/26): ลบ dead validation — status ถูก hardcode ใน controller (pending/completed)
            'reference_number' => 'nullable|string|max:255',
            'received_by' => 'nullable|uuid|exists:users,id',
        ];
    }
}
