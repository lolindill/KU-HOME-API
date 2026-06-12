<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipt_no'      => 'required|string|max:255|unique:receipts,receipt_no',
            'booking_id'      => 'required|uuid|exists:bookings,id',
            'payment_id'      => 'required|uuid|exists:payments,id',
            'amount'          => 'required|numeric|min:0',
            'billing_name'    => 'nullable|string|max:255',
            'billing_address' => 'nullable|string',
            'issued_at'       => 'nullable|date',
        ];
    }
}