<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🌟 Refactor (24/07/26): User ส่งหลักฐานการชำระเงิน (slip + method + time)
 *
 * booking_id รับจาก URL param (เหมือน StorePaymentRequest ใน front-desk)
 * - payment_method: enum เดียวกับ payments table เดิม (cash|credit_card|transfer)
 * - slip_image: บังคับเฉพาะ transfer (cash/credit_card ไม่ต้องส่ง slip)
 * - transfer_time: เวลาที่ลูกค้าแจ้งโอน (จากสลิป) — บังคับเฉพาะ transfer
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
            'payment_method' => 'required|string|in:cash,credit_card,transfer',
            'slip_image'     => 'required_if:payment_method,transfer|file|image|mimes:jpeg,png,jpg|max:4096',
            'transfer_time'  => 'required_if:payment_method,transfer|date|before_or_equal:now',
        ];
    }
}
