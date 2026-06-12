<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source'             => 'required|string|in:online,admin,line',
            'check_in'           => 'required|date|after_or_equal:today',
            'check_out'          => 'required|date|after:check_in',

            'guest_title'        => 'nullable|string|max:255',
            'guest_name'         => 'required|string|max:255',
            'guest_email'        => 'required|email|max:255',
            'guest_phone'        => 'required|string|max:255',
            'guest_nationality'  => 'nullable|string|max:255',
            'is_ku_member'       => 'nullable|boolean|string',
            'children'           => 'nullable|integer|min:0',


            'booking_rooms'               => 'required|array',
            'booking_rooms.*.room_type_id' => 'required|uuid|exists:room_types,id',
            'booking_rooms.*.quantity'     => 'required|integer|min:1',
            'booking_rooms.*.extra_beds'   => 'nullable|integer|min:0',

            'booking_rooms.*.addons'                    => 'nullable|array',
            'booking_rooms.*.addons.breakfast'           => 'nullable|integer|min:0',
            'booking_rooms.*.addons.breakfast_price'     => 'nullable|integer|min:0',
            'booking_rooms.*.addons.early_checkIn_price' => 'nullable|integer|min:0',
            'booking_rooms.*.addons.late_checkOut_price' => 'nullable|integer|min:0',
        ];
    }
}