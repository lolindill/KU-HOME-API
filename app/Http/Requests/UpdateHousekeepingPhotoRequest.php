<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHousekeepingPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id'    => 'sometimes|required|uuid|exists:housekeeping_tasks,id',
            'photo_path' => 'sometimes|required|string|max:500',
        ];
    }
}