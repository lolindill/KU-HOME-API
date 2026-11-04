<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGlobalRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rate_type' => 'sometimes|in:daily,daily_ku,group,month,addon',
            'room_type_id' => 'sometimes|nullable|uuid|exists:room_types,id',
            'code' => 'sometimes|nullable|string|max:255',
            'name_en' => 'sometimes|string|max:255',
            'name_th' => 'sometimes|nullable|string|max:255',
            'default_price' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
