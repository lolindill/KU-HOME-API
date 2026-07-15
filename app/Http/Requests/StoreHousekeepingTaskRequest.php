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
            'room_id' => 'required|uuid|exists:rooms,id',
            'task_type' => 'nullable|string|in:pre_checkin,checkout,checkout_then_in,daily,monthly,group',
            'notes' => 'nullable|string',
            'scheduled_for' => 'nullable|date',
        ];
    }
}
