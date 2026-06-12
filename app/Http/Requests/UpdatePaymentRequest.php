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
            'booking_id'        => 'sometimes|required|uuid|exists:bookings,id',
            'amount'            => 'sometimes|required|numeric|min:0',
            'payment_method'    => 'sometimes|required|string|in:cash,credit_card,transfer',
            'status'            => 'nullable|string|in:pending,completed,failed',
            'reference_number'  => 'nullable|string|max:255',
            'received_by'       => 'nullable|uuid|exists:users,id',
        ];
    }
}