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
            'booking_rooms' => 'required|array|min:1',
            'booking_rooms.*.booking_room_id' => 'required|uuid|distinct',

            'booking_rooms.*.room_type_id' => 'sometimes|required|uuid|exists:room_types,id',
            'booking_rooms.*.check_in' => 'sometimes|required|date|after_or_equal:today',
            'booking_rooms.*.check_out' => 'sometimes|required|date|after:booking_rooms.*.check_in',
            'booking_rooms.*.extra_beds' => 'nullable|integer|min:0',

            // 👥 ข้อมูลผู้เข้าพัก
            'booking_rooms.*.guests' => 'nullable|array',
            'booking_rooms.*.guests.*.title' => 'nullable|string|max:50',
            'booking_rooms.*.guests.*.firstName' => 'nullable|string|max:255',
            'booking_rooms.*.guests.*.lastName' => 'nullable|string|max:255',
            'booking_rooms.*.guests.*.first_name' => 'nullable|string|max:255',
            'booking_rooms.*.guests.*.last_name' => 'nullable|string|max:255',
            'booking_rooms.*.guests.*.name' => 'nullable|string|max:255',
            'booking_rooms.*.guests.*.email' => 'nullable|string|email|max:255',
            'booking_rooms.*.guests.*.phone' => 'nullable|string|max:50',
            'booking_rooms.*.guests.*.nationality' => 'nullable|string|max:100',

            // 🏨 Phase 1: bed preference สำหรับ allocation algorithm (twin | null = any)
            'booking_rooms.*.bed_preference' => 'nullable|string|in:twin',

            // 🧾 Billing fields
            'booking_rooms.*.billing_address' => 'nullable|string|max:255',
            'booking_rooms.*.billing_comment' => 'nullable|string|max:255',

            // ➕ Addons (ราคาคิดใหม่ทั้งหมดที่ server — ไม่รับ price จาก client)
            'booking_rooms.*.addons' => 'nullable|array',
            'booking_rooms.*.addons.breakfast' => 'nullable|integer|min:0',
            'booking_rooms.*.addons.early_checkin' => [
                'nullable', 'integer', 'min:0', 'max:5',
                function ($attribute, $value, $fail) {
                    if (is_bool($value)) {
                        $fail('จำนวนชั่วโมง early check-in ต้องเป็นตัวเลขจำนวนเต็ม (0-5) ค่ะ');
                    }
                },
            ],
            'booking_rooms.*.addons.late_checkout' => [
                'nullable', 'integer', 'min:0', 'max:5',
                function ($attribute, $value, $fail) {
                    if (is_bool($value)) {
                        $fail('จำนวนชั่วโมง late check-out ต้องเป็นตัวเลขจำนวนเต็ม (0-5) ค่ะ');
                    }
                },
            ],
        ];
    }

    /**
     * 🌟 ข้อความแจ้งเตือนภาษาไทย
     */
    public function messages(): array
    {
        return [
            'booking_rooms.required' => 'กรุณาระบุรายการห้องที่ต้องการแก้ไขอย่างน้อย 1 ห้องค่ะ',
            'booking_rooms.min' => 'กรุณาระบุรายการห้องที่ต้องการแก้ไขอย่างน้อย 1 ห้องค่ะ',
            'booking_rooms.*.booking_room_id.required' => 'กรุณาระบุ booking_room_id ของแต่ละห้องค่ะ',
            'booking_rooms.*.booking_room_id.uuid' => 'booking_room_id ต้องเป็น UUID ค่ะ',
            'booking_rooms.*.booking_room_id.distinct' => 'booking_room_id ซ้ำในคำขอเดียวกันไม่ได้ค่ะ',
            'booking_rooms.*.room_type_id.required' => 'กรุณาระบุประเภทห้อง',
            'booking_rooms.*.room_type_id.exists' => 'ไม่พบประเภทห้องที่ระบุ',
            'booking_rooms.*.check_in.required' => 'กรุณาระบุวันที่เช็คอินของแต่ละห้อง',
            'booking_rooms.*.check_in.after_or_equal' => 'วันที่เช็คอินต้องไม่เป็นวันในอดีต',
            'booking_rooms.*.check_out.required' => 'กรุณาระบุวันที่เช็คเอาท์ของแต่ละห้อง',
            'booking_rooms.*.check_out.after' => 'วันที่เช็คเอาท์ต้องอยู่หลังวันที่เช็คอินของห้องนั้น',
            'booking_rooms.*.bed_preference.in' => 'bed_preference ต้องเป็น twin เท่านั้นค่ะ',
            'booking_rooms.*.addons.early_checkin.integer' => 'จำนวนชั่วโมง early check-in ต้องเป็นตัวเลขจำนวนเต็ม (0-5) ค่ะ',
            'booking_rooms.*.addons.early_checkin.min' => 'ชั่วโมง early check-in ต้องอยู่ระหว่าง 0-5 ชั่วโมงค่ะ',
            'booking_rooms.*.addons.early_checkin.max' => 'ชั่วโมง early check-in ต้องอยู่ระหว่าง 0-5 ชั่วโมงค่ะ',
            'booking_rooms.*.addons.late_checkout.integer' => 'จำนวนชั่วโมง late check-out ต้องเป็นตัวเลขจำนวนเต็ม (0-5) ค่ะ',
            'booking_rooms.*.addons.late_checkout.min' => 'ชั่วโมง late check-out ต้องอยู่ระหว่าง 0-5 ชั่วโมงค่ะ',
            'booking_rooms.*.addons.late_checkout.max' => 'ชั่วโมง late check-out ต้องอยู่ระหว่าง 0-5 ชั่วโมงค่ะ',
        ];
    }
}
