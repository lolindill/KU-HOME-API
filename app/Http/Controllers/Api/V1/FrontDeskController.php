<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\User;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\HousekeepingTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

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
            'guests.*.name' => 'nullable|string|max:255',
            'guests.*.nationality' => 'nullable|string|max:100',
            'guests.*.is_ku_member' => 'nullable|boolean',
            'children' => 'nullable|integer|min:0',
        ]);

        try {
            DB::beginTransaction();

            $room = Room::with('roomType')->findOrFail($validated['room_id']);
            if (!in_array($room->status, ['available', 'prep_checkin'])) {
                throw new \Exception("Room number {$room->room_number} is not ready for walk-in. Current status: {$room->status}");
            }

            $staffUser = User::findOrFail($validated['verified_by']);

            $checkIn = Carbon::now();
            $checkOut = Carbon::now()->addDays($validated['nights']);
            $totalAmount = $room->roomType->rate_daily_general * $validated['nights'];
            $confirmationNo = Booking::generateUniqueConfirmation();

            $booking = Booking::create([
                'user_id' => $staffUser->id,
                'confirmation' => $confirmationNo,
                'source' => 'admin',
                'status' => 'draft', // immediately transitioned to confirmed below
                'total_amount' => $totalAmount,
                'payment_deadline' => Carbon::now(),
            ]);

            $bookingRoom = BookingRoom::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'room_type_id' => $room->room_type_id,
                'room_id' => $room->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'guests' => $validated['guests'] ?? null,
                'children' => $validated['children'] ?? 0,
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
            Log::error("Walk-in failed: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // 🛎️ 2. Check-In (BR-level)
    public function checkIn(Request $request, $bookingId)
    {   
        if (is_string($request->input('assigned_rooms'))) {
            $request->merge([
                'assigned_rooms' => [$request->input('assigned_rooms')]
            ]);
        }
        $validated = $request->validate([
            'assigned_rooms'   => 'nullable|array',
            'assigned_rooms.*' => 'required|uuid|exists:rooms,id',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);
            
            $user = $request->user();
            if (!$user) {
                throw new \Exception("Unauthorized: ไม่พบข้อมูลผู้ใช้งานจาก Token ค่ะนายท่าน", 401);
            }
            
            $userId = $user->id;
            $userRole = $user->role;

            // จับคู่ห้องพัก
            if (!empty($validated['assigned_rooms'])) {
                $assignedRooms = $validated['assigned_rooms'];
                $bookingRooms = $booking->bookingRooms;

                if (count($assignedRooms) !== $bookingRooms->count()) {
                    throw new \Exception("จำนวนห้องที่ส่งมา (".count($assignedRooms).") ไม่ตรงกับจำนวนห้องที่จองไว้ (".$bookingRooms->count().") ค่ะนายท่าน");
                }

                foreach ($bookingRooms as $index => $bRoom) {
                    $assignedRoom = Room::findOrFail($assignedRooms[$index]);

                    // ✅ #32 Fixed: ตรวจว่าห้องที่ assign ตรงกับ room type ที่จองไว้
                    if ($assignedRoom->room_type_id !== $bRoom->room_type_id) {
                        throw new \Exception(
                            "ห้องหมายเลข {$assignedRoom->room_number} (ประเภท: {$assignedRoom->roomType->name_en}) " .
                            "ไม่ตรงกับประเภทห้องที่จองไว้ค่ะนายท่าน กรุณาตรวจสอบอีกครั้งนะคะ"
                        );
                    }

                    $bRoom->update(['room_id' => $assignedRooms[$index]]);
                }
                
                $booking->load('bookingRooms'); 
            }

            $roomUpdates = [];

            foreach ($booking->bookingRooms as $bRoom) {
                if (!$bRoom->room_id) {
                    throw new \Exception("ไม่สามารถเช็คอินได้ค่ะ รายการจอง ID {$bRoom->id} ยังไม่ได้ระบุหมายเลขห้องพักค่ะนายท่าน");
                }

                $room = Room::findOrFail($bRoom->room_id);

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
                    'new_status' => $room->status
                ];
            }

            // 🌟 Booking container → confirmed (ถ้ายังเป็น draft/paid)
            if (in_array($booking->status, ['draft', 'paid'])) {
                if ($booking->status === 'draft') {
                    // paid transition needs admin — walk around via direct save for system-equivalent
                    $booking->status = 'confirmed';
                    $booking->save();
                } else {
                    $booking->transitionStatus('confirmed', $userRole);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-in completed successfully! 🎉',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
                'room_updates' => $roomUpdates
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            $modelName = class_basename($e->getModel()); 
            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน"
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Check-in failed: " . $e->getMessage());
            $statusCode = $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 400;

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $statusCode);
        }
    }

    // 🧹 3. Check-Out (BR-level)
    public function checkOut(Request $request, $bookingId)
    {
        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
            'notes' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);

            // 🌟 Refactor (25/06/26): เช็คที่ BR-level ว่าทุกห้อง checked_in หรือไม่
            $allCheckedIn = $booking->bookingRooms->every(fn ($br) => $br->status === 'checked_in');
            if (!$allCheckedIn) {
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
                if (!$bRoom->room_id) continue;

                $room = Room::findOrFail($bRoom->room_id);

                // 🌟 Refactor (25/06/26): BR state machine → checked_out
                $bRoom->transitionStatus('checked_out', 'admin');

                // 🌟 ใช้ state machine เปลี่ยนสถานะห้อง → checkout_makeup
                $room->transitionStatusTo('checkout_makeup', $validated['verified_by']);

                // ออกใบสั่งงานแม่บ้านทันที
                $task = HousekeepingTask::create([
                    'id' => Str::uuid(),
                    'room_id' => $room->id,
                    'status' => 'pending',
                    'notes' => $validated['notes'] ?? 'Auto-generated from Check-out',
                    'checked_out_at' => Carbon::now()
                ]);

                $roomUpdates[] = [
                    'room_number' => $room->room_number,
                    'room_status' => $room->status,
                    'housekeeping_task_id' => $task->id
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
                'room_updates' => $roomUpdates
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Check-out failed: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // 🚫 5. Mark No-Show (BR-level) — เพิ่มใหม่
    public function markNoShow(Request $request, $bookingId)
    {
        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);

            foreach ($booking->bookingRooms as $bRoom) {
                // 🌟 Refactor (25/06/26): BR confirmed → no_show
                if ($bRoom->status === 'confirmed') {
                    $bRoom->transitionStatus('no_show', 'admin');
                }
            }

            // 🌟 Auto-sync booking container → complete (no_show ก็ถือว่าจบการจอง)
            $booking->syncStatusFromRooms();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Booking marked as No-Show แล้วค่ะนายท่าน',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Mark no-show failed: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // 💸 4. บันทึกการรับชำระเงิน
    public function recordPayment(StorePaymentRequest $request, $bookingId)
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            $payment = Payment::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'status' => 'completed',
                'reference_number' => $validated['reference_number'] ?? null,
                'received_by' => $validated['received_by'] ?? null
            ]);

            // ✅ #18 Fixed: อัปเดต is_paid + booking status เมื่อชำระครบแล้ว
            $totalPaid = Payment::where('booking_id', $booking->id)
                ->where('status', 'completed')
                ->sum('amount');

            if ($totalPaid >= $booking->total_amount && !$booking->is_paid) {
                // ✅ #36 Fix: PostgreSQL boolean strict — ใช้ DB::raw('TRUE') แทน PHP true
                DB::table('bookings')->where('id', $booking->id)->update([
                    'is_paid' => DB::raw('TRUE'),
                    'updated_at' => now(),
                ]);
                $booking->refresh();

                // 🌟 Refactor (25/06/26): draft → paid (container)
                if ($booking->status === 'draft') {
                    $booking->transitionStatus('paid', 'admin');
                }

                // สร้าง Receipt
                Receipt::create([
                    'receipt_no' => Receipt::generateUniqueReceiptNo(),
                    'booking_id' => $booking->id,
                    'payment_id' => $payment->id,
                    'amount' => $payment->amount,
                    'billing_name' => $booking->primary_guest_name ?? 'Customer',
                ]);
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
            Log::error("Record payment failed: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}