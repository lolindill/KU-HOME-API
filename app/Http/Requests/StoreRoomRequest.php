<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_type_id' => 'required|uuid|exists:room_types,id',
            'room_number' => 'required|string|max:255|unique:rooms,room_number',
            // 🗓️ (24/09/26) room-state-periods: สถานะ reserved_closed/maintenance ถูกถอด —
            //    "ห้องสำรอง/ซ่อมแซม" จัดการผ่าน /rooms/{id}/periods (ช่วงเวลา)
            'status' => 'nullable|string|in:available,occupied,dirty,prep_checkin,checkout_makeup',
            'builtin_extra_beds' => 'nullable|integer|min:0',
            'status_updated_at' => 'nullable|date',
            'status_updated_by' => 'nullable|uuid|exists:users,id',
        ];
    }
}
