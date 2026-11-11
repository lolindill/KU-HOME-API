<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadCheckinEvidenceRequest;
use App\Models\BookingRoom;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CheckinEvidenceController extends Controller
{
    /**
     * 🛎️ 6. Upload Check-in Evidence (REQ-033 — admin+staff)
     * อัปโหลดบัตร ปชช. / registration / PDPA ผูกกับ BookingRoom (เก็บ 365 วัน)
     */
    public function uploadEvidence(UploadCheckinEvidenceRequest $request, string $bookingId, string $bookingRoomId)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่มีสิทธิ์อัปโหลดหลักฐานการเข้าพักค่ะนายท่าน (admin หรือ staff เท่านั้น)',
            ], 403);
        }

        try {
            $bookingRoom = BookingRoom::where('id', $bookingRoomId)
                ->where('booking_id', $bookingId)
                ->firstOrFail();

            // 🛡️ State guard: อนุญาต confirmed, checked_in, checked_out
            // (draft ยังไม่ยืนยัน / no_show ไม่ได้เข้าพัก)
            if (! in_array($bookingRoom->status, ['confirmed', 'checked_in', 'checked_out'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "ไม่สามารถอัปโหลดหลักฐานได้ค่ะนายท่าน เนื่องจากห้องมีสถานะ '{$bookingRoom->status}' (อนุญาตเฉพาะ confirmed, checked_in, checked_out เท่านั้น)",
                ], 422);
            }

            $validated = $request->validated();
            $file = $request->file('file');

            $storedPath = null;
            try {
                DB::beginTransaction();

                // 🖼️ บันทึกไฟล์บน private disk local (storage/app/private/checkin_evidences)
                $storedPath = $file->store('checkin_evidences', 'local');

                // 🖼️ morph evidences() ไปยัง images table
                $image = $bookingRoom->evidences()->create([
                    'path' => $storedPath,
                    'disk' => 'local',
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_by' => $user->id,
                    'metadata' => [
                        'type' => $validated['type'],
                        'note' => $validated['note'] ?? null,
                    ],
                ]);

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'อัปโหลดหลักฐานการเข้าพักเรียบร้อยแล้วค่ะนายท่าน ✨',
                    'data' => [
                        'id' => $image->id,
                        'booking_room_id' => $bookingRoom->id,
                        'type' => $image->metadata['type'] ?? $validated['type'],
                        'metadata' => $image->metadata,
                        'file_name' => $image->original_name,
                        'mime_type' => $image->mime_type,
                        'size' => $image->size,
                        'url' => $image->url,
                        'created_at' => $image->created_at?->toISOString(),
                    ],
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                if ($storedPath !== null) {
                    Storage::disk('local')->delete($storedPath);
                }
                throw $e;
            }

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลห้องพักที่ระบุในระบบค่ะนายท่าน',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Upload check-in evidence failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการอัปโหลดหลักฐาน กรุณาลองใหม่อีกครั้งนะคะ 🥺',
            ], 500);
        }
    }

    /**
     * 🛎️ 7. Get Check-in Evidences (REQ-033 — admin+staff)
     * ดึงรายการหลักฐานการเข้าพักพร้อม signed URL (อายุ 15 นาที)
     */
    public function getEvidences(Request $request, string $bookingId, string $bookingRoomId)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่มีสิทธิ์เข้าถึงหลักฐานการเข้าพักค่ะนายท่าน (admin หรือ staff เท่านั้น)',
            ], 403);
        }

        try {
            $bookingRoom = BookingRoom::where('id', $bookingRoomId)
                ->where('booking_id', $bookingId)
                ->firstOrFail();

            $evidences = $bookingRoom->evidences()
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn ($img) => [
                    'id' => $img->id,
                    'type' => $img->metadata['type'] ?? null,
                    'metadata' => $img->metadata,
                    'file_name' => $img->original_name,
                    'mime_type' => $img->mime_type,
                    'size' => $img->size,
                    'url' => $img->url,
                    'created_at' => $img->created_at?->toISOString(),
                ]);

            return response()->json([
                'status' => 'success',
                'booking_room_id' => $bookingRoom->id,
                'evidences' => $evidences,
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลห้องพักที่ระบุในระบบค่ะนายท่าน',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Get check-in evidences failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลหลักฐานค่ะ 🥺',
            ], 500);
        }
    }
}
