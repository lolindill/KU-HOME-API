<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Addon;         
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class BookingController extends Controller
{   
    public function getBookings(Request $request)
    {
        try {
            $user = $request->user('sanctum');
            
            if (!$user) {
                return response()->json([
                    'error' => 'Unauthorized', 
                    'message' => 'ต้องล็อกอินในระบบ หรือระบุเลข Guest ID ค่ะนายท่าน!'
                ], 401);
            }

            $term = $request->query('term');
            $roomTypeId = $request->query('room_type', 'all');
            $checkIn = $request->query('check_in'); 
            $checkOut = $request->query('check_out');

            // 🌟 เปลี่ยน Eager Loading ตรงนี้ค่ะนายท่าน! จาก addon เป็น bookingRooms.addon
            $query = Booking::with(['bookingRooms.roomType', 'bookingRooms.room', 'bookingRooms.addon'])
                ->when($user && $user->role === 'admin', function ($q) {
                $q->with('user');
            });

            if ($user && $user->role === 'admin') {
            } elseif ($user) {
                $query->where('user_id', $user->id);
            } 

            if ($term && method_exists($this, 'applyUserFilter')) {
                $query = $this->applyUserFilter($query, $term);
            }
            if ($checkIn && $checkOut && method_exists($this, 'applyDateFilter')) {
                $query = $this->applyDateFilter($query, $checkIn, $checkOut);
            }
            if ($roomTypeId !== 'all' && method_exists($this, 'applyRoomTypeFilter')) {
                $query = $this->applyRoomTypeFilter($query, $roomTypeId);
            }

            $bookings = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'ดึงข้อมูลสำเร็จแล้วค่ะนายท่าน! ✨',
                'count' => $bookings->count(),
                'role' => $user->id,
                'search_criteria' => [
                    'term' => $term,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'room_type' => $roomTypeId,
                ],
                'bookings' => $bookings
            ], 200);

        } catch (\Exception $e) {
            Log::error("Server error getting bookings: " . $e->getMessage(), [
                'user_id' => optional($request->user('sanctum'))->id, 
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Server error getting bookings',
                'message' => 'หนูขอโทษค่ะ เกิดข้อผิดพลาดในระบบ 😭: ' . $e->getMessage() 
            ], 500);
        }
    }

    public function validateDiscount(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'subtotal' => 'required|numeric'
        ]);

        $discountAmount = 0;
        if (strtoupper($request->code) === 'WELCOME10') {
            $discountAmount = $request->subtotal * 0.10; 
        }

        return response()->json([
            'status' => 'success',
            'discount_applied' => $discountAmount,
            'net_total' => $request->subtotal - $discountAmount
        ]);
    }
  
    public function createBooking(Request $request)
    {
        try {
            $validated = $request->validate([
                'source' => 'required|string',
                'check_in' => 'required|date|after_or_equal:today',
                'check_out' => 'required|date|after:check_in',

                'booking_rooms' => 'required|array',
                'booking_rooms.*.room_type_id' => 'required|uuid|exists:room_types,id',
                'booking_rooms.*.quantity' => 'required|integer|min:1', 
                'booking_rooms.*.extra_beds' => 'integer|min:0', 

                // 🌟 ย้าย Validation ของ Addon เข้ามาไว้ในระดับห้องพักแต่ละห้องแทนค่ะ
                'booking_rooms.*.addons' => 'nullable|array',
                'booking_rooms.*.addons.breakfast' => 'nullable|integer|min:0',
                'booking_rooms.*.addons.breakfast_price' => 'nullable|integer|min:0',
                'booking_rooms.*.addons.early_checkIn_price' => 'nullable|integer|min:0',
                'booking_rooms.*.addons.late_checkOut_price' => 'nullable|integer|min:0',

                'guest_title' => 'nullable|string',
                'guest_name' => 'required|string',
                'guest_email' => 'required|email',
                'guest_phone' => 'required|string',
                'guest_nationality' => 'required|string',
                'is_ku_member' => 'required|in:true,false,1,0,True,False',
            ]);

            DB::beginTransaction();

            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = Carbon::parse($validated['check_out']);
            $nights = $checkIn->diffInDays($checkOut) ?: 1;

            $requestedRoomTypes = [];
            $totalGuests = 0; 

            foreach ($validated['booking_rooms'] as $roomRequest) {
                $rtId = $roomRequest['room_type_id'];
                $requestedRoomTypes[$rtId] = ($requestedRoomTypes[$rtId] ?? 0) + 1; 
                $totalGuests += $roomRequest['quantity'];
            }

            foreach ($requestedRoomTypes as $rtId => $requestedRoomCount) {
                $totalRooms = Room::where('room_type_id', $rtId)->count();

                $bookedRooms = BookingRoom::where('room_type_id', $rtId)
                    ->whereHas('booking', function ($query) use ($checkIn, $checkOut) {
                        $query->whereIn('status', ['paid', 'confirmed', 'checked_in'])
                              ->where('check_in', '<', $checkOut)
                              ->where('check_out', '>', $checkIn);
                    })
                    ->count();

                $availableRooms = $totalRooms - $bookedRooms;

                if ($requestedRoomCount > $availableRooms) {
                    throw new \Exception("ขออภัยค่ะนายท่าน ห้องพักประเภทที่เลือกเต็มแล้วในช่วงเวลาดังกล่าวค่ะ", 422);
                }
            }

            $confirmationNo = Carbon::now()->format('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT); 

            $booking = Booking::create([
                'confirmation' => $confirmationNo, 
                'user_id' => $request->user('sanctum')?->id,
                'source' => $validated['source'],
                'status' => 'draft', 
                'check_in' => $validated['check_in'], 
                'check_out' => $validated['check_out'], 
                
                'guest_title' => $validated['guest_title']?? null,
                'guest_name' => $validated['guest_name'],
                'guest_email' => $validated['guest_email'],
                'guest_phone' => $validated['guest_phone'],
                'guest_nationality' => $validated['guest_nationality'],
                'is_ku_member' => $request->boolean('is_ku_member') ? 'true' : 'false', 

                'total_amount' => 0, 
                'payment_deadline' => Carbon::now()->addHours(24)
            ]);
            
            $totalAmount = 0;

            // 🌟 ปรับลูปให้สร้าง BookingRoom และ Addon ไปพร้อมๆ กันต่อห้องเลยค่ะ
            foreach ($validated['booking_rooms'] as $roomRequest) {
                $roomType = RoomType::findOrFail($roomRequest['room_type_id']);
                
                $roomPriceTotal = $roomType->rate_daily_general * $nights;
                $extraBedQty = $roomRequest['extra_beds'] ?? 0;
                $extraBedTotal = ($extraBedQty * $roomType->extra_bed_price) * $nights;
                
                // คำนวณราคา Addon ของห้องนี้
                $addons = $roomRequest['addons'] ?? [];
                $breakfastPrice = $addons['breakfast_price'] ?? 0;
                $earlyCheckInPrice = $addons['early_checkIn_price'] ?? 0;
                $lateCheckOutPrice = $addons['late_checkOut_price'] ?? 0;

                // รวมยอดของห้องนี้ทั้งหมด
                $subtotal = $roomPriceTotal + $extraBedTotal + $breakfastPrice + $earlyCheckInPrice + $lateCheckOutPrice;
                $totalAmount += $subtotal;

                $bookingRoom = BookingRoom::create([
                    'booking_id' => $booking->id, 
                    'room_type_id' => $roomType->id, 
                    'room_id' => null, // รอจ่ายห้องตอน Check-in
                ]);

                // 🌟 บันทึก Addon โดยผูกกับ booking_room_id แทนค่ะ
                Addon::create([
                    'booking_room_id' => $bookingRoom->id, // ใช้ ID ห้องนะคะ
                    'extra_bed' => $extraBedQty,
                    'extra_bed_price' => $extraBedTotal,
                    'breakfast' => $addons['breakfast'] ?? 0,
                    'breakfast_price' => $breakfastPrice,
                    'early_checkIn_price' => $earlyCheckInPrice,
                    'late_checkOut_price' => $lateCheckOutPrice,
                ]);
            }

            $booking->update(['total_amount' => $totalAmount]); 

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Booking and Add-ons created successfully',
                'data' => [
                    'id' => $booking->id,
                    'total_amount' => $booking->total_amount,
                    'payment_deadline' => $booking->payment_deadline->toDateTimeString(),
                    'user' => $request->user('sanctum')?->id,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create booking: " . $e->getMessage());
            
            $statusCode = $e->getCode() === 422 ? 422 : 500;
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create booking: ' . $e->getMessage()
            ], $statusCode);
        }
    }

    private function applyUserFilter($query, $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            if (Str::isUuid($term)) {
                $q->where('user_id', $term)
                  ->orWhereHas('user', function ($userQuery) use ($term) {
                      $userQuery->where('name', 'LIKE', '%' . $term . '%');
                  })
                  ->orWhere('guest_name', 'LIKE', '%' . $term . '%');
            } else {
                $q->whereHas('user', function ($userQuery) use ($term) {
                    $userQuery->where('name', 'LIKE', '%' . $term . '%');
                })
                ->orWhere('guest_name', 'LIKE', '%' . $term . '%');
            }
        });
    }

    private function applyDateFilter($query, $checkIn, $checkOut)
    {
        $startDate = Carbon::parse($checkIn)->startOfDay();
        $endDate = Carbon::parse($checkOut)->endOfDay();

        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('check_in', [$startDate, $endDate])
              ->orWhereBetween('check_out', [$startDate, $endDate])
              ->orWhere(function ($subQ) use ($startDate, $endDate) {
                  $subQ->where('check_in', '<=', $startDate)
                       ->where('check_out', '>=', $endDate);
              });
        });
    }

    private function applyRoomTypeFilter($query, $roomTypeId)
    {
        if (empty($roomTypeId) || $roomTypeId === 'all') {
            return $query; 
        }

        return $query->whereHas('bookingRooms', function ($q) use ($roomTypeId) {
            $q->where('room_type_id', $roomTypeId);
        });
    }

    public function bookingSearch(Request $request)
    {
        try {
            $term = $request->query('term');
            $roomTypeId = $request->query('room_type', 'all');
            $checkIn = $request->query('check_in', Carbon::today()->toDateString());
            $checkOut = $request->query('check_out', Carbon::today()->toDateString());

            $query = Booking::with(['bookingRooms.roomType', 'bookingRooms.room', 'user']);

            $query = $this->applyUserFilter($query, $term);
            $query = $this->applyDateFilter($query, $checkIn, $checkOut);
            $query = $this->applyRoomTypeFilter($query, $roomTypeId);

            $bookings = $query->orderBy('check_in', 'asc')->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Search completed successfully!',
                'total' => $bookings->count(),
                'search_criteria' => [
                    'term' => $term,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'room_type' => $roomTypeId
                ],
                'bookings' => $bookings
            ], 200);

        } catch (\Exception $e) {
            Log::error("Server error in booking search: " . $e->getMessage());
            return response()->json(['error' => 'Server error while searching bookings'], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string|in:draft,paid,confirmed,checked_in,checked_out,cancelled,no_show,deleted'
        ]);

        try {
            $booking = Booking::findOrFail($id);
            $newStatus = $request->status;
            
            $user = $request->user('sanctum');
            $userRole = $user ? $user->role : 'guest';
            
            $booking->transitionStatus($newStatus, $userRole);

            return response()->json([
                'status'  => 'success',
                'message' => "อัปเดตสถานะเป็น {$newStatus} โดยคุณ {$userRole} เรียบร้อยแล้วค่ะ",
                'data'    => [
                    'booking_id' => $booking->id,
                    'status'     => $booking->status
                ]
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $modelName = class_basename($e->getModel()); 
            return response()->json([
                'status'  => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ"
            ], 404);

        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            Log::error("Failed to update booking status: " . $e->getMessage());
            
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], $statusCode);
        }
    }

    public function showById(Request $request, string $id)
    {
        try {
            $user = $request->user('sanctum');

            if (!$user) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'กรุณาเข้าสู่ระบบก่อนนะคะนายท่าน!'
                ], 401);
            }

            // 🌟 แก้ไขตรงนี้ด้วยค่ะ เปลี่ยนจาก addon เฉยๆ เป็น bookingRooms.addon 
            $booking = Booking::with(['user', 'bookingRooms.addon', 'bookingRooms.roomType', 'bookingRooms.room'])
                ->where('id', $id)
                ->firstOrFail();

            if ($user->role !== 'admin' && $booking->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'นายท่านไม่มีสิทธิ์ดูข้อมูลการจองของผู้อื่นนะคะ! 🔒'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'ดึงข้อมูลการจองเรียบร้อยแล้วค่ะ! ✨',
                'data' => $booking
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'ไม่พบรหัสการจองนี้ในระบบค่ะนายท่าน 🔎',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage(),
            ], 500);
        }
    }
}