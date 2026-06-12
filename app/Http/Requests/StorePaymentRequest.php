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
            'booking_id'        => 'required|uuid|exists:bookings,id',
            'amount'            => 'required|integer|min:0',
            'payment_method'    => 'required|string|in:cash,credit_card,transfer',
            'status'            => 'nullable|string|in:pending,completed,failed',
            'reference_number'  => 'nullable|string|max:255',
            'received_by'       => 'nullable|uuid|exists:users,id',
        ];
    }
}