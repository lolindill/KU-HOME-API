<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source'             => 'sometimes|required|string|in:online,admin,line',
            'check_in'           => 'sometimes|required|date',
            'check_out'          => 'sometimes|required|date|after:check_in',

            // 🌟 Refactor (18/06/26): ข้อมูลผู้เข้าพักย้ายไป booking_rooms แล้ว (guests JSON + children)
            'total_amount'       => 'nullable|integer|min:0',
            'is_paid'            => 'nullable|boolean',
            'payment_deadline'   => 'nullable|date',
            'status'             => 'sometimes|required|string|in:draft,confirmed,checked_in,checked_out,cancelled,no_show,deleted',
        ];
    }
}