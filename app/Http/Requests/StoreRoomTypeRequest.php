<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoomTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en'             => 'required|string|max:255',
            'name_th'             => 'required|string|max:255',
            'max_guests'          => 'required|integer|min:1',
            'extra_bed_enabled'   => 'nullable|boolean',
            'max_extra_beds'      => 'nullable|integer|min:0',
            'extra_bed_price'     => 'nullable|integer|min:0',
            'rate_daily_general'  => 'required|integer|min:0',
        ];
    }
}