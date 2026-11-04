<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookingConfirmation;
use App\Models\BookingRoom;
use App\Models\HousekeepingTask;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * 🖼️ Image Controller (19/08/26) — ให้บริการไฟล์รูปผ่าน signed URL
 *
 * draft upload() ถูกถอดออกแล้ว — รูปถูกสร้างผ่าน flow ของเจ้าของเสมอ
 * (ปัจจุบัน: POST /bookings/{id}/confirm สร้าง slip Image ผูกกับ confirmation)
 *
 * 🔐 (26/10/26) กติกาสิทธิ์ — token + signature คุมคู่กัน (2 ชั้น):
 *   admin ผ่านทุกประเภท · เจ้าของตาม imageable:
 *   - BookingConfirmation (สลิป) / BookingRoom (check-in evidence) → เจ้าของ booking
 *     (org booking user_id = null → admin เท่านั้น เหมือน semantics ของ BookingController::showById)
 *   - HousekeepingTask (รูปก่อน/หลังเก็บ) → assigned_to
 *   - type อื่น/ไม่รู้จัก → admin เท่านั้น (fail-closed)
 */
class ImageController extends Controller
{
    /**
     * GET /api/v1/images/{image}/file?expires=...&signature=...
     *
     * 🔐 auth:sanctum + signed (ดู middleware ใน routes/api.php) — ผู้ขอต้องเป็น
     *    เจ้าของรูปตาม imageable หรือ admin เท่านั้น
     *    และ route นี้ exempt RequireJsonAccept เพื่อให้ fetch ที่ส่ง Accept: image/* โหลดได้
     *    (frontend ใช้ fetch + Authorization header + blob URL — <img src> ใส่ token ไม่ได้)
     */
    public function show(Request $request, Image $image)
    {
        $user = $request->user('sanctum');

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'กรุณาเข้าสู่ระบบก่อนนะคะนายท่าน!',
            ], 401);
        }

        $imageable = $image->imageable;

        $ownerId = match (true) {
            $imageable instanceof BookingConfirmation => $imageable->booking?->user_id,
            $imageable instanceof BookingRoom => $imageable->booking?->user_id,
            $imageable instanceof HousekeepingTask => $imageable->assigned_to,
            default => null,
        };

        if ($user->role !== 'admin' && $user->id !== $ownerId) {
            return response()->json([
                'status' => 'error',
                'message' => 'นายท่านไม่มีสิทธิ์ดูรูปภาพนี้ค่ะ! 🔒 (เฉพาะเจ้าของหรือผู้ดูแลระบบ)',
            ], 403);
        }

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
