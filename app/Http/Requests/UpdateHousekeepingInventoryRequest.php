<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHousekeepingInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'task_id'          => 'sometimes|required|uuid|exists:housekeeping_tasks,id',
            'item_name'        => 'sometimes|required|string|max:255',
            'actual_quantity'  => 'sometimes|required|integer|min:0',
            'condition'        => 'nullable|string|in:good,damaged,missing',
            'notes'            => 'nullable|string|max:1000',
        ];
    }
}