<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source'             => 'required|string|in:online,admin,line',
            'check_in'           => 'required|date|after_or_equal:today',
            'check_out'          => 'required|date|after:check_in',

            // 🌟 Refactor (18/06/26): ข้อมูลผู้เข้าพักย้ายไปอยู่ใน booking_rooms (รองรับหลายคน/ห้อง)
            // bookings ไม่รับ guest fields แล้ว

            'booking_rooms'                  => 'required|array',
            'booking_rooms.*.room_type_id'   => 'required|uuid|exists:room_types,id',
            'booking_rooms.*.quantity'       => 'required|integer|min:1',
            'booking_rooms.*.extra_beds'     => 'nullable|integer|min:0',

            // 👥 ข้อมูลผู้เข้าพักในแต่ละห้อง (array ของ guests)
            // รองรับหลายคนต่อห้อง; ถ้าไม่ส่งมา ระบบจะใช้ชื่อผู้จอง (user) เป็น default
            'booking_rooms.*.guests'                 => 'nullable|array',
            'booking_rooms.*.guests.*.title'         => 'nullable|string|max:50',
            'booking_rooms.*.guests.*.name'          => 'nullable|string|max:255',
            'booking_rooms.*.guests.*.nationality'   => 'nullable|string|max:100',
            'booking_rooms.*.guests.*.is_ku_member'  => 'nullable|boolean',
            'booking_rooms.*.children'               => 'nullable|integer|min:0',

            'booking_rooms.*.addons'                    => 'nullable|array',
            'booking_rooms.*.addons.breakfast'           => 'nullable|integer|min:0',
            'booking_rooms.*.addons.early_checkin'       => 'nullable|boolean',
            'booking_rooms.*.addons.late_checkout'       => 'nullable|boolean',
        ];
    }

    /**
     * 🌟 ข้อความแจ้งเตือนภาษาไทย
     */
    public function messages(): array
    {
        return [
            'source.required'                => 'กรุณาระบุแหล่งที่มาของการจอง (online, admin, line)',
            'source.in'                      => 'แหล่งที่มาต้องเป็น online, admin หรือ line เท่านั้น',
            'check_in.required'              => 'กรุณาระบุวันที่เช็คอิน',
            'check_in.after_or_equal'        => 'วันที่เช็คอินต้องไม่เป็นวันในอดีต',
            'check_out.required'             => 'กรุณาระบุวันที่เช็คเอาท์',
            'check_out.after'                => 'วันที่เช็คเอาท์ต้องอยู่หลังวันที่เช็คอิน',
            'booking_rooms.required'         => 'กรุณาระบุห้องที่ต้องการจองอย่างน้อย 1 ห้อง',
            'booking_rooms.array'            => 'รูปแบบข้อมูลห้องที่จองไม่ถูกต้อง',
            'booking_rooms.*.room_type_id.required' => 'กรุณาระบุประเภทห้อง',
            'booking_rooms.*.room_type_id.exists'   => 'ไม่พบประเภทห้องที่ระบุ',
            'booking_rooms.*.quantity.required'     => 'กรุณาระบุจำนวนห้อง',
            'booking_rooms.*.quantity.min'          => 'จำนวนห้องต้องอย่างน้อย 1 ห้อง',
        ];
    }
}