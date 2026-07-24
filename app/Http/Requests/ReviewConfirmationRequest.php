<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🌟 Refactor (24/07/26): Admin review booking confirmation (verify/reject)
 *
 * review_note = เหตุผล (optional) — ใช้ส่วนใหญ่ตอน reject เพื่อบอก user ว่าทำไมสลิปไม่ผ่าน
 */
class ReviewConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'review_note' => 'nullable|string|max:500',
        ];
    }
}
