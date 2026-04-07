<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Room;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // 🛎️ 1. API ดูสถานะห้องพัก (ว่าง / มีแขกเข้าพัก)
    public function roomStatus(Request $request)
    {
        // รับค่า filter จาก URL เช่น ?status=available
        $statusFilter = $request->query('status');

        $rooms = Room::with('roomType')
            ->when($statusFilter, function ($query, $statusFilter) {
                return $query->where('status', $statusFilter);
            })
            ->orderBy('room_number')
            ->get()
            ->map(function ($room) {
                return [
                    'room_number' => $room->room_number,
                    'room_type' => $room->roomType->name_en ?? 'Unknown',
                    'status' => $room->status, // available, occupied, checkout_makeup
                    'last_updated' => $room->status_updated_at ? Carbon::parse($room->status_updated_at)->diffForHumans() : '-'
                ];
            });

        return response()->json([
            'status' => 'success',
            'message' => 'Room status list fetched successfully',
            'total_rooms' => $rooms->count(),
            'data' => $rooms
        ]);
    }

    // 🧹 2. API ดูสถานะงานทำความสะอาดของแม่บ้าน
    public function cleaningTasks(Request $request)
    {
        $tasks = HousekeepingTask::with('room.roomType')
            ->whereIn('status', ['pending', 'in_progress']) // ดึงเฉพาะงานที่ยังไม่เสร็จ
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($task) {
                return [
                    'task_id' => $task->id,
                    'room_number' => $task->room->room_number ?? 'N/A',
                    'room_type' => $task->room->roomType->name_en ?? 'N/A',
                    'task_status' => $task->status,
                    'notes' => $task->notes,
                    'requested_at' => Carbon::parse($task->checked_out_at ?? $task->created_at)->format('Y-m-d H:i')
                ];
            });

        return response()->json([
            'status' => 'success',
            'message' => 'Housekeeping tasks fetched successfully',
            'pending_tasks' => $tasks->count(),
            'data' => $tasks
        ]);
    }

    // 📅 3. API ปฏิทินการเข้าพัก (ดึงข้อมูลตามเดือน/ปี)
    // 📅 3. API ปฏิทินการเข้าพัก (ดึงข้อมูลตามเดือน/ปี)
    public function bookingCalendar(Request $request)
    {
        $month = $request->query('month', Carbon::now()->month);
        $year = $request->query('year', Carbon::now()->year);

        $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $endOfMonth = Carbon::createFromDate($year, $month, 1)->endOfMonth()->endOfDay();

        // 🚨 แก้ไขชื่อฟิลด์เป็น check_in และ check_out ตาม Migration ของนายท่านแล้วค่ะ!
        $bookings = Booking::with(['bookingRooms.roomType', 'bookingRooms.room'])
            ->where(function ($query) use ($startOfMonth, $endOfMonth) {
                $query->whereBetween('check_in', [$startOfMonth, $endOfMonth])
                      ->orWhereBetween('check_out', [$startOfMonth, $endOfMonth])
                      ->orWhere(function ($q) use ($startOfMonth, $endOfMonth) {
                          $q->where('check_in', '<=', $startOfMonth)
                            ->where('check_out', '>=', $endOfMonth);
                      });
            })
            ->orderBy('check_in', 'asc')
            ->get()
            ->map(function ($booking) {
                return [
                    'booking_id' => $booking->id,
                    'confirmation_no' => $booking->confirmation_no, // เพิ่มเลข Confirm ให้ด้วยค่ะ! เผื่อเอาไปโชว์ในปฏิทิน
                    'guest_name' => "Guest (Walk-in/Admin)", // เนื่องจาก user_id เป็น nullable เลยใส่เผื่อไว้ก่อนค่ะ
                    'check_in' => Carbon::parse($booking->check_in)->format('Y-m-d'), // เปลี่ยนชื่อฟิลด์ตรงนี้ด้วยค่ะ
                    'check_out' => Carbon::parse($booking->check_out)->format('Y-m-d'), // เปลี่ยนชื่อฟิลด์ตรงนี้ด้วยค่ะ
                    'status' => $booking->status,
                    
                    'booked_details' => $booking->bookingRooms->groupBy('room_type_id')->map(function ($group) {
                        $assignedRooms = $group->whereNotNull('room_id')->map(function($br) {
                            return $br->room->room_number ?? null;
                        })->filter()->values();

                        return [
                            'room_type' => $group->first()->roomType->name_en ?? 'Unknown',
                            'quantity' => $group->count(),
                            'assigned_rooms' => $assignedRooms->isEmpty() ? 'Pending check-in' : $assignedRooms
                        ];
                    })->values()
                ];
            });

        return response()->json([
            'status' => 'success',
            'message' => "Booking calendar for {$month}/{$year}",
            'total_bookings' => $bookings->count(),
            'data' => $bookings
        ]);
    }


    // 🧹 4. API อัปเดตสถานะงานทำความสะอาด (ค้นหาจาก Room ID)
    public function updateCleaningStatus(Request $request, $roomId)
    {
        // 1. ตรวจสอบข้อมูลที่ส่งมา
        $validated = $request->validate([
            'status' => 'required|in:in_progress,done', 
            'verified_by' => 'required|uuid|exists:users,id' 
        ]);

        try {
            DB::beginTransaction();

            // 2. 🔍 ค้นหางานทำความสะอาดของ "ห้องนี้" ที่ "ยังไม่เสร็จ"
            $task = HousekeepingTask::where('room_id', $roomId)
                ->whereIn('status', ['pending', 'in_progress'])
                ->first();

            // ถ้าไม่เจองานที่ค้างอยู่ ให้ฟ้อง Error กลับไปค่ะ
            if (!$task) {
                throw new \Exception('No active cleaning task found for this room. It might be already clean!');
            }

            // 3. อัปเดตสถานะงาน
            $task->status = $validated['status'];

            // ถ้างานเสร็จแล้ว ให้แสตมป์เวลาลงไปด้วยค่ะ
            if ($validated['status'] === 'done') {
                $task->completed_at = Carbon::now();
            }
            $task->save();

            $roomStatus = 'checkout_makeup';       

            // 4. ✨ เวทมนตร์ลูกโซ่: ถ้างานเสร็จ ต้องเปลี่ยนห้องให้พร้อมขาย!
            if ($validated['status'] === 'done') {
                $room = Room::findOrFail($roomId);
                $room->update([
                    'status' => 'available', // ห้องกลับมาว่างแล้วค่ะ!
                    'status_updated_at' => Carbon::now(),
                    'status_updated_by' => $validated['verified_by']
                ]);
                $roomStatus = $room->status;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Housekeeping status updated successfully!',
                'data' => [
                    'task_id' => $task->id,
                    'new_task_status' => $task->status,
                    'room_id' => $roomId,
                    'new_room_status' => $roomStatus 
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 400);
        }
    }
}