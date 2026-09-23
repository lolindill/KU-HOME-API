<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRoomRequest;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            'rooms' => $rooms,
        ]);
    }

    // 🔍 ค้นหาห้องพักด้วย ID
    public function getRoomById($id)
    {
        if (! Str::isUuid($id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room not found',
            ], 404);
        }

        $room = Room::with('roomType:id,name_en')->find($id);

        if (! $room) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room not found',
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
            ],
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
                    'last_updated' => $room->status_updated_at ? Carbon::parse($room->status_updated_at)->diffForHumans() : '-',
                ];
            });

        return response()->json([
            'status' => 'success',
            'message' => 'Room status list fetched successfully',
            'total_rooms' => $rooms->count(),
            'rooms' => $rooms,
        ]);
    }

    // 🏷️ ดึงข้อมูลประเภทห้องพักทั้งหมด
    public function allRoomTypes()
    {
        // 🌟 Update (03/09/26): eager-load rateRows กัน N+1 (rates object)
        $roomTypes = RoomType::with('rateRows')->get();

        return response()->json([
            'status' => 'success',
            'message' => 'All room types fetched successfully',
            'total_types' => $roomTypes->count(),
            'room_types' => $roomTypes,
        ]);
    }

    // 🏷️ ดึงข้อมูลประเภทห้องพักตาม ID
    public function getRoomTypeById($id)
    {
        if (! Str::isUuid($id)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room type not found',
            ], 404);
        }

        // 🌟 Update (03/09/26): eager-load rateRows กัน N+1 สำหรับ rates object
        $roomType = RoomType::with('rateRows')->find($id);

        if (! $roomType) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room type not found',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Room type fetched successfully',
            'room_type' => $roomType,
        ]);
    }

    // 🔍 ตรวจสอบห้องพักว่าง
    public function availability(Request $request)
    {
        $request->validate([
            'check_in' => 'nullable|date|after_or_equal:today',
            'check_out' => 'nullable|date|after:check_in',
            'max_guests' => 'nullable|integer|min:1',
            // 🌟 (09/09/26) bed_type=king_size → นับ availability เฉพาะห้อง king (ชั้น 8)
            'bed_type' => 'nullable|string|in:king_size',
        ]);

        $checkIn = $request->check_in ? Carbon::parse($request->check_in) : Carbon::today();
        $checkOut = $request->check_out ? Carbon::parse($request->check_out) : Carbon::tomorrow();
        $maxGuests = $request->query('max_guests');
        $bedType = $request->query('bed_type');

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
            // 🌟 (09/09/26) bed_type=king_size → เพิ่ม 2 counters สำหรับมิติห้อง king
            //    king pool = sellable (status NOT IN maintenance/reserved_closed) — ตรงกับ createBooking
            //    king occupied = hybrid ตาม lifecycle เพราะ room_id ถูก assign เฉพาะหลัง paid/confirmed:
            //      ก่อน assign: BR bed_preference=king_size ถูก allocator บังคับเข้าห้อง king แน่นอน (hard constraint)
            //      หลัง assign: นับตาม bed_type ของห้องจริงที่ถูก assign (BR no-preference อาจลง king ได้)
            //      เงื่อนไข 2 แขนกันหมด (room_id null ↔ not null) — ไม่นับซ้ำ
            ->when($bedType === 'king_size', function ($query) use ($checkIn, $checkOut) {
                $query->withCount(['rooms as king_total_rooms' => function ($q) {
                    $q->where('bed_type', 'king_size')
                        ->whereNotIn('status', ['maintenance', 'reserved_closed']);
                }])
                    ->withCount(['bookingRooms as king_occupied_count' => function ($q) use ($checkIn, $checkOut) {
                        $q->whereIn('status', ['draft', 'confirmed', 'checked_in'])
                            ->where('check_in', '<', $checkOut)
                            ->where('check_out', '>', $checkIn)
                            ->where(function ($inner) {
                                $inner->where(fn ($sub) => $sub
                                    ->where('bed_preference', 'king_size')
                                    ->whereNull('room_id'))
                                    ->orWhereHas('room', fn ($room) => $room->where('bed_type', 'king_size'));
                            });
                    }]);
            })
            // 🌟 Update (03/09/26): eager-load rateRows กัน N+1 (rates object)
            ->with('rateRows')
            // 🌟 Filter room types ที่รองรับจำนวนแขกขั้นต่ำที่ต้องการ
            ->when($maxGuests, fn ($q) => $q->where('max_guests', '>=', $maxGuests))
            ->get()
            ->map(function ($type) use ($checkIn, $checkOut, $maxGuests, $bedType) {
                $availableRooms = max(0, $type->rooms_count - $type->booked_rooms_count);

                $row = [
                    'room_type_id' => $type->id,
                    'name_en' => $type->name_en,
                    'name_th' => $type->name_th,
                    'available_rooms' => $availableRooms,
                    'rates' => $type->rates,
                    // 🌟 Embed full room_type object so frontend has all fields
                    // (max_guests, extra_bed_*, rates, etc.)
                    'room_type' => [
                        'id' => $type->id,
                        'name_en' => $type->name_en,
                        'name_th' => $type->name_th,
                        'max_guests' => $type->max_guests,
                        'extra_bed_enabled' => $type->extra_bed_enabled,
                        'max_extra_beds' => $type->max_extra_beds,
                        'extra_bed_price' => $type->extra_bed_price,
                        'rates' => $type->rates,
                    ],
                    'search_criteria' => [
                        'check_in' => $checkIn->toDateString(),
                        'check_out' => $checkOut->toDateString(),
                        'max_guests' => $maxGuests ? (int) $maxGuests : null,
                    ],
                ];

                // 🌟 (09/09/26) bed_type=king_size → available_rooms เปลี่ยนความหมายเป็น king-aware
                //    + ฟิลด์โปร่งใส king_total_rooms / king_occupied (frontend โชว์ "3/5 ห้อง king ว่าง" ได้)
                //    ไม่ส่ง bed_type → payload เหมือนเดิมทุกไบต์ (backward compatible)
                if ($bedType === 'king_size') {
                    $row['available_rooms'] = max(0, $type->king_total_rooms - $type->king_occupied_count);
                    $row['king_total_rooms'] = $type->king_total_rooms;
                    $row['king_occupied'] = $type->king_occupied_count;
                    $row['search_criteria']['bed_type'] = 'king_size';
                }

                return $row;
            });

        return response()->json([
            'status' => 'success',
            'message' => 'Room availability fetched successfully',
            'room_types' => $availableRoomTypes,
        ]);
    }

    // 📅 ตรวจห้องว่างรายวัน (per-day calendar) — คืนทุก room type × ทุกคืนในช่วง
    public function availabilityPerDay(Request $request)
    {
        // 🌟 (24/08/26) default window เมื่อไม่ส่ง params: start = today, end = start + 6 เดือน
        //    (merge ก่อน validate → rules/422 shape เดิมทั้งหมด — เทคนิคเดียวกันทั้ง 3 endpoints)
        $request->mergeIfMissing(['start_date' => Carbon::today()->toDateString()]);
        try {
            $defaultEndDate = Carbon::parse($request->input('start_date'))->addMonths(6)->toDateString();
        } catch (\Throwable) {
            $defaultEndDate = Carbon::today()->addMonths(6)->toDateString();
        }
        $request->mergeIfMissing(['end_date' => $defaultEndDate]);

        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();

        // 🌟 DoS guard (public route): cap ที่ 366 คืน
        if ($start->diffInDays($end) > 365) {
            return response()->json([
                'status' => 'error',
                'message' => 'Date range cannot exceed 366 days',
            ], 422);
        }

        // 🌟 โหลดทุก room type พร้อมจำนวนห้อง "ขายได้จริง" และ rateRows (กัน N+1)
        // (status NOT IN maintenance, reserved_closed) → ตรงกับ createBooking
        $roomTypes = RoomType::withSellableRoomsAndRates()->get();

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
            $brCheckIn = Carbon::parse($br->check_in)->startOfDay();
            $brCheckOut = Carbon::parse($br->check_out)->startOfDay();

            // วันที่ BR ครอบความค้างคืน ที่อยู่ภายใน [start, end] (half-open: check_in <= D < check_out)
            $from = $brCheckIn < $start ? $start : $brCheckIn;
            $to = $brCheckOut > $endExclusive ? $endExclusive : $brCheckOut;

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
                'name_en' => $type->name_en,
                'name_th' => $type->name_th,
                'rates' => $type->rates,
            ];
            foreach ($dateKeys as $dateKey) {
                $occupiedCount = $occupied[$type->id][$dateKey] ?? 0;
                $row[$dateKey] = max(0, $type->total_rooms_count - $occupiedCount);
            }

            return $row;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Per-day availability fetched successfully',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'room_types' => $result,
        ]);
    }

    // 📅 ตรวจห้องว่างเป็นช่วง (range) — คืน intervals ของวันที่ห้องเต็ม (available=0) ราย room_type
    //    ต่างจาก availabilityPerDay ตรงที่ไม่คืนจำนวนห้องว่างรายวัน แต่กลุ่มวัน sold-out ที่ติดกันเป็น {start_date,end_date}
    //    ใช้สำหรับปฏิทิน frontend disable วันที่จองไม่ได้ (โหลดน้อยกว่า per-day matrix)
    public function availabilityRanges(Request $request)
    {
        // 🌟 (24/08/26) default window เมื่อไม่ส่ง params: start = today, end = start + 6 เดือน
        //    (merge ก่อน validate → rules/422 shape เดิมทั้งหมด — เทคนิคเดียวกันทั้ง 3 endpoints)
        $request->mergeIfMissing(['start_date' => Carbon::today()->toDateString()]);
        try {
            $defaultEndDate = Carbon::parse($request->input('start_date'))->addMonths(6)->toDateString();
        } catch (\Throwable) {
            $defaultEndDate = Carbon::today()->addMonths(6)->toDateString();
        }
        $request->mergeIfMissing(['end_date' => $defaultEndDate]);

        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();

        // 🌟 DoS guard (public route): cap ที่ 366 คืน
        if ($start->diffInDays($end) > 365) {
            return response()->json([
                'status' => 'error',
                'message' => 'Date range cannot exceed 366 days',
            ], 422);
        }

        // 🌟 โหลดทุก room type พร้อมจำนวนห้อง "ขายได้จริง" และ rateRows (กัน N+1)
        // (status NOT IN maintenance, reserved_closed) → ตรงกับ availabilityPerDay/createBooking
        $roomTypes = RoomType::withSellableRoomsAndRates()->get();

        // 🌟 โหลด booking_rooms ที่ overlap [start, end+1] ครั้งเดียว (matrix approach)
        // BR states ที่นับลด availability: draft, confirmed, checked_in (ตรงกับ availabilityPerDay/createBooking)
        $endExclusive = $end->copy()->addDay();
        $overlaps = BookingRoom::select(['room_type_id', 'check_in', 'check_out'])
            ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
            ->where('check_in', '<', $endExclusive)
            ->where('check_out', '>', $start)
            ->get();

        // 🌟 Build occupied matrix [room_type_id => [date => count]]
        $occupied = [];
        foreach ($overlaps as $br) {
            $brCheckIn = Carbon::parse($br->check_in)->startOfDay();
            $brCheckOut = Carbon::parse($br->check_out)->startOfDay();

            // วันที่ BR ครอบความค้างคืน ที่อยู่ภายใน [start, end] (half-open: check_in <= D < check_out)
            $from = $brCheckIn < $start ? $start : $brCheckIn;
            $to = $brCheckOut > $endExclusive ? $endExclusive : $brCheckOut;

            for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
                $dateKey = $d->toDateString();
                $occupied[$br->room_type_id][$dateKey] = ($occupied[$br->room_type_id][$dateKey] ?? 0) + 1;
            }
        }

        // 🌟 List ของทุกคืนในช่วง [start, end] (รวม end — end_date คือคืนสุดท้ายที่ตรวจ)
        $dateKeys = [];
        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dateKeys[] = $date->toDateString();
        }

        // 🌟 กลุ่มวัน sold-out (available=0) ที่ติดกันเป็น interval ราย room_type
        // sold-out = occupied >= total_rooms_count (total=0 → ไม่มีห้องขาย ไม่ถือว่า sold-out ที่นี่
        //            เพราะ frontend มัก disable เฉพาะวันที่เคยมีห้องแต่หมดแล้ว)
        $result = $roomTypes->map(function ($type) use ($occupied, $dateKeys) {
            $intervals = [];

            // run-length grouping: จับวัน sold-out ที่ติดกันเป็นช่วง [start_date, end_date]
            foreach ($dateKeys as $dateKey) {
                $occupiedCount = $occupied[$type->id][$dateKey] ?? 0;
                $isSoldOut = $type->total_rooms_count > 0 && $occupiedCount >= $type->total_rooms_count;

                if ($isSoldOut) {
                    // ขยาย interval สุดท้าย (ถ้าติดกัน) หรือเปิด interval ใหม่
                    $lastIdx = count($intervals) - 1;
                    if ($lastIdx >= 0 && Carbon::parse($intervals[$lastIdx]['end_date'])->addDay()->toDateString() === $dateKey) {
                        $intervals[$lastIdx]['end_date'] = $dateKey;
                    } else {
                        $intervals[] = ['start_date' => $dateKey, 'end_date' => $dateKey];
                    }
                }
            }

            return [
                'room_type_id' => $type->id,
                'name_en' => $type->name_en,
                'name_th' => $type->name_th,
                'rates' => $type->rates,
                'intervals' => array_values($intervals),
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Availability ranges fetched successfully',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'room_types' => $result,
        ]);
    }

    // 📅 ดึงวันที่จองไม่ได้ราย room type (flat list ต่อ type) — คลอนจาก availabilityRanges
    //    🌟 Refactor (23/08/26): เดิมรวมทุก room type เป็น flat list เดียว (วันที่ทุก type เต็ม)
    //    ตอนนี้แยกราย room type เหมือน availabilityPerDay/Ranges — frontend เลือกประเภทเองได้
    //    ต่างจาก availabilityRanges ตรงที่คืนวันราบ ไม่กลุ่มติดกันเป็น interval
    public function unavailableDates(Request $request)
    {
        // 🌟 (24/08/26) default window เมื่อไม่ส่ง params: start = today, end = start + 6 เดือน
        //    (merge ก่อน validate → rules/422 shape เดิมทั้งหมด — เทคนิคเดียวกันทั้ง 3 endpoints)
        $request->mergeIfMissing(['start_date' => Carbon::today()->toDateString()]);
        try {
            $defaultEndDate = Carbon::parse($request->input('start_date'))->addMonths(6)->toDateString();
        } catch (\Throwable) {
            $defaultEndDate = Carbon::today()->addMonths(6)->toDateString();
        }
        $request->mergeIfMissing(['end_date' => $defaultEndDate]);

        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $start = Carbon::parse($validated['start_date'])->startOfDay();
        $end = Carbon::parse($validated['end_date'])->startOfDay();

        // 🌟 DoS guard (public route): cap ที่ 366 คืน
        if ($start->diffInDays($end) > 365) {
            return response()->json([
                'status' => 'error',
                'message' => 'Date range cannot exceed 366 days',
            ], 422);
        }

        // 🌟 โหลดทุก room type พร้อมจำนวนห้อง "ขายได้จริง" และ rateRows (กัน N+1)
        // (status NOT IN maintenance, reserved_closed) → ตรงกับ availabilityPerDay/createBooking
        $roomTypes = RoomType::withSellableRoomsAndRates()->get();

        // 🌟 โหลด booking_rooms ที่ overlap [start, end+1] ครั้งเดียว (matrix approach)
        // BR states ที่นับลด availability: draft, confirmed, checked_in (ตรงกับ availabilityPerDay/createBooking)
        $endExclusive = $end->copy()->addDay();
        $overlaps = BookingRoom::select(['room_type_id', 'check_in', 'check_out'])
            ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
            ->where('check_in', '<', $endExclusive)
            ->where('check_out', '>', $start)
            ->get();

        // 🌟 Build occupied matrix [room_type_id => [date => count]]
        $occupied = [];
        foreach ($overlaps as $br) {
            $brCheckIn = Carbon::parse($br->check_in)->startOfDay();
            $brCheckOut = Carbon::parse($br->check_out)->startOfDay();

            // วันที่ BR ครอบความค้างคืน ที่อยู่ภายใน [start, end] (half-open: check_in <= D < check_out)
            $from = $brCheckIn < $start ? $start : $brCheckIn;
            $to = $brCheckOut > $endExclusive ? $endExclusive : $brCheckOut;

            for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
                $dateKey = $d->toDateString();
                $occupied[$br->room_type_id][$dateKey] = ($occupied[$br->room_type_id][$dateKey] ?? 0) + 1;
            }
        }

        // 🌟 List ของทุกคืนในช่วง [start, end] (รวม end — end_date คือคืนสุดท้ายที่ตรวจ)
        $dateKeys = [];
        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dateKeys[] = $date->toDateString();
        }

        // 🌟 วัน "จองไม่ได้" ราย type = occupied >= total_rooms_count
        //    (total=0 → ไม่ถือว่า sold-out เพราะไม่มีห้องขายอยู่แล้ว — เหมือน availabilityRanges)
        $result = $roomTypes->map(function ($type) use ($occupied, $dateKeys) {
            $unavailableDates = [];
            foreach ($dateKeys as $dateKey) {
                $occupiedCount = $occupied[$type->id][$dateKey] ?? 0;
                if ($type->total_rooms_count > 0 && $occupiedCount >= $type->total_rooms_count) {
                    $unavailableDates[] = $dateKey;
                }
            }

            return [
                'room_type_id' => $type->id,
                'name_en' => $type->name_en,
                'name_th' => $type->name_th,
                'rates' => $type->rates,
                'unavailable_dates' => $unavailableDates,
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Unavailable dates fetched successfully',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'room_types' => $result,
        ]);
    }

    // 📅 ดึงช่วงวันที่จองไม่ได้ราย room type (sold-out intervals) — คลอนจาก availabilityRanges
    //    ต่างจาก availabilityRanges ตรงที่ "ไม่รับ input" และช่วงสแกนคำนวณอัตโนมัติ:
    //      start = today+3, end = max(check_out) ของ booking_rooms ที่ยัง active
    //    key ของ interval เป็น {start, end} ตามที่ frontend ขอ
    //    ใช้สำหรับ frontend แสดงวันที่จองไม่ได้เลยโดยไม่ต้องส่งช่วงวันมาเอง
    public function unavailableRanges(Request $request)
    {
        // 🌟 ไม่รับ input — ช่วงสแกนคำนวณอัตโนมัติ: [today+3, max checkout ของ booking ที่ยัง active]
        $start = Carbon::today()->addDays(3)->startOfDay();

        // 🌟 max checkout จาก BR ที่ status นับลด availability (ชุดเดียวกับ availabilityRanges/createBooking)
        $maxCheckout = BookingRoom::select('check_out')
            ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
            ->max('check_out');

        // 🌟 โหลด room types พร้อมจำนวนห้อง "ขายได้จริง" และ rateRows (กัน N+1)
        // (status NOT IN maintenance, reserved_closed) → ตรงกับ availabilityRanges/createBooking
        $roomTypes = RoomType::withSellableRoomsAndRates()->get();

        // 🌟 กรณีไม่มี booking เลย → ไม่สามารถ lock end ของช่วงได้ → คืน list ว่าง
        if ($maxCheckout === null) {
            return response()->json([
                'status' => 'success',
                'message' => 'Unavailable ranges fetched successfully',
                'start' => null,
                'end' => null,
                'room_types' => $roomTypes->map(fn ($t) => [
                    'room_type_id' => $t->id,
                    'name_en' => $t->name_en,
                    'name_th' => $t->name_th,
                    'rates' => $t->rates,
                    'intervals' => [],
                ])->values(),
            ]);
        }

        $end = Carbon::parse($maxCheckout)->startOfDay();

        // 🌟 DoS guard (public route): ป้องกัน booking ไกลๆ ทำให้ลูปสแกนยาวเกินไป (cap 365 คืน เทียบเท่า sibling)
        if ($end->gt($start->copy()->addDays(365))) {
            $end = $start->copy()->addDays(365);
        }

        // 🌟 โหลด booking_rooms overlap [start, end+1] ครั้งเดียว (matrix approach)
        // BR states ที่นับลด availability: draft, confirmed, checked_in (ตรงกับ availabilityRanges/createBooking)
        $endExclusive = $end->copy()->addDay();
        $overlaps = BookingRoom::select(['room_type_id', 'check_in', 'check_out'])
            ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
            ->where('check_in', '<', $endExclusive)
            ->where('check_out', '>', $start)
            ->get();

        // 🌟 Build occupied matrix [room_type_id => [date => count]]
        $occupied = [];
        foreach ($overlaps as $br) {
            $brCheckIn = Carbon::parse($br->check_in)->startOfDay();
            $brCheckOut = Carbon::parse($br->check_out)->startOfDay();

            // วันที่ BR ครอบความค้างคืน ที่อยู่ภายใน [start, end] (half-open: check_in <= D < check_out)
            $from = $brCheckIn < $start ? $start : $brCheckIn;
            $to = $brCheckOut > $endExclusive ? $endExclusive : $brCheckOut;

            for ($d = $from->copy(); $d->lt($to); $d->addDay()) {
                $dateKey = $d->toDateString();
                $occupied[$br->room_type_id][$dateKey] = ($occupied[$br->room_type_id][$dateKey] ?? 0) + 1;
            }
        }

        // 🌟 list ของทุกคืนในช่วง [start, end] (ว่างอัตโนมัติถ้า end < start — ทุก booking checkout ก่อน today+3)
        $dateKeys = [];
        foreach (CarbonPeriod::create($start, $end) as $date) {
            $dateKeys[] = $date->toDateString();
        }

        // 🌟 กลุ่มวัน sold-out (available=0) ที่ติดกันเป็น interval ราย room_type → {start, end}
        // sold-out = occupied >= total_rooms_count (total=0 → ไม่ถือว่า sold-out เพราะไม่มีห้องขายอยู่แล้ว)
        $result = $roomTypes->map(function ($type) use ($occupied, $dateKeys) {
            $intervals = [];

            // run-length grouping: จับวัน sold-out ที่ติดกันเป็นช่วง [start, end]
            foreach ($dateKeys as $dateKey) {
                $occupiedCount = $occupied[$type->id][$dateKey] ?? 0;
                $isSoldOut = $type->total_rooms_count > 0 && $occupiedCount >= $type->total_rooms_count;

                if ($isSoldOut) {
                    // ขยาย interval สุดท้าย (ถ้าติดกัน) หรือเปิด interval ใหม่
                    $lastIdx = count($intervals) - 1;
                    if ($lastIdx >= 0 && Carbon::parse($intervals[$lastIdx]['end'])->addDay()->toDateString() === $dateKey) {
                        $intervals[$lastIdx]['end'] = $dateKey;
                    } else {
                        $intervals[] = ['start' => $dateKey, 'end' => $dateKey];
                    }
                }
            }

            return [
                'room_type_id' => $type->id,
                'name_en' => $type->name_en,
                'name_th' => $type->name_th,
                'rates' => $type->rates,
                'intervals' => array_values($intervals),
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Unavailable ranges fetched successfully',
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
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

            if (! $isUpdated) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Room status is already '.$newStatus,
                    'room' => $room,
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

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Room not found',
            ], 404);

        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            Log::error('Room status update failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }
}
