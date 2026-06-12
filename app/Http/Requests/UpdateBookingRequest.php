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

            'guest_title'        => 'nullable|string|max:255',
            'guest_name'         => 'sometimes|required|string|max:255',
            'guest_email'        => 'sometimes|required|email|max:255',
            'guest_phone'        => 'sometimes|required|string|max:255',
            'guest_nationality'  => 'nullable|string|max:255',
            'is_ku_member'       => 'nullable|boolean|string',
            'children'           => 'nullable|integer|min:0',

            'total_amount'       => 'nullable|integer|min:0',
            'is_paid'            => 'nullable|boolean',
            'payment_deadline'   => 'nullable|date',
            'status'             => 'sometimes|required|string|in:draft,confirmed,checked_in,checked_out,cancelled,no_show,deleted',
        ];
    }
}