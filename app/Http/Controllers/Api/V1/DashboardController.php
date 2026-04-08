<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Booking;
use App\Models\HousekeepingTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    

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