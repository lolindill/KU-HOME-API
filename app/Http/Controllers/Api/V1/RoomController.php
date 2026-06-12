<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RoomController extends Controller
{
    // 🛏️ ดึงข้อมูลห้องพักทั้งหมด
    public function allRooms()
    {
        $rooms = Room::with('roomType:id,name_en')
            ->orderBy('room_number', 'asc')
            ->get()
            ->map(function ($room) {
                return [
                    'id' => $room->id,
                    'room_number' => $room->room_number,
                    'room_type_id' => $room->room_type_id,
                    'room_type_name' => $room->roomType->name_en ?? 'Unknown',
                    'status' => $room->status,
                    'status_updated_at' => $room->status_updated_at,
                ];
            });

        return response()->json([
            'status' => 'success',
            'message' => 'All rooms fetched successfully',
            'total_rooms' => $rooms->count(),
            'rooms' => $rooms
        ]);
    }

    // 🔍 ค้นหาห้องพักด้วย ID
    public function getRoomById($id)
    {
        $room = Room::with('roomType:id,name_en')->find($id);

        if (!$room) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Room fetched successfully',
            'room' => [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'room_type_id' => $room->room_type_id,
                'room_type_name' => $room->roomType->name_en ?? 'Unknown',
                'status' => $room->status,
                'status_updated_at' => $room->status_updated_at,
            ]
        ]);
    }

    // 🛎️ ดูสถานะห้องพัก พร้อม Filter
    public function roomStatus(Request $request)
    {
        $statusFilter = $request->query('status');

        $rooms = Room::with('roomType')
            ->when($statusFilter, function ($query, $statusFilter) {
                return $query->where('status', strtolower($statusFilter));
            })  
            ->orderBy('room_number')
            ->get()
            ->map(function ($room) {
                return [
                    'room_number' => $room->room_number,
                    'room_type' => $room->roomType->name_en ?? 'Unknown',
                    'status' => $room->status,
                    'last_updated' => $room->status_updated_at ? Carbon::parse($room->status_updated_at)->diffForHumans() : '-'
                ];
            });
     
        return response()->json([
            'status' => 'success',
            'message' => 'Room status list fetched successfully',
            'total_rooms' => $rooms->count(),
            'rooms' => $rooms
        ]);
    }

    // 🏷️ ดึงข้อมูลประเภทห้องพักทั้งหมด
    public function allRoomTypes()
    {
        $roomTypes = RoomType::all();

        return response()->json([
            'status' => 'success',
            'message' => 'All room types fetched successfully',
            'total_types' => $roomTypes->count(),
            'room_types' => $roomTypes
        ]);
    }

    // 🏷️ ดึงข้อมูลประเภทห้องพักตาม ID
    public function getRoomTypeById($id)
    {
        $roomType = RoomType::find($id);

        if (!$roomType) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room type not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Room type fetched successfully',
            'room_type' => $roomType
        ]);
    }

    // 🔍 ตรวจสอบห้องพักว่าง
    public function availability(Request $request)
    {
        $request->validate([
            'check_in' => 'nullable|date|after_or_equal:today',
            'check_out' => 'nullable|date|after:check_in',
        ]);

        $checkIn = $request->check_in ? Carbon::parse($request->check_in) : Carbon::today();
        $checkOut = $request->check_out ? Carbon::parse($request->check_out) : Carbon::tomorrow();

        $availableRoomTypes = RoomType::withCount(['rooms' => function ($query) {
                $query->where('status', 'available');
            }])
            ->withCount(['bookingRooms as booked_rooms_count' => function ($query) use ($checkIn, $checkOut) {
                $query->whereHas('booking', function ($q) use ($checkIn, $checkOut) {
                    $q->where('check_in', '<', $checkOut)
                      ->where('check_out', '>', $checkIn)
                      ->whereIn('status', ['paid', 'confirmed', 'checked_in']);
                });
            }])
            ->get()
            ->map(function ($type) use ($checkIn, $checkOut) {
                $availableRooms = max(0, $type->rooms_count - $type->booked_rooms_count);

                return [
                    'room_type_id' => $type->id,
                    'name_en' => $type->name_en,
                    'name_th' => $type->name_th,
                    'available_rooms' => $availableRooms, 
                    'search_criteria' => [
                        'check_in' => $checkIn->toDateString(),
                        'check_out' => $checkOut->toDateString(),
                    ]
                ];
            });

        return response()->json([
            'status' => 'success',
            'message' => 'Room availability fetched successfully',
            'room_types' => $availableRoomTypes
        ]);
    }

    // 🧹 เปลี่ยนสถานะห้องพัก (ใช้ state machine)
    public function updateRoomStatus(UpdateRoomRequest $request, $id)
    {
        try {
            $validated = $request->validated();
            $room = Room::findOrFail($id);
            $currentStatus = $room->status;
            $newStatus = strtolower($validated['status']);

            $userId = $request->user() ? $request->user()->id : null;

            // 🌟 ใช้ state machine
            $isUpdated = $room->transitionStatusTo($newStatus, $userId);

            if (!$isUpdated) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Room status is already ' . $newStatus,
                    'room' => $room
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => "Room status updated to '{$newStatus}' successfully!",
                'room_id' => $room->id,
                'room_number' => $room->room_number,
                'old_status' => $currentStatus,
                'new_status' => $room->status,
                'status_updated_at' => $room->status_updated_at,
                'status_updated_by' => $room->status_updated_by, 
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room not found'
            ], 404);

        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            Log::error("Room status update failed: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], $statusCode);
        }
    }
}