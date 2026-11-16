<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 🖼️ Request สำหรับอัปโหลดรูปโปรไฟล์ (avatar) — POST /profile/avatar
 *
 * PUT /profile เป็น JSON ไม่รับไฟล์ avatar จึงแยกเป็น multipart endpoint เฉพาะ
 * (ตามแพทเทิร์น check-in evidence) — 1 user มี avatar เดียว อัปโหลดใหม่ = แทนที่
 */
class UploadAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'กรุณาแนบไฟล์รูปโปรไฟล์ค่ะนายท่าน 📎',
            'file.file' => 'ไฟล์ที่ส่งมาไม่ถูกต้องค่ะ',
            'file.image' => 'กรุณาส่งไฟล์รูปภาพเท่านั้นค่ะ',
            'file.mimes' => 'รองรับเฉพาะไฟล์รูปภาพ (jpeg, png, jpg, webp) เท่านั้นค่ะ',
            'file.max' => 'ขนาดไฟล์ต้องไม่เกิน 2MB ค่ะ',
        ];
    }
}
