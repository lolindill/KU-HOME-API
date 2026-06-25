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

            // 🌟 Refactor (25/06/26): BR-level dates (แต่ละห้องต่างวันได้)
            'check_in'      => 'required|date',
            'check_out'     => 'required|date|after:check_in',

            // 👥 ข้อมูลผู้เข้าพัก (JSON array ของ guests แต่ละคน)
            'guests'                 => 'nullable|array',
            'guests.*.title'         => 'nullable|string|max:50',
            'guests.*.name'          => 'nullable|string|max:255',
            'guests.*.nationality'   => 'nullable|string|max:100',
            'guests.*.is_ku_member'  => 'nullable|boolean',
            'children'               => 'nullable|integer|min:0',
        ];
    }
}