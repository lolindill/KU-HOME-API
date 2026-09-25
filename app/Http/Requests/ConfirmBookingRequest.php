<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🌟 Refactor (19/08/26): User ส่งหลักฐานการชำระเงิน (slip + time)
 *
 * booking_id รับจาก URL param (เหมือน StorePaymentRequest ใน front-desk)
 * - slip_image: บังคับเสมอ — flow เหลือ "ส่งสลิป → รอแอดมินตรวจ" อย่างเดียว (ลบ payment_method แล้ว)
 * - transfer_time: เวลาที่ลูกค้าแจ้งโอน (จากสลิป) — optional
 *
 * 💳 (25/09/26, booking-payment-types ticket 04/05): amount บังคับ ≥ 1 —
 *    สลิปใหม่ต้องแจ้งยอดเสมอ (ชั้น A booking_confirmations.amount) —
 *    ⚠️ breaking change ตั้งแต่ deploy นี้: frontend ต้องส่ง amount มาด้วย
 *    (admin verify เทียบ expected vs claimed แล้วตัดสินเอง — ระบบไม่ hard-reject)
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
            'amount' => 'required|integer|min:1',
        ];
    }

    /**
     * 🌟 ข้อความแจ้งเตือนภาษาไทย
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'กรุณาระบุยอดเงินที่ชำระตามสลิป (บาท) ค่ะ 💳',
            'amount.integer' => 'ยอดเงินที่ชำระต้องเป็นตัวเลขจำนวนเต็ม (บาท) ค่ะ 💳',
            'amount.min' => 'ยอดเงินที่ชำระต้องมากกว่า 0 บาทค่ะ 💳',
        ];
    }
}
