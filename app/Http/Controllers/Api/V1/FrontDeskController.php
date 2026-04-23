<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\User; 
use App\Models\Payment; 
use App\Models\HousekeepingTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class FrontDeskController extends Controller
{
    // 🌟 1. API สำหรับ Process 3.1 Manage Walk-in Booking
    public function walkIn(Request $request)
    {
        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
            'guest_name' => 'required|string',
            'guest_phone' => 'required|string',
            'nights' => 'required|integer|min:1',
            'room_id' => 'required|uuid|exists:rooms,id', 
        ]);

        try {
            DB::beginTransaction();

            // 1. Check Availability & Update Room
            $room = Room::with('roomType')->findOrFail($validated['room_id']);
            if (!in_array($room->status, ['available', 'prep_checkIn'])) {
                throw new \Exception("Room number {$room->room_number} is not ready for walk-in. Current status: {$room->status}");
            }

            // 2. Read and Create User
            $guestUser = User::firstOrCreate(
                ['phone' => $validated['guest_phone']],
                [
                    'id' => Str::uuid(),
                    'name' => $validated['guest_name'],
                    'role' => 'guest',
                    'password' => bcrypt(Str::random(10)) 
                ]
            );

            // 3. Save Booking
            $checkIn = Carbon::now();
            $checkOut = Carbon::now()->addDays($validated['nights']);
            $totalAmount = $room->roomType->rate_daily_general * $validated['nights'];
            $confirmationNo = Carbon::now()->format('Ym') . '-W' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

            $booking = Booking::create([
                'id' => Str::uuid(),
                'user_id' => $guestUser->id,
                'confirmation_no' => $confirmationNo,
                'booking_type' => 'walk-in',
                'source' => 'admin', 
                'status' => 'checked_in', 
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'adults' => 1,
                'total_amount' => $totalAmount,
            ]);

            BookingRoom::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'room_type_id' => $room->room_type_id,
                'room_id' => $room->id,
                'room_price' => $room->roomType->rate_daily_general,
                'subtotal' => $totalAmount
            ]);

            // อัปเดตสถานะห้องเป็น Occupied ทันที
            $room->update([
                'status' => 'Occupied',
                'status_updated_at' => Carbon::now(),
                'status_updated_by' => $validated['verified_by']
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Walk-in booking and Check-in completed!',
                'data' => ['booking_id' => $booking->id, 'room_number' => $room->room_number]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    // 🛎️ 2. API สำหรับ Process 3.2 Process Check-In (ใช้ Bearer Token ระบุตัวตน)
    public function checkIn(Request $request, $bookingId)
    {   
        // ✨ เวทมนตร์ของน้องเมด: ถ้าส่งมาเป็น String เดี่ยวๆ ให้แปลงเป็น Array อัตโนมัติค่ะ
        if (is_string($request->input('assigned_rooms'))) {
            $request->merge([
                'assigned_rooms' => [$request->input('assigned_rooms')]
            ]);
        }
        $validated = $request->validate([
            // 🌟 ลบ verified_by ออกแล้วค่ะ! เราจะดึงข้อมูลจาก Token แทน
            'assigned_rooms'   => 'nullable|array',
            'assigned_rooms.*' => 'required|uuid|exists:rooms,id',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);
            
            // 🗝️ ดึงข้อมูล User จาก Token ได้เลยค่ะ มั่นใจ ปลอดภัย 100%
            $user = $request->user();
            if (!$user) {
                throw new \Exception("Unauthorized: ไม่พบข้อมูลผู้ใช้งานจาก Token ค่ะนายท่าน", 401);
            }
            
            $userId = $user->id;
            $userRole = $user->role;

            // ✨ จับคู่ห้องพักอัตโนมัติ
            if (!empty($validated['assigned_rooms'])) {
                $assignedRooms = $validated['assigned_rooms'];
                $bookingRooms = $booking->bookingRooms;

                if (count($assignedRooms) !== $bookingRooms->count()) {
                    throw new \Exception("จำนวนห้องที่ส่งมา (".count($assignedRooms).") ไม่ตรงกับจำนวนห้องที่จองไว้ (".$bookingRooms->count().") ค่ะนายท่าน");
                }

                foreach ($bookingRooms as $index => $bRoom) {
                    $bRoom->update(['room_id' => $assignedRooms[$index]]);
                }
                
                $booking->load('bookingRooms'); 
            }

            $roomUpdates = [];

            foreach ($booking->bookingRooms as $bRoom) {
                if (!$bRoom->room_id) {
                    throw new \Exception("หนูไม่สามารถทำการเช็คอินได้ค่ะ เพราะรายการจอง ID {$bRoom->id} ยังไม่ได้ระบุหมายเลขห้องพักเลยค่ะนายท่าน");
                }

                $room = Room::findOrFail($bRoom->room_id);

                // 🌟 สั่งให้ Room Model เปลี่ยนสถานะห้อง และบันทึก $userId จาก Token ลงไปค่ะ
                $room->transitionStatusTo('Occupied', $userId);

                $roomUpdates[] = [
                    'room_number' => $room->room_number,
                    'new_status' => $room->status
                ];
            }

            // สั่งให้ Booking Model เปลี่ยนสถานะเป็น checked_in
            $booking->transitionStatus('checked_in', $userRole);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-in completed successfully! ห้องพักพร้อมให้บริการแขกแล้วค่ะนายท่าน 🎉',
                'data' => [
                    'booking_id' => $booking->id,
                    'booking_status' => $booking->status,
                    'room_updates' => $roomUpdates
                ]
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
            $statusCode = $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 400;

            return response()->json([
                'status' => 'error',
                'message' => 'Check-in failed: ' . $e->getMessage()
            ], $statusCode);
        }
    }

    // 🧹 3. API สำหรับ Process 3.3 Process Check-Out 
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

            // 🌟 ตรวจสอบยอดชำระเงิน
            $totalPaid = Payment::where('booking_id', $bookingId)
                            ->where('status', 'completed')
                            ->sum('amount');
                            
            if ($totalPaid < $booking->total_amount) {
                $pendingAmount = $booking->total_amount - $totalPaid;
                throw new \Exception("ยังมีรายการค้างชำระอยู่ {$pendingAmount} บาทค่ะนายท่าน กรุณารับชำระเงินก่อนนะคะ");
            }

            // เปลี่ยนสถานะใบจองเป็น "เช็คเอาท์แล้ว" 
            $booking->update(['status' => 'checked_out']);

            $bookingRooms = BookingRoom::where('booking_id', $bookingId)->whereNotNull('room_id')->get();
            $roomUpdates = [];

            foreach ($bookingRooms as $bRoom) {
                $room = Room::findOrFail($bRoom->room_id);

                // 🛑 กฎของนายท่าน: Occupied => checkout_makeup
                if (strtolower($room->status) !== 'occupied') {
                     throw new \Exception("หมายเลขห้อง {$room->room_number} ไม่ได้อยู่ในสถานะ Occupied ค่ะ (สถานะปัจจุบัน: {$room->status})");
                }

                $room->update([
                    'status' => 'checkout_makeup',
                    'status_updated_at' => Carbon::now(),
                    'status_updated_by' => $validated['verified_by']
                ]);

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
                'data' => [
                    'booking_id' => $booking->id,
                    'booking_status' => $booking->status,
                    'room_updates' => $roomUpdates
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Check-out failed: ' . $e->getMessage()
            ], 400);
        }
    }

    // 💸 4. API สำหรับบันทึกการรับชำระเงิน
    public function recordPayment(Request $request, $bookingId)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:cash,credit_card,transfer',
            'reference_number' => 'nullable|string',
            'received_by' => 'required|uuid|exists:users,id'
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            $payment = Payment::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'status' => 'completed',
                'reference_number' => $validated['reference_number'],
                'received_by' => $validated['received_by']
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment recorded successfully!',
                'data' => $payment
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to record payment: ' . $e->getMessage()
            ], 400);
        }
    }
}