<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 💳🏛️ (25/09/26, booking-payment-types ticket 05 หัวข้อ 2 + organization-bookings ticket 07) — PUT /bookings/{id}
 *
 * Endpoint สำหรับ admin แก้ payment fields + customer identity fields — guard state แยกตาม field-group (ตรวจใน controller):
 *
 * 💳 payment group — draft เท่านั้น (หลังส่งสลิป/verify แล้ว frozen)
 * - payment_type: ไม่ส่ง = ไม่แตะค่าเดิม
 * - deposit_amount: ส่ง null ชัด ๆ = revert ไป effective 50% · มีความหมายเฉพาะ type deposit
 *   (cross-field ตรวจใน controller — FormRequest ไม่รู้ค่า type ปัจจุบัน/ใหม่)
 * - discount_code: reuse กลไก setDiscountCode/DiscountService เดิม · ส่งค่าว่าง = ลบโค้ด
 * - payment_deadline: ต่อ/ลดเวลาชำระ · ไม่ส่ง = คงเดิม
 * - total_amount ไม่รับจาก input — reprice โดยระบบเสมอ
 *
 * 🏛️ customer identity group (ticket 07 — attach user/organization ทีหลัง) — ได้จนก่อน complete/no_show
 * - user: UUID target (attach จองแทน) · ส่ง null ชัด ๆ = ถอด identity กลับเฮดเปล่า
 * - organize: erp code → server lookup เป็น FK organization_id (ไม่เก็บ erp บน bookings — replaceability
 *   contract ของ ticket 01) · user×organize mutually exclusive + organize บังคับ customer_name —
 *   cross-field ตรวจใน controller (ชุดเดียวกับ POST /bookings, ticket 03)
 * - customer_name/customer_phone/customer_email: snapshot ผู้ติดต่อ (phone/email nullable)
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

            // 🏛️ organization-bookings ticket 07 — customer identity (attach ทีหลัง)
            'user' => 'nullable|uuid|exists:users,id',
            'organize' => 'nullable|string|max:100',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_email' => 'nullable|string|email|max:255',

            // 📊 (05/10/26, excel-reports spec §2.1 — ticket 09) booking attributes สำหรับรายงาน
            //    admin/system เท่านั้น (route role:admin + re-check ใน controller) · ไม่ผูก state
            'invoice_requested_at' => 'nullable|date',
            'special_request' => 'nullable|string|max:2000',
            'comment' => 'nullable|string|max:2000',
            'is_complimentary' => 'nullable|boolean',
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
            'user.uuid' => 'รูปแบบ user ต้องเป็น UUID ค่ะ 🏛️',
            'user.exists' => 'ไม่พบ user ตามที่ระบุค่ะ 🏛️',
            'organize.max' => 'รหัสองค์กร (organize) ต้องไม่เกิน 100 ตัวอักษรค่ะ 🏛️',
            'customer_name.max' => 'ชื่อผู้ติดต่อ/ผู้เข้าพัก (customer_name) ต้องไม่เกิน 255 ตัวอักษรค่ะ 🏛️',
            'customer_phone.max' => 'เบอร์โทรศัพท์ต้องไม่เกิน 50 ตัวอักษรค่ะ 🏛️',
            'customer_email.email' => 'รูปแบบอีเมลไม่ถูกต้องค่ะ 🏛️',
            'customer_email.max' => 'อีเมลต้องไม่เกิน 255 ตัวอักษรค่ะ 🏛️',
        ];
    }
}
