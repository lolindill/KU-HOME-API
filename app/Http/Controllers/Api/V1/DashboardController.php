<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignTaskRequest;
use App\Http\Requests\StoreHousekeepingTaskRequest;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 🧹 Housekeeping Dashboard Controller (Phase A refactor — 15/07/26)
 *
 *    Endpoint map:
 *      GET    /dashboard/tasks               (admin)        — listTasks (+ filter ?status=&type=)
 *      POST   /dashboard/tasks               (admin)        — createTask (manual: daily/monthly/group/pre_checkin)
 *      PUT    /dashboard/tasks/{id}/assign   (admin)        — assignTask (skip accepted)
 *      GET    /dashboard/tasks/unassigned    (admin|hk)     — unassignedTasks
 *      POST   /dashboard/tasks/{id}/accept   (admin|hk)     — acceptTask
 *      PATCH  /dashboard/tasks/{id}/status   (admin|hk)     — updateStatus (by task_id)
 *
 *    Fix S-B2: updateStatus ใช้ task_id แทน room_id (กันสับสนเมื่อมีหลาย task/ห้อง)
 *    Fix S-B4: แยก routes admin vs housekeeper + defense-in-depth role check ในทุก method
 *    Fix L3 (เขียนใหม่): guard `done → *` ทำงานจริง อยู่ใน HousekeepingTask::transitionStatus()
 */
class DashboardController extends Controller
{
    // ============================================
    // 🔐 Admin endpoints
    // ============================================

