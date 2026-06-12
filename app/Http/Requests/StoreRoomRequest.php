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
            'room_type_id'       => 'required|uuid|exists:room_types,id',
            'room_number'        => 'required|string|max:255|unique:rooms,room_number',
            'status'             => 'nullable|string|in:available,occupied,maintenance,out_of_service',
            'builtin_extra_beds' => 'nullable|integer|min:0',
            'status_updated_at'  => 'nullable|date',
            'status_updated_by'  => 'nullable|uuid|exists:users,id',
        ];
    }
}