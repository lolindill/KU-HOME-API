<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;

/**
 * 🖼️ Image Controller (19/08/26) — ให้บริการไฟล์รูปผ่าน signed URL
 *
 * draft upload() ถูกถอดออกแล้ว — รูปถูกสร้างผ่าน flow ของเจ้าของเสมอ
 * (ปัจจุบัน: POST /bookings/{id}/confirm สร้าง slip Image ผูกกับ confirmation)
 */
class ImageController extends Controller
{
    /**
     * GET /api/v1/images/{image}/file?expires=...&signature=...
     *
     * 🔐 ไม่มี auth:sanctum โดยตั้งใจ: signature (อายุ 15 นาที) เป็นตัวยืนยันแทน
     *    URL ถูกออกให้เฉพาะใน response ของผู้มีสิทธิ์เท่านั้น (เจ้าของ booking / admin)
     *    และ route นี้ exempt RequireJsonAccept เพื่อให้ <img Accept: image/*> โหลดได้
     */
    public function show(Image $image)
    {
        if (! Storage::disk($image->disk)->exists($image->path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบไฟล์รูปค่ะ (อาจถูกลบไปแล้วตามรอบการเก็บกวาด)',
            ], 404);
        }

        // stream ไฟล์ inline พร้อม Content-Type ที่บันทึกไว้ตอนอัปโหลด
        return Storage::disk($image->disk)->response($image->path, null, [
            'Content-Type' => $image->mime_type ?? 'application/octet-stream',
        ]);
    }
}
