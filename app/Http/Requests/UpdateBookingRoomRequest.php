<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_id'    => 'sometimes|required|uuid|exists:bookings,id',
            'room_type_id'  => 'sometimes|required|uuid|exists:room_types,id',
            'room_id'       => 'nullable|uuid|exists:rooms,id',
        ];
    }
}