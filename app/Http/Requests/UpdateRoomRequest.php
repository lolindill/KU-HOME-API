<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roomId = $this->route('room') ?? $this->route('id');

        return [
            'room_type_id'       => 'sometimes|required|uuid|exists:room_types,id',
            'room_number'        => ['sometimes', 'required', 'string', 'max:255', Rule::unique('rooms', 'room_number')->ignore($roomId)],
            'status'             => 'nullable|string|in:available,prep_checkIn,Occupied,checkout_makeup,maintenance,reserved_closed,dirty',
            'builtin_extra_beds' => 'nullable|integer|min:0',
            'status_updated_at'  => 'nullable|date',
            'status_updated_by'  => 'nullable|uuid|exists:users,id',
        ];
    }
}