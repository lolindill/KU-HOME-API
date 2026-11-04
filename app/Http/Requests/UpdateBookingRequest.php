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
            'source' => 'sometimes|required|string|in:online,admin,line',

            // 🌟 Refactor (25/06/26): check_in/check_out ย้ายไป BR-level แล้ว — bookings เก็บแค่ container + payment info
            // 🌟 Refactor (18/06/26): ข้อมูลผู้เข้าพักย้ายไป booking_rooms แล้ว (guests JSON)
            'total_amount' => 'nullable|integer|min:0',
            'is_paid' => 'nullable|boolean',
            'payment_deadline' => 'nullable|date',

            // 🌟 Refactor (25/06/26): container states เท่านั้น
            // (checked_in/checked_out/no_show อยู่ที่ BookingRoom)
            'status' => 'sometimes|required|string|in:draft,paid,confirmed,complete',
        ];
    }
}
