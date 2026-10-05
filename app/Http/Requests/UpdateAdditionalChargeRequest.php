<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdditionalChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_id' => 'sometimes|required|uuid|exists:bookings,id',
            'transaction_date' => 'sometimes|required|date',
            'item_code' => 'nullable|string|max:100',
            'item_name' => 'sometimes|required|string|max:255',
            'qty' => 'nullable|integer|min:1',
            'unit' => 'nullable|string|max:50',
            'price' => 'sometimes|required|integer|min:0',
            'charge_type' => 'sometimes|required|in:damage,rental',
        ];
    }
}
