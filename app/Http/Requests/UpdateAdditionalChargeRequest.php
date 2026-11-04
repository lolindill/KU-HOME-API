<?php

namespace App\Http\Requests;

use App\Models\AdditionalCharge;
use App\Models\BookingRoom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdditionalChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_id' => 'sometimes|required|uuid|exists:bookings,id',
            // 🛏️ (15/10/26, ticket 13) ผูกห้อง (nullable) — ต้องเป็นลูกของ booking_id
            //    (booking_id ไม่ส่งมาด้วย = ใช้ booking_id ปัจจุบันของ charge — 422 ถ้าไม่ใช่ลูก)
            'booking_room_id' => [
                'nullable',
                'uuid',
                Rule::exists('booking_rooms', 'id'),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === null) {
                        return;
                    }

                    $bookingId = $this->input('booking_id')
                        ?? AdditionalCharge::find($this->route('id'))?->booking_id;

                    if ($bookingId && ! BookingRoom::where('id', $value)->where('booking_id', $bookingId)->exists()) {
                        $fail('booking_room_id ที่ระบุไม่ใช่ห้องพักของ booking นี้ค่ะ');
                    }
                },
            ],
            'transaction_date' => 'sometimes|required|date',
            'item_code' => 'nullable|string|max:100',
            'item_name' => 'sometimes|required|string|max:255',
            'qty' => 'nullable|integer|min:1',
            'unit' => 'nullable|string|max:50',
            'price' => 'sometimes|required|integer|min:0',
            'charge_type' => 'sometimes|required|in:damage,rental,addon',
        ];
    }
}
