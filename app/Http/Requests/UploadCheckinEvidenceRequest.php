<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🛎️ (REQ-033): Request สำหรับอัปโหลดหลักฐานการเข้าพักตอน check-in
 *
 * ประเภทหลักฐาน (metadata.type):
 *   - id_card: บัตรประชาชน / Passport
 *   - registration: ใบลงทะเบียนการเข้าพัก
 *   - guest_register: Guest Register (ดิจิทัล/กระดาษ)
 *   - pdpa: เอกสารยินยอม PDPA
 *   - other: เอกสารอื่นๆ
 */
class UploadCheckinEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:jpeg,png,jpg,webp,pdf|max:10240',
            'type' => 'required|string|in:id_card,registration,guest_register,pdpa,other',
            'note' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'กรุณาแนบไฟล์หลักฐานการเข้าพักค่ะนายท่าน 📎',
            'file.file' => 'ไฟล์ที่ส่งมาไม่ถูกต้องค่ะ',
            'file.mimes' => 'รองรับเฉพาะไฟล์รูปภาพ (jpeg, png, jpg, webp) หรือไฟล์ PDF เท่านั้นค่ะ',
            'file.max' => 'ขนาดไฟล์ต้องไม่เกิน 10MB ค่ะ',
            'type.required' => 'กรุณาระบุประเภทของหลักฐานค่ะ (id_card, registration, guest_register, pdpa, other)',
            'type.in' => 'ประเภทหลักฐานไม่ถูกต้องค่ะ รองรับเฉพาะ id_card, registration, guest_register, pdpa, other เท่านั้น',
            'note.max' => 'หมายเหตุต้องมีความยาวไม่เกิน 1000 ตัวอักษรค่ะ',
        ];
    }
}
