<?php

namespace App\Http\Requests;

use App\Models\RoomType;
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
            'booking_id' => 'required|uuid|exists:bookings,id',
            'room_type_id' => 'required|uuid|exists:room_types,id',
            'room_id' => 'nullable|uuid|exists:rooms,id',

            // 🌟 Refactor (25/06/26): BR-level dates (แต่ละห้องต่างวันได้)
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',

            // 👥 ข้อมูลผู้เข้าพัก (JSON array ของ guests แต่ละคน)
            'guests' => 'nullable|array',
            'guests.*.title' => 'nullable|string|max:50',
            'guests.*.firstName' => 'nullable|string|max:255',
            'guests.*.lastName' => 'nullable|string|max:255',
            'guests.*.first_name' => 'nullable|string|max:255',
            'guests.*.last_name' => 'nullable|string|max:255',
            'guests.*.name' => 'nullable|string|max:255',
            'guests.*.email' => 'nullable|string|email|max:255',
            'guests.*.phone' => 'nullable|string|max:50',
            'guests.*.nationality' => 'nullable|string|max:100',
            // 🧾 Billing fields (04/08/26)
            'billing_address' => 'nullable|string|max:255',
            'billing_comment' => 'nullable|string|max:255',
        ];
    }

    /**
     * 👥 ชั้น 1 (REQ-007/019): ตรวจจำนวน guests ไม่เกิน max_guests ของประเภทห้อง
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('guests') && is_array($this->guests) && $this->filled('room_type_id')) {
                $roomType = RoomType::find($this->room_type_id);
                if ($roomType && count($this->guests) > $roomType->max_guests) {
                    $validator->errors()->add('guests', "ผู้เข้าพักเกินจำนวนสูงสุด {$roomType->max_guests} คนของห้องประเภทนี้ค่ะ 🥺");
                }
            }
        });
    }
}
