<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_room_id'     => 'sometimes|required|uuid|exists:booking_rooms,id',
            'extra_bed'           => 'nullable|integer|min:0',
            'breakfast'           => 'nullable|integer|min:0',
            'early_checkIn_price' => 'nullable|integer|min:0',
            'late_checkOut_price' => 'nullable|integer|min:0',
            'extra_bed_price'     => 'nullable|integer|min:0',
            'breakfast_price'     => 'nullable|integer|min:0',
        ];
    }
}