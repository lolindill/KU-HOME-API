<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // นายท่านอนุญาตให้ส่งคำขอนี้ได้เลยค่ะ
        return true;
    }

    public function rules(): array
    {
        return [
            // 📅 ข้อมูลพื้นฐานการจอง (จาก Migration)
            'booking_type'      => 'nullable|string|in:individual,group,monthly',
            'source'            => 'required|string',
            'check_in'          => 'required|date|after_or_equal:today',
            'check_out'         => 'required|date|after:check_in',
            'breakfast_included'=> 'boolean',

            // 🏢 ข้อมูลห้องพัก (Array Validation)
            'booking_rooms'                => 'required|array|min:1',
            'booking_rooms.*.room_type_id' => 'required|uuid|exists:room_types,id',
            'booking_rooms.*.quantity'     => 'required|integer|min:1',
            'booking_rooms.*.extra_beds'   => 'integer|min:0',

            // 🧍‍♂️ ข้อมูลผู้เข้าพัก (Guest Details)
            'guest_title'       => 'nullable|string',
            'guest_first_name'  => 'required|string|max:255',
            'guest_last_name'   => 'required|string|max:255',
            'guest_email'       => 'required|email',
            'guest_phone'       => 'required|string',
            'guest_id_number'   => 'nullable|string',
            'guest_nationality' => 'required|string',
            'is_ku_member'      => 'required|boolean',
            
            // 💸 ส่วนลด (ถ้ามี)
            'discount_code_id'  => 'nullable|uuid|exists:discount_codes,id',
        ];
    }

    /**
     * ปรับแต่งชื่อฟิลด์ให้อ่านง่ายตอนแจ้ง Error (Optional)
     */
    public function attributes(): array
    {
        return [
            'booking_rooms.*.room_type_id' => 'ประเภทห้องพัก',
            'booking_rooms.*.quantity'     => 'จำนวนห้อง',
            'check_in' => 'วันที่เข้าพัก',
            'check_out' => 'วันที่ย้อนกลับ',
        ];
    }
}