<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
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
        // 🌟 Add (24/07/26): eager-load dailyRateRow กัน N+1 (rate มาจาก global_rates)
        $roomTypes = RoomType::with('dailyRateRow')->get();

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
        // 🌟 Add (24/07/26): eager-load dailyRateRow กัน N+1
        $roomType = RoomType::with('dailyRateRow')->find($id);

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
            'max_guests' => 'nullable|integer|min:1',
        ]);

        $checkIn = $request->check_in ? Carbon::parse($request->check_in) : Carbon::today();
        $checkOut = $request->check_out ? Carbon::parse($request->check_out) : Carbon::tomorrow();
        $maxGuests = $request->query('max_guests');

        $availableRoomTypes = RoomType::withCount(['rooms' => function ($query) {
                $query->where('status', 'available');
            }])
            ->withCount(['bookingRooms as booked_rooms_count' => function ($query) use ($checkIn, $checkOut) {
                // 🌟 Refactor (29/06/26): filter ที่ BR-level ตรงๆ ตาม state machine
                // BR states ที่นับลด availability: draft, confirmed, checked_in
                // (cancelled/no_show/checked_out ไม่นับลด)
                // ✅ Consistency: ตรงกับ createBooking() ที่กรองแบบเดียวกัน
                $query->whereIn('status', ['draft', 'confirmed', 'checked_in'])
                      ->where('check_in', '<', $checkOut)
                      ->where('check_out', '>', $checkIn);
            }])
            // 🌟 Add (24/07/26): eager-load dailyRateRow กัน N+1 (rate มาจาก global_rates)
            ->with('dailyRateRow')
            // 🌟 Filter room types ที่รองรับจำนวนแขกขั้นต่ำที่ต้องการ
            ->when($maxGuests, fn($q) => $q->where('max_guests', '>=', $maxGuests))
            ->get()
            ->map(function ($type) use ($checkIn, $checkOut, $maxGuests) {
                $availableRooms = max(0, $type->rooms_count - $type->booked_rooms_count);

                return [
                    'room_type_id' => $type->id,
                    'name_en' => $type->name_en,
                    'name_th' => $type->name_th,
                    'available_rooms' => $availableRooms,
                    // 🌟 Embed full room_type object so frontend has all fields
                    // (max_guests, extra_bed_*, etc.)
                    // 🌟 Add (24/07/26): daily_rate ดึงจาก global_rates มา embed ให้เลย
                    'room_type' => [
                        'id' => $type->id,
                        'name_en' => $type->name_en,
                        'name_th' => $type->name_th,
                        'max_guests' => $type->max_guests,
                        'extra_bed_enabled' => $type->extra_bed_enabled,
                        'max_extra_beds' => $type->max_extra_beds,
                        'extra_bed_price' => $type->extra_bed_price,
                        'daily_rate' => $type->daily_rate,
                    ],
                    'search_criteria' => [
                        'check_in' => $checkIn->toDateString(),
                        'check_out' => $checkOut->toDateString(),
                        'max_guests' => $maxGuests ? (int) $maxGuests : null,
                    ]
                ];
            });

        return response()->json([
            'status' => 'success',
            'message' => 'Room availability fetched successfully',
            'room_types' => $availableRoomTypes
        ]);
    }

    // 📅 ตรวจห้องว่างรายวัน (per-day calendar) — คืนทุก room type × ทุกคืนในช่วง
    public function availabilityPerDay(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end   = Carbon::parse($validated['end_date'])->startOfDay();

        // 🌟 DoS guard (public route): cap ที่ 366 คืน
        if ($start->diffInDays($end) > 365) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Date range cannot exceed 366 days',
            ], 422);
        }

        // 🌟 โหลดทุก room type พร้อมจำนวนห้อง "ขายได้จริง"
        // (status NOT IN maintenance, reserved_closed) → ตรงกับ createBooking
        $roomTypes = RoomType::withCount(['rooms as total_rooms_count' => function ($q) {
                $q->whereNotIn('status', ['maintenance', 'reserved_closed']);
            }])
            ->get();

        // 🌟 โหลด booking_rooms ที่ overlap [start, end+1] ครั้งเดียว (matrix approach)
        // BR states ที่นับลด availability: draft, confirmed, checked_in (ตรงกับ availability/createBooking)
        $endExclusive = $end->copy()->addDay();
        $overlaps = BookingRoom::select(['room_type_id', 'check_in', 'check_out'])
            ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
            ->where('check_in', '<', $endExclusive)
            ->where('check_out', '>', $start)
            ->get();

        // 🌟 Build occupied matrix [room_type_id => [date => count]]
        $occupied = [];
        foreach ($overlaps as $br) {
            $brCheckIn  = Carbon::parse($br->check_in)->startOfDay();
            $brCheckOut = Carbon::parse($br->check_out)->startOfDay();

            // วันที่ BR ครอบความค้างคืน ที่อยู่ภายใน [start, end] (half-open: check_in <= D < check_out)
            $from = $brCheckIn < $start ? $start : $brCheckIn;
            $to   = $brCheckOut > $endExclusive ? $endExclusive : $brCheckOut;

            for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
                $dateKey = $d->toDateString();
                $occupied[$br->room_type_id][$dateKey] = ($occupied[$br->room_type_id][$dateKey] ?? 0) + 1;
            }
        }

        // 🌟 Build per-day availability per room type
        $period = CarbonPeriod::create($start, $end);
        $dateKeys = [];
        foreach ($period as $date) {
            $dateKeys[] = $date->toDateString();
        }

        $result = $roomTypes->map(function ($type) use ($occupied, $dateKeys) {
            $row = [
                'room_type_id' => $type->id,
                'name_en'      => $type->name_en,
                'name_th'      => $type->name_th,
            ];
            foreach ($dateKeys as $dateKey) {
                $occupiedCount = $occupied[$type->id][$dateKey] ?? 0;
                $row[$dateKey] = max(0, $type->total_rooms_count - $occupiedCount);
            }
            return $row;
        });

        return response()->json([
            'status'     => 'success',
            'message'    => 'Per-day availability fetched successfully',
            'start_date' => $start->toDateString(),
            'end_date'   => $end->toDateString(),
            'room_types' => $result,
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