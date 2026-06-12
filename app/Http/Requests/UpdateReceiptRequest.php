<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $receiptId = $this->route('receipt') ?? $this->route('id');

        return [
            'receipt_no'      => ['sometimes', 'required', 'string', 'max:255', Rule::unique('receipts', 'receipt_no')->ignore($receiptId)],
            'booking_id'      => 'sometimes|required|uuid|exists:bookings,id',
            'payment_id'      => 'sometimes|required|uuid|exists:payments,id',
            'amount'          => 'sometimes|required|numeric|min:0',
            'billing_name'    => 'nullable|string|max:255',
            'billing_address' => 'nullable|string',
            'issued_at'       => 'nullable|date',
        ];
    }
}