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
            // 📊 (05/10/26) canonical — breakfast แยก 2 ชุด + extra-bed รายคืน (spec §2.2)
            'breakfast_set_100'   => 'nullable|integer|min:0',
            'breakfast_set_200'   => 'nullable|integer|min:0',
            'extra_beds_by_night' => 'nullable|array',
            'extra_beds_by_night.*' => 'nullable|integer|min:0',
            'early_checkIn_price' => 'nullable|integer|min:0',
            'late_checkOut_price' => 'nullable|integer|min:0',
            'extra_bed_price'     => 'nullable|integer|min:0',
            'breakfast_price'     => 'nullable|integer|min:0',
        ];
    }
}