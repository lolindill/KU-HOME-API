<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Services\RoomAllocator\RoomAllocator;
use App\Support\IncludeReservedGate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingAssignmentController extends Controller
{
    public function autoAssignRooms(Request $request, $bookingId)
    {
        try {
            DB::beginTransaction();

            // 🌟 1. โหลดข้อมูลการจอง พร้อมกับห้องพัก และ Addon มาด้วยเลย
            $booking = Booking::with(['bookingRooms.addon'])->findOrFail($bookingId);

            // =========================================================
            // 🛡️ ด่านตรวจที่ 1: เช็คสิทธิ์ User (Token ตรงกับเจ้าของ หรือเป็น Admin/Staff)
            // =========================================================
            $user = $request->user('sanctum');

            // ถ้านายท่านยังไม่ได้ล็อกอิน
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'นายท่านยังไม่ได้ล็อกอินนะคะ! กรุณาแนบ Token ก่อนทำรายการค่ะ 🔒',
                    'user' => $user,
                ], 401);
            }

            // ถ้าไม่ใช่ Admin/Staff และ ID ไม่ตรงกับคนจอง
            if (! in_array($user->role, ['admin', 'staff']) && $booking->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่อนุญาตค่ะนายท่าน! สิทธิ์นี้เฉพาะแอดมินหรือเจ้าของบุ๊กกิ้งเท่านั้นนะคะ 🙅‍♀️',
                ], 403);
            }

            // =========================================================
            // 🛡️ ด่านตรวจที่ 2: เช็คสถานะ Booking (ต้อง paid หรือ confirmed เท่านั้น)
            // =========================================================
            if (! in_array($booking->status, ['paid', 'confirmed'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "ยังระบุเลขห้องไม่ได้ค่ะนายท่าน! สถานะปัจจุบันคือ '{$booking->status}' (ต้องจ่ายเงิน 'paid' หรือยืนยัน 'confirmed' ก่อนนะคะ) 💳",
                ], 422);
            }

            // =========================================================
            // 🌟 เริ่มกระบวนการ Assign ห้อง (เรียกใช้ Logic จาก Model)
            // =========================================================
            $unassignedRooms = $booking->bookingRooms()->whereNull('room_id')->get();
            $unassignedRooms->load('addon');

            if ($unassignedRooms->isEmpty()) {
                // เช็คกันเหนียวเผื่อบุ๊กกิ้งนี้ไม่มีห้องเลยจริงๆ
                if ($booking->bookingRooms()->count() === 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'เอ๊ะ! บุ๊กกิ้งนี้ยังไม่มีการจองห้องพักเข้ามาเลยนะคะนายท่าน! 💦',
                    ], 422);
                }

                return response()->json([
                    'status' => 'info',
                    'message' => 'ห้องพักทั้งหมดในบุ๊กกิ้งนี้ถูกระบุเลขห้องเรียบร้อยแล้วค่ะนายท่าน! ✨',
                ]);
            }

            // 🌟 Refactor (25/06/26): BR-level dates แล้ว — assignAvailableRoom() ใช้ $this->check_in/check_out เอง
            // 🏨 Phase 5: เปลี่ยนจาก first-available greedy แบบเดิม → RoomAllocator (Hybrid+ v3 cluster algorithm)
            //    เหตุผล: algorithm ใหม่จัดห้องเป็น cluster (ระยะเดินใกล้กันที่สุด) แทนที่จะ assign ทีละ BR แยกกัน
            // 🎫 (26/09/26) ticket 04 map reserved-room-pool: flag `include_reserved` (admin เท่านั้น —
            //    silent-ignore) ส่งต่อให้ allocator ขยาย pool ให้ห้องติด reserved period เข้า cluster ได้
            //    (maintenance ถูกตัดเสมอ) · ไม่ persist ค่า flag — แต่ละ request ตัดสินใจเอง (grill #2)
            $assignedCount = 0;

            $result = app(RoomAllocator::class)->allocate(
                $unassignedRooms,
                IncludeReservedGate::enabled($request)
            );

            if (! $result->ok) {
                $failedTypes = $unassignedRooms->map(fn ($br) => $br->room_type_id)->unique()->implode(', ');
                $winner = $result->winner ?? 'none';
                throw new \Exception("แย่แล้วค่ะนายท่าน! ไม่สามารถจัดห้องเป็น cluster ได้ในช่วงเวลาดังกล่าวค่ะ 😭 (room_type_id: {$failedTypes}) — algorithm: {$winner}");
            }

            foreach ($result->assignments as $brId => $roomId) {
                BookingRoom::where('id', $brId)->update(['room_id' => $roomId]);
                $assignedCount++;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "หนูจัดการระบุเลขห้องอัตโนมัติให้จำนวน {$assignedCount} ห้องเรียบร้อยแล้วค่ะนายท่าน! 🎉",
                // 🌟 (26/08/26): load addon ให้ format booking_rooms เหมือนกับ endpoint อื่นๆ
                // 💳 (22/10/26): confirmations.slipImage — ประวัติส่งสลิป 1:N (array) แนบใน booking
                'booking' => $booking->load(['bookingRooms.addon', 'confirmations.slipImage']),
                // 🏨 debug info: algorithm ที่ชนะ + cluster cost
                'allocation' => [
                    'winner' => $result->winner,
                    'cost' => round($result->cost, 2),
                    'algo' => $result->algo,
                ],
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลการจองนี้ในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to auto-assign room: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'หนูขอโทษค่ะ เกิดข้อผิดพลาด: '.$e->getMessage(),
            ], 422);
        }
    }
}
