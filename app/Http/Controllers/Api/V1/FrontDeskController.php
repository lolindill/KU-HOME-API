<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\HousekeepingTask;
use App\Models\Payment;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FrontDeskController extends Controller
{
    // 🌟 1. Walk-in Booking (Admin only)
    // 🌟 Refactor (25/06/26): BR-level state machine — BR เริ่มที่ checked_in ตรงๆ (skip draft→confirmed)
    public function walkIn(Request $request)
    {
        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
            'nights' => 'required|integer|min:1',
            'room_id' => 'required|uuid|exists:rooms,id',

            // 👥 ข้อมูลผู้เข้าพัก (เก็บใน booking_rooms.guests แทน รองรับหลายคนต่อห้อง)
            'guests' => 'nullable|array',
            'guests.*.title' => 'nullable|string|max:50',
            'guests.*.firstName' => 'nullable|string|max:255',
            'guests.*.lastName' => 'nullable|string|max:255',
            'guests.*.first_name' => 'nullable|string|max:255',
            'guests.*.last_name' => 'nullable|string|max:255',
            'guests.*.name' => 'nullable|string|max:255',
            'guests.*.email' => 'nullable|string|email|max:255',
            'guests.*.phone' => 'nullable|string|max:50',
            'guests.*.nationality' => 'nullable|string|max:100',
            'guests.*.is_ku_member' => 'nullable|boolean',
            // 🧒 Refactor (04/08/26): เปลี่ยนจาก integer count → boolean flag
            'has_children' => 'nullable|boolean',
            // 🧾 Billing fields (04/08/26)
            'billing_address' => 'nullable|string|max:255',
            'billing_comment' => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $room = Room::with('roomType')->lockForUpdate()->findOrFail($validated['room_id']);
            if (! in_array($room->status, ['available', 'prep_checkin'])) {
                throw new \Exception("Room number {$room->room_number} is not ready for walk-in. Current status: {$room->status}");
            }

            $staffUser = User::findOrFail($validated['verified_by']);

            $checkIn = Carbon::now();
            $checkOut = Carbon::now()->addDays($validated['nights']);
            // 🌟 Refactor (22/07/26): อ่าน room rate จาก global_rates (rate_type='daily') แทน room_types
            $totalAmount = GlobalRate::getRoomRate($room->roomType, 'daily') * $validated['nights'];
            $confirmationNo = Booking::generateUniqueConfirmation();

            $booking = Booking::create([
                'user_id' => $staffUser->id,
                'confirmation' => $confirmationNo,
                'source' => 'admin',
                'status' => 'draft', // immediately transitioned to confirmed below
                'total_amount' => $totalAmount,
                // 🌟 Fix (03/07/26): walk-in จ่ายเงินสดเสร็จแล้ว ไม่มี payment deadline
                // (ตั้งเป็น null แทน now() ที่ทำให้ดูเหมือน "หมดอายุทันที")
                'payment_deadline' => null,
            ]);

            $bookingRoom = BookingRoom::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'room_type_id' => $room->room_type_id,
                'room_id' => $room->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'guests' => $validated['guests'] ?? null,
                // 🧒 Refactor (04/08/26): เปลี่ยนจาก integer count → boolean flag
                'has_children' => $validated['has_children'] ?? false,
                // 🧾 Billing fields (04/08/26)
                'billing_address' => $validated['billing_address'] ?? null,
                'billing_comment' => $validated['billing_comment'] ?? null,
                'status' => 'draft',
            ]);

            // 🌟 Refactor (25/06/26): BR สองสเต็ปเพราะ state machine บังคับ draft→confirmed→checked_in
            $bookingRoom->transitionStatus('confirmed', 'admin');
            $bookingRoom->transitionStatus('checked_in', 'admin');

            // 🌟 ใช้ state machine เปลี่ยนสถานะห้องเป็น occupied
            $room->transitionStatusTo('occupied', $validated['verified_by']);

            // 🌟 Booking container → confirmed (walk-in skip draft→paid)
            $booking->transitionStatus('confirmed', 'admin');

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Walk-in booking and Check-in completed!',
                'booking_id' => $booking->id,
                'room_number' => $room->room_number,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Walk-in failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // 🛎️ 2. Check-In (BR-level)
    public function checkIn(Request $request, $bookingId)
    {
        if (is_string($request->input('assigned_rooms'))) {
            $request->merge([
                'assigned_rooms' => [$request->input('assigned_rooms')],
            ]);
        }
        $validated = $request->validate([
            'assigned_rooms' => 'nullable|array',
            'assigned_rooms.*' => 'required|uuid|exists:rooms,id',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);

            $user = $request->user();
            if (! $user) {
                throw new \Exception('Unauthorized: ไม่พบข้อมูลผู้ใช้งานจาก Token ค่ะนายท่าน', 401);
            }

            $userId = $user->id;
            $userRole = $user->role;

            // จับคู่ห้องพัก
            if (! empty($validated['assigned_rooms'])) {
                $assignedRooms = $validated['assigned_rooms'];
                $bookingRooms = $booking->bookingRooms;

                if (count($assignedRooms) !== $bookingRooms->count()) {
                    throw new \Exception('จำนวนห้องที่ส่งมา ('.count($assignedRooms).') ไม่ตรงกับจำนวนห้องที่จองไว้ ('.$bookingRooms->count().') ค่ะนายท่าน');
                }

                foreach ($bookingRooms as $index => $bRoom) {
                    $assignedRoom = Room::lockForUpdate()->findOrFail($assignedRooms[$index]);

                    // ✅ #32 Fixed: ตรวจว่าห้องที่ assign ตรงกับ room type ที่จองไว้
                    if ($assignedRoom->room_type_id !== $bRoom->room_type_id) {
                        throw new \Exception(
                            "ห้องหมายเลข {$assignedRoom->room_number} (ประเภท: {$assignedRoom->roomType->name_en}) ".
                            'ไม่ตรงกับประเภทห้องที่จองไว้ค่ะนายท่าน กรุณาตรวจสอบอีกครั้งนะคะ'
                        );
                    }

                    $bRoom->update(['room_id' => $assignedRooms[$index]]);
                }

                $booking->load('bookingRooms');
            }

            $roomUpdates = [];

            foreach ($booking->bookingRooms as $bRoom) {
                if (! $bRoom->room_id) {
                    throw new \Exception("ไม่สามารถเช็คอินได้ค่ะ รายการจอง ID {$bRoom->id} ยังไม่ได้ระบุหมายเลขห้องพักค่ะนายท่าน");
                }

                $room = Room::lockForUpdate()->findOrFail($bRoom->room_id);

                // 🌟 ใช้ state machine เปลี่ยนสถานะห้อง
                $room->transitionStatusTo('occupied', $userId);

                // 🌟 Refactor (25/06/26): BR state machine → checked_in
                //    BR ต้องเป็น confirmed ก่อน — ถ้ายังเป็น draft ให้ confirmed ก่อน
                if ($bRoom->status === 'draft') {
                    $bRoom->transitionStatus('confirmed', 'admin');
                }
                $bRoom->transitionStatus('checked_in', 'admin');

                $roomUpdates[] = [
                    'room_number' => $room->room_number,
                    'new_status' => $room->status,
                ];
            }

            // 🌟 Fix scrutinize (29/06/26): ลบ bypass state machine
            // Booking container ต้องผ่าน flow ปกติ: draft → (record payment) → paid → (check-in) → confirmed
            // ห้ามข้ามขั้นตอนการชำระเงิน — staff ต้อง record payment ก่อนทุกครั้ง
            if ($booking->status === 'draft') {
                throw new \Exception(
                    'ไม่สามารถเช็คอินได้ค่ะนายท่าน เนื่องจากรายการจองยังไม่ได้รับชำระเงิน '.
                    'กรุณาบันทึกการรับชำระเงินก่อน (draft → paid) แล้วจึงกลับมาเช็คอินนะคะ'
                );
            } elseif ($booking->status === 'paid') {
                $booking->transitionStatus('confirmed', $userRole);
            }
            // confirmed: ไม่ต้อง transition อะไรเพิ่ม

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-in completed successfully! 🎉',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
                'room_updates' => $roomUpdates,
            ], 200);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            $modelName = class_basename($e->getModel());

            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน",
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Check-in failed: '.$e->getMessage());
            $statusCode = $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 400;

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }

    // 🧹 3. Check-Out (BR-level)
    public function checkOut(Request $request, $bookingId)
    {
        // 🌟 Fix L4 (03/07/26): defense-in-depth — ตรวจ role ใน controller ด้วย ไม่พึ่ง middleware อย่างเดียว
        $user = $request->user();
        if (! $user || $user->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินเท่านั้นถึงจะ Check-out ได้ค่ะนายท่าน',
            ], 403);
        }

        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);

            // 🌟 Refactor (25/06/26): เช็คที่ BR-level ว่าทุกห้อง checked_in หรือไม่
            $allCheckedIn = $booking->bookingRooms->every(fn ($br) => $br->status === 'checked_in');
            if (! $allCheckedIn) {
                throw new \Exception('ยังมีห้องที่ไม่ได้ Check-in อยู่ค่ะ จะ Check-out ไม่ได้น้า');
            }

            // ตรวจสอบยอดชำระเงิน
            $totalPaid = Payment::where('booking_id', $bookingId)
                ->where('status', 'completed')
                ->sum('amount');

            if ($totalPaid < $booking->total_amount) {
                $pendingAmount = $booking->total_amount - $totalPaid;
                throw new \Exception("ยังมีรายการค้างชำระอยู่ {$pendingAmount} บาทค่ะนายท่าน กรุณารับชำระเงินก่อนนะคะ");
            }

            $roomUpdates = [];

            foreach ($booking->bookingRooms as $bRoom) {
                if (! $bRoom->room_id) {
                    continue;
                }

                $room = Room::findOrFail($bRoom->room_id);

                // 🌟 Refactor (25/06/26): BR state machine → checked_out
                $bRoom->transitionStatus('checked_out', 'admin');

                // 🌟 ใช้ state machine เปลี่ยนสถานะห้อง → checkout_makeup
                $room->transitionStatusTo('checkout_makeup', $validated['verified_by']);

                // 🧹 Phase A (15/07/26): สร้าง housekeeping task — เพิ่ม task_type + duplicate guard (Fix S-B2)
                //    ก่อนสร้าง → เช็คว่ามี active task ในห้องนี้อยู่แล้วหรือไม่ ถ้ามีไม่สร้างซ้ำ
                $activeExists = HousekeepingTask::where('room_id', $room->id)
                    ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])
                    ->exists();

                if (! $activeExists) {
                    // checkout_then_in = checkout ที่มี confirmed booking check_in วันนี้ ใน room_type เดียวกัน
                    // (กรณีรีบเคลียร์ห้องเพราะแขกใหม่จะเข้าทันที)
                    $rush = BookingRoom::where('room_type_id', $room->room_type_id)
                        ->whereDate('check_in', Carbon::today())
                        ->where('status', 'confirmed')
                        ->exists();

                    $task = HousekeepingTask::create([
                        'id' => Str::uuid(),
                        'room_id' => $room->id,
                        'task_type' => $rush ? 'checkout_then_in' : 'checkout',
                        'status' => 'unassigned',
                        'notes' => $validated['notes'] ?? 'Auto-generated from Check-out',
                    ]);
                } else {
                    $task = null;
                }

                $roomUpdates[] = [
                    'room_number' => $room->room_number,
                    'room_status' => $room->status,
                    'housekeeping_task_id' => $task?->id,
                ];
            }

            // 🌟 Auto-sync booking container → complete ถ้า BR ทุกห้องจบแล้ว
            $booking->syncStatusFromRooms();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-out completed successfully. สร้างงานให้ทีมแม่บ้านเรียบร้อยค่ะ!',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
                'room_updates' => $roomUpdates,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Check-out failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    // 🚫 5. Mark No-Show (BR-level) — รองรับ partial (เลือกเฉพาะห้องได้)
    public function markNoShow(Request $request, $bookingId)
    {
        // 🌟 Fix L4 (03/07/26): defense-in-depth — ตรวจ role ใน controller ด้วย
        $user = $request->user();
        if (! $user || $user->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินเท่านั้นถึงจะ Mark No-Show ได้ค่ะนายท่าน',
            ], 403);
        }

        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
            // 🌟 Fix (03/07/26): รองรับ partial no-show — ถ้าไม่ส่ง = mark ทุกห้องใน booking
            'booking_room_ids' => 'nullable|array',
            'booking_room_ids.*' => 'required|uuid|exists:booking_rooms,id',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);

            // 🛡️ Guard 1: booking ที่จบไปแล้ว ทำอะไรต่อไม่ได้
            if ($booking->status === 'complete') {
                throw new \Exception('รายการจองนี้จบสิ้นไปแล้วค่ะ ไม่สามารถ mark No-Show ได้');
            }

            // 🌟 เลือก BR ที่จะ mark (ถ้าไม่ส่ง booking_room_ids = ทุกห้อง)
            $targetRooms = ! empty($validated['booking_room_ids'])
                ? $booking->bookingRooms->whereIn('id', $validated['booking_room_ids'])
                : $booking->bookingRooms;

            if ($targetRooms->isEmpty()) {
                throw new \Exception('ไม่พบห้องที่ระบุในรายการจองนี้ค่ะนายท่าน');
            }

            // 🛡️ Guard 2: ทุกห้องที่จะ mark ต้องเป็น confirmed เท่านั้น
            // (draft ยังไม่ยืนยัน / checked_in เข้าพักแล้ว / checked_out / no_show จบไปแล้ว)
            foreach ($targetRooms as $bRoom) {
                if ($bRoom->status !== 'confirmed') {
                    throw new \Exception(
                        "ไม่สามารถ mark No-Show ได้ค่ะนายท่าน เนื่องจากห้องมีสถานะ '{$bRoom->status}' ".
                        "(ต้องเป็น 'confirmed' เท่านั้น) กรุณาตรวจสอบอีกครั้งนะคะ"
                    );
                }
            }

            // 🌟 Fix (03/07/26): ถ้า booking ยังเป็น paid (จ่ายแล้วยังไม่ confirm) → confirmed ก่อน
            // รองรับสถานการณ์จริง: ลูกค้าจ่ายเงินแล้วแต่ไม่มา ระบบยังปิดการจองได้
            if ($booking->status === 'paid') {
                $booking->transitionStatus('confirmed', 'admin');
            }

            // Mark no-show ทีละห้อง (ใช้ BR state machine)
            foreach ($targetRooms as $bRoom) {
                $bRoom->transitionStatus('no_show', 'admin');
            }

            // 🌟 Auto-sync booking container → complete (ถ้าทุกห้องจบแล้ว)
            // กรณี partial no-show (ยังมีห้อง confirmed/checked_in) → booking ยังเป็น confirmed รอเหลือห้องจบ
            $booking->refresh();
            $booking->syncStatusFromRooms();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Mark No-Show สำเร็จแล้วค่ะนายท่าน',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
            ], 200);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            $modelName = class_basename($e->getModel());

            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน",
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Mark no-show failed: '.$e->getMessage());
            $statusCode = $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 400;

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }

    // 💸 4. บันทึกการรับชำระเงิน
    public function recordPayment(StorePaymentRequest $request, $bookingId)
    {
        // 🌟 Fix L4 (03/07/26): defense-in-depth — ตรวจ role ใน controller ด้วย
        $user = $request->user();
        if (! $user || $user->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินเท่านั้นถึงจะบันทึกการรับชำระเงินได้ค่ะนายท่าน',
            ], 403);
        }

        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            $payment = Payment::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'amount' => $validated['amount'],
                'status' => 'completed',
                'reference_number' => $validated['reference_number'] ?? null,
                'received_by' => $validated['received_by'] ?? null,
            ]);

            // ✅ #18 Fixed: อัปเดต is_paid + booking status เมื่อชำระครบแล้ว
            $totalPaid = Payment::where('booking_id', $booking->id)
                ->where('status', 'completed')
                ->sum('amount');

            if ($totalPaid >= $booking->total_amount && ! $booking->is_paid) {
                // 🌟 Fix H3 (03/07/26): unify is_paid write ให้เป็นวิธีเดียวกับ webhook
                // ใช้ Eloquent update (PHP boolean) แทน DB::raw('TRUE') — portable ข้าม DB + trigger events
                $booking->update(['is_paid' => true]);

                // 🌟 Refactor (25/06/26): draft → paid (container)
                if ($booking->status === 'draft') {
                    $booking->transitionStatus('paid', 'admin');
                }

                // ❄️ FROZEN (24/07/26): Receipt table deprecated — ไม่สร้าง receipt row ใหม่อีกต่อไป
                //    ใช้ booking_confirmations table แทน (ดู POST /bookings/{id}/confirm + admin verify)
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment recorded successfully!',
                'payment' => $payment,
                'booking_is_paid' => $booking->fresh()->is_paid,
                'booking_status' => $booking->fresh()->status,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Record payment failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