    /**
     * List ทุก housekeeping task + filter ?status=&type=
     */
    public function listTasks(Request $request)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'admin') {
            return $this->forbidden('ต้องเป็นแอดมินเท่านั้นถึงจะดูรายการงานทั้งหมดได้ค่ะ');
        }

        $query = HousekeepingTask::with(['room.roomType', 'assignee']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->input('type')) {
            $query->where('task_type', $type);
        }

        $tasks = $query->orderBy('created_at', 'asc')->get()
            ->map(fn ($t) => $this->serializeTask($t));

        return response()->json([
            'status' => 'success',
            'message' => 'Housekeeping tasks fetched successfully',
            'total_tasks' => $tasks->count(),
            'tasks' => $tasks,
        ]);
    }

    /**
     * Manual create task — admin สร้าง daily/monthly/group/pre_checkin เอง
     */
    public function createTask(StoreHousekeepingTaskRequest $request)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'admin') {
            return $this->forbidden('ต้องเป็นแอดมินเท่านั้นถึงจะสร้างงานได้ค่ะ');
        }

        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // Fix S-B2: กัน duplicate active task ในห้องเดียวกัน
            $activeExists = HousekeepingTask::where('room_id', $validated['room_id'])
                ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])
                ->exists();
            if ($activeExists) {
                throw new \Exception('ห้องนี้มีงานที่กำลังดำเนินการอยู่แล้วค่ะ กรุณาปิดงานเดิมก่อนสร้างใหม่', 422);
            }

            $task = HousekeepingTask::create([
                'id' => Str::uuid(),
                'room_id' => $validated['room_id'],
                'task_type' => $validated['task_type'] ?? 'daily',
                'status' => 'unassigned',
                'notes' => $validated['notes'] ?? null,
                'scheduled_for' => $validated['scheduled_for'] ?? null,
            ]);

            DB::commit();

            $task->load(['room.roomType', 'assignee']);

            return response()->json([
                'status' => 'success',
                'message' => 'สร้างงาน housekeeping สำเร็จแล้วค่ะนายท่าน!',
                'task' => $this->serializeTask($task),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Create housekeeping task failed: '.$e->getMessage());
            $statusCode = $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 400;

            $message = $statusCode === 422
                ? $e->getMessage()
                : 'เกิดข้อผิดพลาดในการสร้างงาน กรุณาลองใหม่อีกครั้งค่ะ';

            return response()->json(['status' => 'error', 'message' => $message], $statusCode);
        }
    }

    /**
     * Admin assign housekeeper ให้ task — skip accepted (status=accepted ตรงๆ)
     */
    public function assignTask(AssignTaskRequest $request, $id)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'admin') {
            return $this->forbidden('ต้องเป็นแอดมินเท่านั้นถึงจะ assign งานได้ค่ะ');
        }

        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $task = HousekeepingTask::with(['room.roomType', 'assignee'])->findOrFail($id);
            $task->transitionStatus('accepted', $validated['assigned_to']);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Assign งานให้แม่บ้านเรียบร้อยค่ะ!',
                'task' => $this->serializeTask($task->fresh(['room.roomType', 'assignee'])),
            ]);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบงานที่ระบุในระบบค่ะนายท่าน',
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Assign task failed: '.$e->getMessage());
            $statusCode = $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 400;

            return response()->json([
                'status' => 'error',
                'message' => $statusCode === 422
                    ? $e->getMessage()
                    : 'เกิดข้อผิดพลาดในการ assign งาน กรุณาลองใหม่ค่ะ',
            ], $statusCode);
        }
    }

    // ============================================
    // 🧹 Shared endpoints — admin OR housekeeping
    // ============================================

    /**
     * List task ที่ยังไม่มีคนรับ (สำหรับ housekeeper dashboard)
     */
    public function unassignedTasks(Request $request)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'housekeeping'], true)) {
            return $this->forbidden('ต้องเป็นแอดมินหรือแม่บ้านถึงจะดูงานที่ยังไม่มีคนรับได้ค่ะ');
        }

        $tasks = HousekeepingTask::with(['room.roomType'])
            ->where('status', 'unassigned')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($t) => $this->serializeTask($t));

        return response()->json([
            'status' => 'success',
            'message' => 'Unassigned tasks fetched successfully',
            'total_unassigned' => $tasks->count(),
            'tasks' => $tasks,
        ]);
    }

    /**
     * Housekeeper accept งาน — เซ็ต assigned_to = auth user + transition → accepted
     */
    public function acceptTask(Request $request, $id)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'housekeeping'], true)) {
            return $this->forbidden('ต้องเป็นแอดมินหรือแม่บ้านถึงจะ accept งานได้ค่ะ');
        }

        try {
            DB::beginTransaction();

            $task = HousekeepingTask::with(['room.roomType', 'assignee'])->findOrFail($id);
            $task->transitionStatus('accepted', $user->id);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'รับงานเรียบร้อยแล้วค่ะ! เริ่มทำได้เลยนะคะ ✨',
                'task' => $this->serializeTask($task->fresh(['room.roomType', 'assignee'])),
            ]);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบงานที่ระบุในระบบค่ะนายท่าน',
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Accept task failed: '.$e->getMessage());
            $statusCode = $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 400;

            return response()->json([
                'status' => 'error',
                'message' => $statusCode === 422
                    ? $e->getMessage()
                    : 'เกิดข้อผิดพลาดในการรับงาน กรุณาลองใหม่ค่ะ',
            ], $statusCode);
        }
    }

    /**
     * อัปเดต status ของ task — ใช้ task_id (Fix S-B2) ไม่ใช่ room_id
     * ถ้า status=done → เปลี่ยนสถานะห้อง → available ผ่าน state machine
     */
    public function updateStatus(Request $request, $id)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'housekeeping'], true)) {
            return $this->forbidden('ต้องเป็นแอดมินหรือแม่บ้านถึงจะอัปเดตสถานะงานได้ค่ะ');
        }

        $validated = $request->validate([
            'status' => 'required|in:in_progress,done',
        ]);

        try {
            DB::beginTransaction();

            // Fix S-B2: ใช้ task_id แทน room_id — ไม่มี first() ที่จะเลือกผิด
            $task = HousekeepingTask::with(['room.roomType', 'assignee'])->findOrFail($id);

            // Fix L3 (เขียนใหม่): guard done→* อยู่ใน transitionStatus() แล้ว — ทำงานจริง
            $task->transitionStatus($validated['status']);

            $roomStatus = $task->room->status ?? 'unknown';

            // ถ้างาน done → เปลี่ยนสถานะห้อง → available ผ่าน state machine
            if ($validated['status'] === 'done' && $task->room_id) {
                $room = Room::findOrFail($task->room_id);
                $room->transitionStatusTo('available', $user->id);
                $roomStatus = $room->status;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $validated['status'] === 'done'
                    ? 'งานเสร็จเรียบร้อย! ห้องพร้อมให้บริการแล้วค่ะ 💖'
                    : 'เริ่มทำงานแล้วค่ะ!',
                'task' => $this->serializeTask($task->fresh(['room.roomType', 'assignee'])),
                'new_room_status' => $roomStatus,
            ]);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบงานที่ระบุในระบบค่ะนายท่าน',
            ], 404);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update task status failed: '.$e->getMessage());
            $statusCode = $e->getCode();
            $statusCode = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 400;

            return response()->json([
                'status' => 'error',
                'message' => $statusCode === 422
                    ? $e->getMessage()
                    : 'เกิดข้อผิดพลาดในการอัปเดตสถานะ กรุณาลองใหม่ค่ะ',
            ], $statusCode);
        }
    }

    // ============================================
    // 🔧 Helpers
    // ============================================

    /**
     * แปลง task เป็น array สำหรับ JSON response (consistent shape)
     */
    private function serializeTask(HousekeepingTask $task): array
    {
        return [
            'task_id' => $task->id,
            'room_id' => $task->room_id,
            'room_number' => $task->room?->room_number ?? 'N/A',
            'room_type' => $task->room?->roomType?->name_en ?? 'N/A',
            'task_type' => $task->task_type,
            'task_status' => $task->status,
            'assigned_to' => $task->assigned_to,
            'assignee_name' => $task->assignee?->name,
            'notes' => $task->notes,
            'scheduled_for' => $task->scheduled_for?->format('Y-m-d'),
            'requested_at' => Carbon::parse($task->created_at)->format('Y-m-d H:i'),
            'accepted_at' => $task->accepted_at?->format('Y-m-d H:i'),
            'completed_at' => $task->completed_at?->format('Y-m-d H:i'),
        ];
    }

    private function forbidden(string $message)
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }
}
