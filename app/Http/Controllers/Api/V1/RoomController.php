<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;

class RoomController extends Controller
{
    // 🛏️ 1. API ดึงข้อมูลห้องพักทั้งหมด
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
            'data' => $rooms
        ]);
    }

    // 🔍 2. API ค้นหาห้องพักด้วย ID
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
            'data' => [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'room_type_id' => $room->room_type_id,
                'room_type_name' => $room->roomType->name_en ?? 'Unknown',
                'status' => $room->status,
                'status_updated_at' => $room->status_updated_at,
            ]
        ]);
    }

    // 🛎️ 3. API ดูสถานะห้องพัก (ว่าง / มีแขกเข้าพัก) พร้อม Filter
    public function roomStatus(Request $request)
    {
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
                    'status' => $room->status,
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

    // 🏷️ 4. API ดึงข้อมูลประเภทห้องพักทั้งหมด
    public function allRoomTypes()
    {
        $roomTypes = RoomType::all();

        return response()->json([
            'status' => 'success',
            'message' => 'All room types fetched successfully',
            'total_types' => $roomTypes->count(),
            'data' => $roomTypes
        ]);
    }

    // 🏷️ 5. API ดึงข้อมูลประเภทห้องพักตาม ID
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
            'data' => $roomType
        ]);
    }
}