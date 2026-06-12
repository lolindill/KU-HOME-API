<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHousekeepingTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id'       => 'required|uuid|exists:rooms,id',
            'assigned_to'   => 'nullable|uuid|exists:users,id',
            'status'        => 'nullable|string|in:pending,in_progress,done',
            'notes'         => 'nullable|string',
            'checked_out_at' => 'nullable|date',
            'completed_at'  => 'nullable|date',
        ];
    }
}