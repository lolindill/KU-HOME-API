<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_id'    => 'required|uuid|exists:bookings,id',
            'room_type_id'  => 'required|uuid|exists:room_types,id',
            'room_id'       => 'nullable|uuid|exists:rooms,id',
        ];
    }
}