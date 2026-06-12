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
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    // 🧹 ดูสถานะงานทำความสะอาดของแม่บ้าน
    public function cleaningTasks(Request $request)
    {
        $tasks = HousekeepingTask::with('room.roomType')
            ->whereIn('status', ['pending', 'in_progress'])
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
            'tasks' => $tasks
        ]);
    }

    // 🧹 อัปเดตสถานะงานทำความสะอาด (ค้นหาจาก Room ID)
    public function updateCleaningStatus(Request $request, $roomId)
    {
        $validated = $request->validate([
            'status' => 'required|in:in_progress,done', 
            'verified_by' => 'required|uuid|exists:users,id' 
        ]);

        try {
            DB::beginTransaction();

            $task = HousekeepingTask::where('room_id', $roomId)
                ->whereIn('status', ['pending', 'in_progress'])
                ->first();

            if (!$task) {
                throw new \Exception('No active cleaning task found for this room. It might be already clean!');
            }

            // อัปเดตสถานะงาน
            $task->status = $validated['status'];

            if ($validated['status'] === 'done') {
                $task->completed_at = Carbon::now();
            }
            $task->save();

            $roomStatus = $task->room->status ?? 'unknown';

            // 🌟 ถ้างานเสร็จ ใช้ state machine เปลี่ยนสถานะห้อง → available
            if ($validated['status'] === 'done') {
                $room = Room::findOrFail($roomId);
                $room->transitionStatusTo('available', $validated['verified_by']);
                $roomStatus = $room->status;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Housekeeping status updated successfully!',
                'task_id' => $task->id,
                'new_task_status' => $task->status,
                'room_id' => $roomId,
                'new_room_status' => $roomStatus
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Dashboard cleaning status update failed: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการอัปเดตสถานะ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭'
            ], 400);
        }
    }
}