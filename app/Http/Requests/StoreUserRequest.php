<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /*
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            // 🎫 split user: unique scope เฉพาะ auth_provider=password — email ที่มี SSO account อยู่แล้ว
            //    ยังสมัคร password account ได้ (composite unique ที่ DB กันให้เอง) — ticket 09
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->where(fn ($query) => $query->where('auth_provider', 'password')),
            ],
            'password' => 'required|string|min:8',
            'title' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:255',
            'role' => 'nullable|string|in:user,admin,staff,housekeeping,ku_member',
            'is_ku_member' => 'nullable|boolean',
            'ver' => 'nullable|boolean',
        ];
    }
}
