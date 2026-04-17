<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\BookingRoom;
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
    public function GetAllBookings(Request $request)
    {
        try {
           $bookings = Booking::leftJoin('users', 'bookings.user_id', '=', 'users.id')
            ->select('bookings.*', 'users.name as user_name')
            ->orderBy('users.name', 'asc')           // เรียงตามชื่อ (คนไม่มีชื่อจะไปรวมกันอยู่บนสุดหรือล่างสุดตาม DB)
            ->orderBy('bookings.created_at', 'desc') // เรียงตามวันที่สร้าง
            ->get();

            return response()->json([
                'bookings' => $bookings
            ], 200);

        } catch (\Exception $e) {
            Log::error("Server error getting bookings: " . $e->getMessage());
            
            return response()->json([
                'error' => 'Server error getting bookings'
            ], 500);
        }
    }
    public function userGetAllBookings(Request $request){
    try {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $bookings = Booking::where('user_id', $user->id)
            ->with(['room', 'status']) 
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'count' => $bookings->count(),
            'bookings' => $bookings
        ], 200);

    } catch (\Exception $e) {
        Log::error("Server error getting bookings: " . $e->getMessage(), [
            'user_id' => optional($request->user())->id,
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'error' => 'Server error getting bookings',
            'message' => $e->getMessage() 
        ], 500);
    }
}
    
    // 2️⃣ API ตรวจสอบรหัสส่วนลด (Process 2.2)
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
            // 1. ตรวจสอบข้อมูลที่ส่งมา (Validation)
            $validated = $request->validate([
                'source' => 'required|string',
                'check_in' => 'required|date|after_or_equal:today',
                'check_out' => 'required|date|after:check_in',

                // ข้อมูลห้องพักที่เลือก
                'booking_rooms' => 'required|array',
                'booking_rooms.*.room_type_id' => 'required|uuid|exists:room_types,id',
                'booking_rooms.*.quantity' => 'required|integer|min:1',
                'booking_rooms.*.extra_beds' => 'integer|min:0',

                // 🧍‍♂️ ข้อมูลผู้เข้าพัก
                'guest_title' => 'nullable|string',
                'guest_name' => 'required|string',
                'guest_email' => 'required|email',
                'guest_phone' => 'required|string',
                'guest_id_number' => 'nullable|string',
                'guest_nationality' => 'required|string',
                'is_ku_member' => 'required|in:true,false,1,0,True,False',

                // 🌟 เพิ่ม Validation สำหรับบริการเสริม (Add-ons)
                'addons' => 'nullable|array',
                'addons.breakfast' => 'nullable|integer|min:0',
                'addons.breakfast_price' => 'nullable|integer|min:0',
                'addons.early_checkIn_price' => 'nullable|integer|min:0',
                'addons.late_checkOut_price' => 'nullable|integer|min:0',
            ]);

            DB::beginTransaction();

            // 2. คำนวณจำนวนคืน
            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = Carbon::parse($validated['check_out']);
            $nights = $checkIn->diffInDays($checkOut);
            if ($nights === 0) $nights = 1;

            $confirmationNo = Carbon::now()->format('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT); 

            // 3. สร้างข้อมูลการจองหลัก (Booking)
            $booking = Booking::create([
                'confirmation' => $confirmationNo, // 🌟 หนูเปลี่ยนชื่อให้ตรงกับ Migration ล่าสุด (confirmation)
                'user_id' => $request->user()?->id,
                'source' => $validated['source'],
                'status' => 'draft', 
                'check_in' => $validated['check_in'], 
                'check_out' => $validated['check_out'], 
                
                'guest_title' => $validated['guest_title'],
                'guest_name' => $validated['guest_name'],
                'guest_email' => $validated['guest_email'],
                'guest_phone' => $validated['guest_phone'],
                
                'guest_nationality' => $validated['guest_nationality'],
                'is_ku_member' => filter_var($validated['is_ku_member'], FILTER_VALIDATE_BOOLEAN), // 🌟 แปลงเป็น Boolean ให้ชัวร์ค่ะ

                'total_amount' => 0, 
                'payment_deadline' => Carbon::now()->addHours(24)
            ]);
            
            $totalAmount = 0;
            
            // 🌟 ตัวแปรเก็บยอดรวมของ Add-on เตียงเสริม
            $totalExtraBedQty = 0;
            $totalExtraBedPrice = 0;

            // 4. บันทึกข้อมูลห้องที่จอง (Booking Rooms)
            foreach ($validated['booking_rooms'] as $roomRequest) {
                $roomType = RoomType::findOrFail($roomRequest['room_type_id']);
                
                for ($i = 0; $i < $roomRequest['quantity']; $i++) {
                    $roomPriceTotal = $roomType->rate_daily_general * $nights;
                    $extraBedTotal = ($roomRequest['extra_beds'] * $roomType->extra_bed_price) * $nights;
                    $subtotal = $roomPriceTotal + $extraBedTotal;
                    
                    $totalAmount += $subtotal;

                    // 🌟 เก็บยอดสะสมเตียงเสริมเพื่อเอาไปใส่ในตาราง Addons
                    $totalExtraBedQty += $roomRequest['extra_beds'];
                    $totalExtraBedPrice += $extraBedTotal;

                    BookingRoom::create([
                        'booking_id' => $booking->id, 
                        'room_type_id' => $roomType->id, 
                        'room_id' => null, 
                        'extra_beds' => $roomRequest['extra_beds'], 
                        'room_price' => $roomType->rate_daily_general, 
                        'extra_bed_price' => $roomType->extra_bed_price, 
                        'subtotal' => $subtotal 
                    ]);
                }
            }

            // 🌟 5. บันทึกข้อมูลบริการเสริม (Addons) แบบ 1-to-1
            $breakfastPrice = $request->input('addons.breakfast_price', 0);
            $earlyCheckInPrice = $request->input('addons.early_checkIn_price', 0);
            $lateCheckOutPrice = $request->input('addons.late_checkOut_price', 0);

            // บวกราคาบริการเสริมอื่นๆ เข้าไปในยอดรวมการจอง
            $totalAmount += ($breakfastPrice + $earlyCheckInPrice + $lateCheckOutPrice);

            Addon::create([
                'booking_id' => $booking->id,
                // ข้อมูลเตียงเสริมที่สรุปมาจากทุกห้องรวมกัน
                'extra_bed' => $totalExtraBedQty,
                'extra_bed_price' => $totalExtraBedPrice,
                // ข้อมูลบริการเสริมอื่นๆ ที่ส่งมาจาก Frontend
                'breakfast' => $request->input('addons.breakfast', 0),
                'breakfast_price' => $breakfastPrice,
                'early_checkIn_price' => $earlyCheckInPrice,
                'late_checkOut_price' => $lateCheckOutPrice,
            ]);

            // อัปเดตยอดรวมสุดท้ายกลับไปที่ Booking
            $booking->update(['total_amount' => $totalAmount]); 

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Booking and Add-ons created successfully',
                'data' => [
                    'total_amount' => $booking->total_amount,
                    'payment_deadline' => $booking->payment_deadline->toDateTimeString(),
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create booking: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create booking: ' . $e->getMessage()
            ], 500);
        }
    }


    private function applyUserFilter($query, $term)
{
    if (empty($term)) {
        return $query;
    }

    return $query->where(function ($q) use ($term) {
        
        // กรณีที่ 1: ถ้าคำค้นหาเป็น UUID (เช่น copy รหัส user_id มาค้นหาตรงๆ)
        if (Str::isUuid($term)) {
            $q->where('user_id', $term)
              // เสริม orWhere ให้ค้นหาแบบ Substring เผื่อเอาไว้ด้วยค่ะ
              ->orWhereHas('user', function ($userQuery) use ($term) {
                  $userQuery->where('name', 'LIKE', '%' . $term . '%');
              })
              ->orWhere('guest_name', 'LIKE', '%' . $term . '%');
        } 
        // กรณีที่ 2: ถ้าเป็นข้อความค้นหาปกติ
        else {
            // ค้นหาแบบ Substring จาก name ในตาราง users
            $q->whereHas('user', function ($userQuery) use ($term) {
                $userQuery->where('name', 'LIKE', '%' . $term . '%');
            })
            // หรือ ค้นหาแบบ Substring จาก guest_name ในตาราง bookings โดยตรงค่ะ
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
            // 1. รับค่าพารามิเตอร์ต่างๆ
            $term = $request->query('term');
            $roomTypeId = $request->query('room_type', 'all');
            $checkIn = $request->query('check_in', Carbon::today()->toDateString());
            $checkOut = $request->query('check_out', Carbon::today()->toDateString());

            // 🚀 2. เริ่มต้นสร้าง Query
            $query = Booking::with(['bookingRooms.roomType', 'bookingRooms.room', 'user']);

            // 🪄 3. ส่ง Query ไปให้ Sub-functions ต่างๆ จัดการ (Chain methods)
            $query = $this->applyUserFilter($query, $term);
            $query = $this->applyDateFilter($query, $checkIn, $checkOut);
            $query = $this->applyRoomTypeFilter($query, $roomTypeId);

            // 🎉 4. ประมวลผล Query สุทธิ
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

    // 🌟 API อัปเดตสถานะการจอง พร้อมตรวจสอบ Role และ State Rule
    public function updateStatus(Request $request)
    {
        // 1. ตรวจสอบข้อมูล JSON ที่ส่งมา
        $request->validate([
            'booking_id' => 'required|uuid|exists:bookings,id',
            'status'     => 'required|string|in:draft,confirmed,checked_in,checked_out,cancelled,no_show,deleted'
        ]);

        try {
            $booking = Booking::findOrFail($request->booking_id);
            $currentStatus = $booking->status;
            $newStatus = $request->status;
            
            $user = $request->user('sanctum'); // หรือใช้ auth('sanctum')->user() ก็ได้นะคะ
            $userRole = $user ? $user->role : 'guest';
            

            // 2. กฎการเปลี่ยนสถานะ (State Rules) ควบคู่กับสิทธิ์ (Roles) ที่อนุญาต
            $validTransitions = [
                'draft' => [
                    'paid' => ['user', 'guest'],
                    'deleted'   => ['user', 'guest'],
                ],
                'paid' => [
                    'comfirmed'  => 'admin',
                    'cancelled'  => 'admin',
                ],
                'confirmed' => [
                    'cancelled'  => 'admin',
                    'checked_in' => 'admin',
                    'no_show'    => 'admin',
                ],
                'checked_in' => [
                    'checked_out' => 'admin',
                ],
            ];

            // 3. ตรวจสอบว่าสถานะปัจจุบันสามารถเปลี่ยนไปเป็นสถานะใหม่ได้หรือไม่
            if (!array_key_exists($currentStatus, $validTransitions) || !array_key_exists($newStatus, $validTransitions[$currentStatus])) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "ไม่อนุญาตให้เปลี่ยนสถานะจาก '{$currentStatus}' ไปเป็น '{$newStatus}' ตาม Flow ระบบค่ะนายท่าน"
                ], 422);
            }

            // 4. ตรวจสอบ Role ว่าตรงกับที่ระบบอนุญาตหรือไม่
           $requiredRoles = $validTransitions[$currentStatus][$newStatus];
            if (!in_array($userRole, $requiredRoles)) {
                return response()->json(['message' => 'ไม่มีสิทธิ์ดำเนินการค่ะ!'], 403);
            }

            // 5. ถ้าผ่านด่านทั้งหมด ก็อัปเดตสถานะลง DB เลยค่ะ
            $booking->update(['status' => $newStatus]);

            return response()->json([
                'status'  => 'success',
                'message' => "อัปเดตสถานะเป็น {$newStatus} โดยคุณ {$userRole} เรียบร้อยแล้วค่ะ",
                'data'    => [
                    'booking_id' => $booking->id,
                    'status'     => $booking->status
                ]
            ], 200);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            
            // แอบดูว่าหา Model ไหนไม่เจอ จะได้แจ้ง Frontend ถูกค่ะ
            $modelName = class_basename($e->getModel()); 
            
            return response()->json([
                'status'  => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ"
            ], 404); // ปิ๊งป่อง! แจ้งเตือนแบบ 404 Not Found ถูกต้องตามหลักเป๊ะๆ ✨

        }catch (\Exception $e) {
            Log::error("Failed to update booking status: " . $e->getMessage());
            
            return response()->json([
                'status'  => 'error',
                'message' => 'ระบบขัดข้องระหว่างอัปเดตสถานะค่ะ'
            ], 500);
        }
    }
}