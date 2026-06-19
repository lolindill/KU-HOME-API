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
    // 🌟 Refactor (18/06/26): ใช้ staff user (verified_by) เป็นผู้ถือ booking — ข้อมูลแขกไปอยู่ใน booking_rooms.guests
    // 🌟 แขก walk-in ไม่ต้องสมัครสมาชิก ไม่ใช้ member/guest user อีกต่อไป
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

            // 🌟 Refactor (18/06/26): ไม่สร้าง guest user แล้ว — ใช้ staff (verified_by) เป็นผู้ถือ booking
            $staffUser = User::findOrFail($validated['verified_by']);

            $checkIn = Carbon::now();
            $checkOut = Carbon::now()->addDays($validated['nights']);
            $totalAmount = $room->roomType->rate_daily_general * $validated['nights'];
            $confirmationNo = Booking::generateUniqueConfirmation();

            $booking = Booking::create([
                'user_id' => $staffUser->id,
                'confirmation' => $confirmationNo,
                'source' => 'admin',
                'status' => 'draft', // immediately transitioned to checked_in below
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                // 🌟 Refactor (18/06/26): ข้อมูลผู้เข้าพักย้ายไป booking_rooms แล้ว
                'total_amount' => $totalAmount,
                'payment_deadline' => Carbon::now(),
            ]);

            BookingRoom::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'room_type_id' => $room->room_type_id,
                'room_id' => $room->id,
                // 🌟 Refactor (18/06/26): เก็บข้อมูลผู้เข้าพักที่นี่
                'guests' => $validated['guests'] ?? null,
                'children' => $validated['children'] ?? 0,
            ]);

            // 🌟 ใช้ state machine เปลี่ยนสถานะห้องเป็น occupied
            $room->transitionStatusTo('occupied', $validated['verified_by']);

            // 🌟 ใช้ state machine เปลี่ยนสถานะ booking เป็น checked_in
            $booking->transitionStatus('checked_in', 'admin');

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

    // 🛎️ 2. Check-In
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

                $roomUpdates[] = [
                    'room_number' => $room->room_number,
                    'new_status' => $room->status
                ];
            }

            // 🌟 ใช้ state machine เปลี่ยนสถานะ booking
            $booking->transitionStatus('checked_in', $userRole);

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

    // 🧹 3. Check-Out
    public function checkOut(Request $request, $bookingId)
    {
        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
            'notes' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            if ($booking->status !== 'checked_in') {
                throw new \Exception('แขกยังไม่ได้ Check-in เลยค่ะ จะ Check-out ไม่ได้น้า');
            }

            // ตรวจสอบยอดชำระเงิน
            $totalPaid = Payment::where('booking_id', $bookingId)
                            ->where('status', 'completed')
                            ->sum('amount');
                            
            if ($totalPaid < $booking->total_amount) {
                $pendingAmount = $booking->total_amount - $totalPaid;
                throw new \Exception("ยังมีรายการค้างชำระอยู่ {$pendingAmount} บาทค่ะนายท่าน กรุณารับชำระเงินก่อนนะคะ");
            }

            // 🌟 ใช้ state machine เปลี่ยนสถานะ booking
            $booking->transitionStatus('checked_out', 'admin');

            $bookingRooms = BookingRoom::where('booking_id', $bookingId)->whereNotNull('room_id')->get();
            $roomUpdates = [];

            foreach ($bookingRooms as $bRoom) {
                $room = Room::findOrFail($bRoom->room_id);

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

                // ถ้า booking ยังเป็น draft → เปลี่ยนเป็น paid (สถานะที่รอ admin confirm)
                // แต่ถ้าเป็น checked_in หรือ confirmed อยู่แล้ว → ไม่ต้องเปลี่ยน status
                if ($booking->status === 'draft') {
                    $booking->transitionStatus('paid', 'admin');
                }

                // สร้าง Receipt
                // 🌟 Refactor (18/06/26): ใช้ primary_guest_name (จาก booking_rooms) แทน guest_name ที่ถูกลบไปแล้ว
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