<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('user') ?? $this->route('id');

        return [
            'name'            => 'sometimes|required|string|max:255',
            'email'           => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password'        => 'sometimes|required|string|min:8',
            'title'           => 'nullable|string|max:255',
            'phone'           => 'nullable|string|max:255',
            'nationality'     => 'nullable|string|max:255',
            'role'            => 'nullable|string|in:user,admin',
            'is_ku_member'    => 'nullable|boolean',
            'ver'             => 'nullable|boolean',
        ];
    }
}