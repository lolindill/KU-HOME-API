<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\User; // 🌟 เพิ่ม Model User สำหรับ Walk-in
use App\Models\Payment; // 🌟 เพิ่ม Model Payment สำหรับ Check-out
use App\Models\HousekeepingTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class FrontDeskController extends Controller
{
    // 🌟 1. [เพิ่มใหม่] API สำหรับ Process 3.1 Manage Walk-in Booking
    public function walkIn(Request $request)
    {
        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id', // Admin ที่ทำรายการ
            'guest_name' => 'required|string',
            'guest_phone' => 'required|string',
            'nights' => 'required|integer|min:1',
            'room_id' => 'required|uuid|exists:rooms,id', // เลือกห้องที่ว่างให้เลย
        ]);

        try {
            DB::beginTransaction();

            // 1. Check Availability & Update Room (D2: ROOMS)
            $room = Room::with('roomType')->findOrFail($validated['room_id']);
            if ($room->status !== 'available') {
                throw new \Exception("Room number {$room->room_number} is not available for walk-in.");
            }

            // 2. Read and Create User (D1: USERS) - สร้าง Guest User แบบไวๆ
            $guestUser = User::firstOrCreate(
                ['phone' => $validated['guest_phone']],
                [
                    'id' => Str::uuid(),
                    'name' => $validated['guest_name'],
                    'role' => 'guest',
                    'password' => bcrypt(Str::random(10)) // สุ่มรหัสผ่านไปก่อน
                ]
            );

            // 3. Save Booking (D3: BOOKINGS) - ระบุ Source เป็น admin/walk-in
            $checkIn = Carbon::now();
            $checkOut = Carbon::now()->addDays($validated['nights']);
            $totalAmount = $room->roomType->rate_daily_general * $validated['nights'];
            $confirmationNo = Carbon::now()->format('Ym') . '-W' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

            $booking = Booking::create([
                'id' => Str::uuid(),
                'user_id' => $guestUser->id,
                'confirmation_no' => $confirmationNo,
                'booking_type' => 'walk-in',
                'source' => 'admin', // 🌟 ตามแผนภาพเป๊ะ
                'status' => 'checked_in', // เช็คอินทันที
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
                'status' => 'occupied',
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

    // 🛎️ 2. API สำหรับ Process 3.2 Process Check-In (ของเดิมของนายท่าน ดีอยู่แล้วค่ะ!)
    public function checkIn(Request $request, $bookingId)
    {
        // ... (โค้ดเดิมที่นายท่านเขียนไว้ เป๊ะมากแล้วค่ะ ไม่ต้องแก้) ...
    }

    // 🧹 3. API สำหรับ Process 3.3 Process Check-Out (เพิ่มเช็ค Payment)
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
                throw new \Exception('Booking is not checked in. Cannot process check-out.');
            }

            // 🌟 [ส่วนที่เพิ่มใหม่] Read Booking and Validate Payment (D4: PAYMENTS)
            // เช็คว่ามียอดค้างชำระหรือไม่ (สมมติว่าถ้ายอดที่จ่ายแล้ว น้อยกว่า ยอดรวม ถือว่ายังจ่ายไม่ครบ)
            $totalPaid = \App\Models\Payment::where('booking_id', $bookingId)
                            ->where('status', 'completed')
                            ->sum('amount');
                            
            if ($totalPaid < $booking->total_amount) {
                $pendingAmount = $booking->total_amount - $totalPaid;
                throw new \Exception("Cannot check-out. There is a pending payment of {$pendingAmount} THB.");
            }

            // เปลี่ยนสถานะใบจองเป็น "เช็คเอาท์แล้ว" 
            $booking->update(['status' => 'checked_out']);

            // ค้นหาห้องและสร้าง Housekeeping Task
            $bookingRooms = BookingRoom::where('booking_id', $bookingId)->whereNotNull('room_id')->get();
            $roomUpdates = [];

            foreach ($bookingRooms as $bRoom) {
                $room = \App\Models\Room::findOrFail($bRoom->room_id);

                $room->update([
                    'status' => 'checkout_makeup',
                    'status_updated_at' => Carbon::now(),
                    'status_updated_by' => $validated['verified_by']
                ]);

                $task = HousekeepingTask::create([
                    'id' => \Illuminate\Support\Str::uuid(),
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
                'message' => 'Check-out completed successfully. Housekeeping tasks generated!',
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

    // 💸 API สำหรับบันทึกการรับชำระเงิน (ต้องทำก่อน Check-out เพื่อให้ยอดครบ)
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

            // บันทึกประวัติการจ่ายเงินลง D4: PAYMENTS
            $payment = \App\Models\Payment::create([
                'id' => \Illuminate\Support\Str::uuid(),
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