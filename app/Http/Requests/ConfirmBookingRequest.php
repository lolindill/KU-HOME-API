<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🌟 Refactor (19/08/26): User ส่งหลักฐานการชำระเงิน (slip + time)
 *
 * booking_id รับจาก URL param (เหมือน StorePaymentRequest ใน front-desk)
 * - slip_image: บังคับเสมอ — flow เหลือ "ส่งสลิป → รอแอดมินตรวจ" อย่างเดียว (ลบ payment_method แล้ว)
 * - transfer_time: เวลาที่ลูกค้าแจ้งโอน (จากสลิป) — optional
 */
class ConfirmBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slip_image' => 'required|file|image|mimes:jpeg,png,jpg|max:4096',
            'transfer_time' => 'nullable|date|before_or_equal:now',
        ];
    }
}
