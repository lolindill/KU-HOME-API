<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomStatePeriodRequest;
use App\Http\Requests\UpdateRoomStatePeriodRequest;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Services\RoomStatePeriod\RoomStatePeriodService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * 🗓️ RoomStatePeriodController — contract จัดการ "ห้องสำรอง/ซ่อมแซม" แบบช่วงเวลา
 *
 *    (wayfinder/room-state-periods ticket 04, 2026-09-24 · route ซ้อนใต้ room · kind = body field)
 *
 *    สิทธิ์: route = role:admin,staff (OR) — ในนี้ guard แยก kind ตาม owner decision:
 *      - kind=maintenance → admin และ staff ทำได้ครบ POST/PATCH/DELETE/GET (หัวหน้าหน้างานจัดการเอง)
 *      - kind=reserved    → admin เท่านั้นทุก verb (staff → 403) — kind immutable จึงตรวจจาก row ครั้งเดียวพอ
 *    Response 201/200: period ผลลัพธ์หลัง merge (model ตรง ๆ ตาม convention) + merged + deleted_drafts
 *    + affected_bookings — รายละเอียดกฎ merge/effects อยู่ที่ RoomStatePeriodService
 */
class RoomStatePeriodController extends Controller
{
    // 📋 ลิสต์ period ของห้อง (ทุก kind · active/past/future อ่านจากวันที่)
    public function index(Request $request, $roomId)
    {
        try {
            $room = Room::findOrFail($roomId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room not found',
            ], 404);
        }

        $periods = $room->periods()->orderBy('start_date')->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Room state periods fetched successfully',
            'room_id' => $room->id,
            'room_number' => $room->room_number,
            'periods' => $periods,
        ]);
    }

    // ➕ สร้าง period — 201 (auto-merge same-kind ถ้าชน)
    public function store(StoreRoomStatePeriodRequest $request, $roomId)
    {
        try {
            $room = Room::findOrFail($roomId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room not found',
            ], 404);
        }

        $validated = $request->validated();

        if ($this->deniedForKind($request, $validated['kind'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'kind=reserved (ห้องสำรอง) เปลี่ยนได้โดย admin เท่านั้นค่ะนายท่าน',
            ], 403);
        }

        try {
            $result = app(RoomStatePeriodService::class)->create(
                $room,
                $validated['kind'],
                Carbon::parse($validated['start_date']),
                isset($validated['end_date']) ? Carbon::parse($validated['end_date']) : null,
                $request->user(),
            );
        } catch (\Exception $e) {
            $statusCode = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $statusCode);
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['merged']
                ? 'Room state period merged into an existing period of the same kind'
                : 'Room state period created successfully',
            'period' => $result['period'],
            'merged' => $result['merged'],
            'deleted_drafts' => $result['deleted_drafts'],
            'affected_bookings' => $result['affected_bookings'],
        ], 201);
    }

    // ✏️ แก้ช่วงวันที่ (kind immutable — ส่ง kind มา = 422 จาก FormRequest)
    public function update(UpdateRoomStatePeriodRequest $request, $roomId, $periodId)
    {
        try {
            $room = Room::findOrFail($roomId);
            $period = $room->periods()->findOrFail($periodId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room or period not found',
            ], 404);
        }

        if ($this->deniedForKind($request, $period->kind)) {
            return response()->json([
                'status' => 'error',
                'message' => 'kind=reserved (ห้องสำรอง) เปลี่ยนได้โดย admin เท่านั้นค่ะนายท่าน',
            ], 403);
        }

        try {
            $result = app(RoomStatePeriodService::class)->update($period, $request->validated(), $request->user());
        } catch (\Exception $e) {
            $statusCode = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $statusCode);
        }

        return response()->json([
            'status' => 'success',
            'message' => $result['merged']
                ? 'Room state period merged into an existing period of the same kind'
                : 'Room state period updated successfully',
            'period' => $result['period'],
            'merged' => $result['merged'],
            'deleted_drafts' => $result['deleted_drafts'],
            'affected_bookings' => $result['affected_bookings'],
        ]);
    }

    // 🗑️ ยกเลิก period — hard delete + audit log (ใช้ได้ทั้ง period ยังไม่ถึงและ active)
    public function destroy(Request $request, $roomId, $periodId)
    {
        try {
            $room = Room::findOrFail($roomId);
            $period = $room->periods()->findOrFail($periodId);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room or period not found',
            ], 404);
        }

        if ($this->deniedForKind($request, $period->kind)) {
            return response()->json([
                'status' => 'error',
                'message' => 'kind=reserved (ห้องสำรอง) เปลี่ยนได้โดย admin เท่านั้นค่ะนายท่าน',
            ], 403);
        }

        app(RoomStatePeriodService::class)->delete($period, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => 'Room state period deleted successfully (ห้องกลับมาขายตามปกติทันที — derived ตอน query)',
        ]);
    }

    /**
     * 🔐 guard แยก kind — reserved = admin เท่านั้น · maintenance = admin+staff (ผ่าน route มาแล้ว)
     */
    private function deniedForKind(Request $request, string $kind): bool
    {
        return $kind === RoomStatePeriod::KIND_RESERVED
            && $request->user('sanctum')?->role !== 'admin';
    }
}
