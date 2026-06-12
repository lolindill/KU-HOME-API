<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHousekeepingPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id'    => 'required|uuid|exists:housekeeping_tasks,id',
            'photo_path' => 'required|string|max:500',
        ];
    }
}