<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 💳 (25/09/26, booking-payment-types ticket 05 หัวข้อ 2) — PUT /bookings/{id}
 *
 * Endpoint ใหม่สำหรับ admin แก้ payment fields ของ booking สถานะ draft เท่านั้น
 * (หลังส่งสลิป/verify แล้ว frozen — guard state ใน controller)
 *
 * - payment_type: ไม่ส่ง = ไม่แตะค่าเดิม
 * - deposit_amount: ส่ง null ชัด ๆ = revert ไป effective 50% · มีความหมายเฉพาะ type deposit
 *   (cross-field ตรวจใน controller — FormRequest ไม่รู้ค่า type ปัจจุบัน/ใหม่)
 * - discount_code: reuse กลไก setDiscountCode/DiscountService เดิม · ส่งค่าว่าง = ลบโค้ด
 * - payment_deadline: ต่อ/ลดเวลาชำระ · ไม่ส่ง = คงเดิม
 * - total_amount ไม่รับจาก input — reprice โดยระบบเสมอ
 */
class UpdateBookingPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_type' => 'nullable|in:full,deposit,deferred',
            'deposit_amount' => 'nullable|integer|min:1',
            'discount_code' => 'nullable|string|max:50',
            'payment_deadline' => 'nullable|date|after:now',
        ];
    }

    /**
     * 🌟 ข้อความแจ้งเตือนภาษาไทย
     */
    public function messages(): array
    {
        return [
            'payment_type.in' => 'ประเภทการชำระเงินต้องเป็น full, deposit หรือ deferred เท่านั้นค่ะ 💳',
            'deposit_amount.integer' => 'ยอดมัดจำต้องเป็นตัวเลขจำนวนเต็ม (บาท) ค่ะ 💳',
            'deposit_amount.min' => 'ยอดมัดจำต้องมากกว่า 0 บาทค่ะ 💳',
            'discount_code.max' => 'รหัสส่วนลดต้องไม่เกิน 50 ตัวอักษร',
            'payment_deadline.after' => 'เวลาชำระเงินต้องเป็นเวลาในอนาคตค่ะ ⏱️',
        ];
    }
}
