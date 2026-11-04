<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('user') ?? $this->route('id') ?? $this->user()?->id;

        // 🎫 split user: unique scope ตาม provider ของ row ที่กำลังแก้ — email ซ้ำข้าม provider ได้ (ticket 09)
        // กรณี /profile route ไม่มี param → fallback ใช้ user ที่ login อยู่
        $provider = $userId ? User::find($userId)?->auth_provider : 'password';

        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes', 'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')
                    ->where(fn ($query) => $query->where('auth_provider', $provider ?? 'password'))
                    ->ignore($userId),
            ],
            'password' => 'sometimes|required|string|min:8',
            'title' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:255',
            'role' => 'nullable|string|in:user,admin,staff,housekeeping,ku_member',
            'ver' => 'nullable|boolean',
        ];
    }
}
