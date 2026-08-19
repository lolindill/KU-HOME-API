<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🌟 (19/08/26): Request สำหรับแก้ไข booking room หลายห้องพร้อมกัน (batch update)
 *
 * ใช้กับ PUT /bookings/{bookingId}/rooms
 * — อนุญาตเฉพาะ BR status='draft' และ parent booking status='draft' ทุกห้อง (guard ใน controller, all-or-nothing)
 * — payload ต่างกันได้รายห้อง: 1 array entry = การแก้ 1 ห้อง (ระบุ booking_room_id ของตัวเอง)
 *
 * 🚫 ไม่มี booking_id / room_id / status / ราคา — ห้าม user ตั้งเอง
 *    (room_id ต้องผ่าน RoomAllocator เท่านั้น, status ต้องผ่าน transitionStatus,
 *     ราคาคิดใหม่ทั้งหมดที่ server จาก global_rates)
 */
class UpdateBookingRoomsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rooms' => 'required|array|min:1',
            'rooms.*.booking_room_id' => 'required|uuid|distinct',

            'rooms.*.room_type_id' => 'sometimes|required|uuid|exists:room_types,id',
            'rooms.*.check_in' => 'sometimes|required|date|after_or_equal:today',
            'rooms.*.check_out' => 'sometimes|required|date|after:rooms.*.check_in',
            'rooms.*.extra_beds' => 'nullable|integer|min:0',

            // 👥 ข้อมูลผู้เข้าพัก
            'rooms.*.guests' => 'nullable|array',
            'rooms.*.guests.*.title' => 'nullable|string|max:50',
            'rooms.*.guests.*.name' => 'nullable|string|max:255',
            'rooms.*.guests.*.nationality' => 'nullable|string|max:100',
            'rooms.*.guests.*.is_ku_member' => 'nullable|boolean',
            'rooms.*.has_children' => 'nullable|boolean',

            // 🏨 Phase 1: bed preference สำหรับ allocation algorithm (twin | null = any)
            'rooms.*.bed_preference' => 'nullable|string|in:twin',

            // 🧾 Billing fields
            'rooms.*.billing_address' => 'nullable|string|max:255',
            'rooms.*.billing_comment' => 'nullable|string|max:255',

            // ➕ Addons (ราคาคิดใหม่ทั้งหมดที่ server — ไม่รับ price จาก client)
            'rooms.*.addons' => 'nullable|array',
            'rooms.*.addons.breakfast' => 'nullable|integer|min:0',
            'rooms.*.addons.early_checkin' => 'nullable|boolean',
            'rooms.*.addons.late_checkout' => 'nullable|boolean',
        ];
    }

    /**
     * 🌟 ข้อความแจ้งเตือนภาษาไทย
     */
    public function messages(): array
    {
        return [
            'rooms.required' => 'กรุณาระบุรายการห้องที่ต้องการแก้ไขอย่างน้อย 1 ห้องค่ะ',
            'rooms.min' => 'กรุณาระบุรายการห้องที่ต้องการแก้ไขอย่างน้อย 1 ห้องค่ะ',
            'rooms.*.booking_room_id.required' => 'กรุณาระบุ booking_room_id ของแต่ละห้องค่ะ',
            'rooms.*.booking_room_id.uuid' => 'booking_room_id ต้องเป็น UUID ค่ะ',
            'rooms.*.booking_room_id.distinct' => 'booking_room_id ซ้ำในคำขอเดียวกันไม่ได้ค่ะ',
            'rooms.*.room_type_id.required' => 'กรุณาระบุประเภทห้อง',
            'rooms.*.room_type_id.exists' => 'ไม่พบประเภทห้องที่ระบุ',
            'rooms.*.check_in.required' => 'กรุณาระบุวันที่เช็คอินของแต่ละห้อง',
            'rooms.*.check_in.after_or_equal' => 'วันที่เช็คอินต้องไม่เป็นวันในอดีต',
            'rooms.*.check_out.required' => 'กรุณาระบุวันที่เช็คเอาท์ของแต่ละห้อง',
            'rooms.*.check_out.after' => 'วันที่เช็คเอาท์ต้องอยู่หลังวันที่เช็คอินของห้องนั้น',
            'rooms.*.bed_preference.in' => 'bed_preference ต้องเป็น twin เท่านั้นค่ะ',
        ];
    }
}
