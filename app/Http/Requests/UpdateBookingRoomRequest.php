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
            'booking_id' => 'sometimes|required|uuid|exists:bookings,id',
            'room_type_id' => 'sometimes|required|uuid|exists:room_types,id',
            'room_id' => 'nullable|uuid|exists:rooms,id',

            // 🌟 Refactor (25/06/26): BR-level dates + guests (PATCHable)
            'check_in' => 'sometimes|required|date',
            'check_out' => 'sometimes|required|date|after:check_in',
            'guests' => 'nullable|array',
            'guests.*.title' => 'nullable|string|max:50',
            'guests.*.name' => 'nullable|string|max:255',
            'guests.*.nationality' => 'nullable|string|max:100',
            'guests.*.is_ku_member' => 'nullable|boolean',
            'has_children' => 'nullable|boolean',
            // 🧾 Billing fields (04/08/26)
            'billing_address' => 'nullable|string|max:255',
            'billing_comment' => 'nullable|string|max:255',
        ];
    }
}
