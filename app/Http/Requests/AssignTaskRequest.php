<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // admin assign housekeeper ให้ task (skip accepted — เซ็ต status=accepted ตรงๆ)
            'assigned_to' => 'required|uuid|exists:users,id',
        ];
    }
}
