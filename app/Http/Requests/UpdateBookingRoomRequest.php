<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🌟 (17/08/26): Request สำหรับแก้ไข booking room รายห้อง
 *
 * ใช้กับ PUT /bookings/{bookingId}/rooms/{bookingRoomId}
 * — อนุญาตเฉพาะ BR status='draft' และ parent booking status='draft' (guard ใน controller)
 *
 * โครงสร้าง flat (ไม่ซ้อน booking_rooms.*) ต่างจาก StoreBookingRequest/AddBookingRoomsRequest
 * เพราะแก้ทีละห้องด้วย bookingRoomId ที่ระบุใน path
 *
 * 🚫 ไม่มี booking_id / room_id / status — ห้าม user ตั้งเอง
 *    (room_id ต้องผ่าน RoomAllocator เท่านั้น, status ต้องผ่าน transitionStatus)
 */
class UpdateBookingRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_type_id' => 'sometimes|required|uuid|exists:room_types,id',
            'check_in' => 'sometimes|required|date|after_or_equal:today',
            'check_out' => 'sometimes|required|date|after:check_in',
            'extra_beds' => 'nullable|integer|min:0',

            // 👥 ข้อมูลผู้เข้าพัก
            'guests' => 'nullable|array',
            'guests.*.title' => 'nullable|string|max:50',
            'guests.*.firstName' => 'nullable|string|max:255',
            'guests.*.lastName' => 'nullable|string|max:255',
            'guests.*.first_name' => 'nullable|string|max:255',
            'guests.*.last_name' => 'nullable|string|max:255',
            'guests.*.name' => 'nullable|string|max:255',
            'guests.*.email' => 'nullable|string|email|max:255',
            'guests.*.phone' => 'nullable|string|max:50',
            'guests.*.nationality' => 'nullable|string|max:100',
            'guests.*.is_ku_member' => 'nullable|boolean',
            'has_children' => 'nullable|boolean',

            // 🏨 Phase 1: bed preference สำหรับ allocation algorithm (twin | null = any)
            'bed_preference' => 'nullable|string|in:twin',

            // 🧾 Billing fields
            'billing_address' => 'nullable|string|max:255',
            'billing_comment' => 'nullable|string|max:255',

            // ➕ Addons (ราคาคิดใหม่ทั้งหมดที่ server — ไม่รับ price จาก client)
            'addons' => 'nullable|array',
            'addons.breakfast' => 'nullable|integer|min:0',
            'addons.early_checkin' => 'nullable|boolean',
            'addons.late_checkout' => 'nullable|boolean',
        ];
    }

    /**
     * 🌟 ข้อความแจ้งเตือนภาษาไทย
     */
    public function messages(): array
    {
        return [
            'room_type_id.required' => 'กรุณาระบุประเภทห้อง',
            'room_type_id.exists' => 'ไม่พบประเภทห้องที่ระบุ',
            'check_in.required' => 'กรุณาระบุวันที่เช็คอิน',
            'check_in.after_or_equal' => 'วันที่เช็คอินต้องไม่เป็นวันในอดีต',
            'check_out.required' => 'กรุณาระบุวันที่เช็คเอาท์',
            'check_out.after' => 'วันที่เช็คเอาท์ต้องอยู่หลังวันที่เช็คอิน',
            'bed_preference.in' => 'bed_preference ต้องเป็น twin เท่านั้นค่ะ',
        ];
    }
}
