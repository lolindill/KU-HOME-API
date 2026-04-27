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
            // 🛡️ เช็ค User จาก Sanctum ค่ะ
            $user = $request->user('sanctum');
            
            

            // 1. ตรวจสอบสิทธิ์ ถ้าไม่มีทั้ง User (ไม่ได้ล็อกอิน) และไม่ส่ง Guest ID มา หนูจะไม่ให้ผ่านนะคะ! 🛑
            if (!$user) {
                return response()->json([
                    'error' => 'Unauthorized', 
                    'message' => 'ต้องล็อกอินในระบบ หรือระบุเลข Guest ID ค่ะนายท่าน!'
                ], 401);
            }

            // 2. รับค่าพารามิเตอร์สำหรับการค้นหาและฟิลเตอร์
            $term = $request->query('term');
            $roomTypeId = $request->query('room_type', 'all');
            $checkIn = $request->query('check_in'); 
            $checkOut = $request->query('check_out');

            // 3. เริ่มต้นสร้าง Query พร้อม Eager Loading (หนูแอบเพิ่ม addon เข้าไปให้ด้วยเผื่อเรียกใช้นะคะ)
            $query = Booking::with(['bookingRooms.roomType', 'bookingRooms.room', 'addon'])
                ->when($user && $user->role === 'admin', function ($q) {
                // โหลดข้อมูล user เพิ่มเข้าไปเฉพาะเมื่อผู้ใช้ล็อกอินและเป็น admin ค่ะ
                $q->with('user');
            });

            // 4. 🌟 แยก Logic สิทธิ์การเข้าถึง (Authorization) ตาม Role
            if ($user && $user->role === 'admin') {
                // 👑 แอดมิน: ดูได้ทุกอย่าง ทั้งของ User และ Guest (ดึงผ่านหมด ไม่ต้องใส่ where ดัก)
                // Filter ต่างๆ ของแอดมินจะไปทำงานในขั้นตอนที่ 5 ค่ะ
            } elseif ($user) {
                // 👤 ยูสเซอร์: ดูได้แค่ของตัวเอง หรือถ้ามีเลข Guest ID ที่ตัวเองเคยจองไว้
                $query->where('user_id', $user->id);
                
            } 

            // 5. 🔍 การทำงานของระบบฟิลเตอร์
            // ถ้าแอดมินสั่ง ฟิลเตอร์จะค้นหาจากทั้งหมด แต่ถ้ายูสเซอร์หรือเกสต์สั่ง ฟิลเตอร์จะทำงานภายใต้ scope ข้อมูลของตัวเองเท่านั้นค่ะ
            if ($term && method_exists($this, 'applyUserFilter')) {
                $query = $this->applyUserFilter($query, $term);
            }
            
            if ($checkIn && $checkOut && method_exists($this, 'applyDateFilter')) {
                $query = $this->applyDateFilter($query, $checkIn, $checkOut);
            }
            
            if ($roomTypeId !== 'all' && method_exists($this, 'applyRoomTypeFilter')) {
                $query = $this->applyRoomTypeFilter($query, $roomTypeId);
            }

            // 6. 🎉 ประมวลผลดึงข้อมูลออกมา
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
            \Illuminate\Support\Facades\Log::error("Server error getting bookings: " . $e->getMessage(), [
                'user_id' => optional($request->user('sanctum'))->id, 
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Server error getting bookings',
                'message' => 'หนูขอโทษค่ะ เกิดข้อผิดพลาดในระบบ 😭: ' . $e->getMessage() 
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

            // ข้อมูลห้องพักที่เลือก (1 Object = 1 ห้อง)
            'booking_rooms' => 'required|array',
            'booking_rooms.*.room_type_id' => 'required|uuid|exists:room_types,id',
            'booking_rooms.*.quantity' => 'required|integer|min:1', // 🌟 จำนวนลูกค้าในห้องนี้
            'booking_rooms.*.extra_beds' => 'integer|min:0', 

            // ข้อมูลผู้เข้าพัก
            'guest_title' => 'nullable|string',
            'guest_name' => 'required|string',
            'guest_email' => 'required|email',
            'guest_phone' => 'required|string',
            'guest_nationality' => 'required|string',
            'is_ku_member' => 'required|in:true,false,1,0,True,False',

            // บริการเสริม (Add-ons)
            'addons' => 'nullable|array',
            'addons.breakfast' => 'nullable|integer|min:0',
            'addons.breakfast_price' => 'nullable|integer|min:0',
            'addons.early_checkIn_price' => 'nullable|integer|min:0',
            'addons.late_checkOut_price' => 'nullable|integer|min:0',
        ]);

        DB::beginTransaction();

        $checkIn = Carbon::parse($validated['check_in']);
        $checkOut = Carbon::parse($validated['check_out']);
        $nights = $checkIn->diffInDays($checkOut) ?: 1;

        // 🛡️ 1.5 เช็คห้องว่าง (Availability Check) และรวมจำนวนลูกค้า
        $requestedRoomTypes = [];
        $totalGuests = 0; // 🌟 ตัวแปรเก็บจำนวนลูกค้ารวมทั้งหมด

        foreach ($validated['booking_rooms'] as $roomRequest) {
            $rtId = $roomRequest['room_type_id'];
            // นับจำนวนห้องที่ต้องการจอง (1 Object = 1 ห้อง)
            $requestedRoomTypes[$rtId] = ($requestedRoomTypes[$rtId] ?? 0) + 1; 
            // สะสมจำนวนลูกค้ารวม
            $totalGuests += $roomRequest['quantity'];
        }

        // ตรวจสอบโควต้าทีละประเภทห้อง
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

            // ถ้าห้องว่างน้อยกว่าจำนวนที่ขอจอง ให้ Error
            if ($requestedRoomCount > $availableRooms) {
                throw new \Exception("ขออภัยค่ะนายท่าน ห้องพักประเภทที่เลือกเต็มแล้วในช่วงเวลาดังกล่าวค่ะ", 422);
            }
        }

        // 2. สร้างข้อมูลการจองหลัก (Booking)
        $confirmationNo = Carbon::now()->format('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT); 

        $booking = Booking::create([
            'confirmation' => $confirmationNo, 
            'user_id' => $request->user('sanctum')?->id,
            'source' => $validated['source'],
            'status' => 'draft', 
            'check_in' => $validated['check_in'], 
            'check_out' => $validated['check_out'], 
            
            'guest_title' => $validated['guest_title'],
            'guest_name' => $validated['guest_name'],
            'guest_email' => $validated['guest_email'],
            'guest_phone' => $validated['guest_phone'],
            'guest_nationality' => $validated['guest_nationality'],
            'is_ku_member' => $request->boolean('is_ku_member') ? 'true' : 'false', 

            'total_amount' => 0, 
            'payment_deadline' => Carbon::now()->addHours(24)
        ]);
        
        $totalAmount = 0;
        $totalExtraBedQty = 0;
        $totalExtraBedPrice = 0;

        // 3. บันทึกข้อมูลห้องที่จอง (Booking Rooms) 
        foreach ($validated['booking_rooms'] as $roomRequest) {
            $roomType = RoomType::findOrFail($roomRequest['room_type_id']);
            
            // ราคาห้องพักต่อ 1 ห้อง
            $roomPriceTotal = $roomType->rate_daily_general * $nights;
            
            $extraBedQty = $roomRequest['extra_beds'] ?? 0;
            $extraBedTotal = ($extraBedQty * $roomType->extra_bed_price) * $nights;
            
            $subtotal = $roomPriceTotal + $extraBedTotal;
            $totalAmount += $subtotal;

            $totalExtraBedQty += $extraBedQty;
            $totalExtraBedPrice += $extraBedTotal;

            // สร้างแค่ 1 แถวต่อ 1 Object ใน Array ค่ะ
            BookingRoom::create([
                'booking_id' => $booking->id, 
                'room_type_id' => $roomType->id, 
                'room_id' => null, 
                // 💡 Note จากน้องเมด: ถ้านายท่านต้องการเก็บจำนวนลูกค้าในตารางนี้ 
                // อย่าลืมไปเพิ่ม Column 'guest_count' หรือ 'quantity' ใน Migration ของ BookingRoom ด้วยนะคะ!
                // 'guest_count' => $roomRequest['quantity'], 
            ]);
        }

        // 4. บันทึกข้อมูลบริการเสริม (Addons)
        $breakfastPrice = $request->input('addons.breakfast_price', 0);
        
        // 💡 ถ้านายท่านอยากคำนวณราคาอาหารเช้าจากจำนวนคนบนเซิร์ฟเวอร์เลย สามารถใช้ตัวแปร $totalGuests ได้เลยค่ะ
        // เช่น: $breakfastPrice = $totalGuests * ราคาอาหารเช้าต่อหัว * $nights;

        $earlyCheckInPrice = $request->input('addons.early_checkIn_price', 0);
        $lateCheckOutPrice = $request->input('addons.late_checkOut_price', 0);

        $totalAmount += ($breakfastPrice + $earlyCheckInPrice + $lateCheckOutPrice);

        Addon::create([
            'booking_id' => $booking->id,
            'extra_bed' => $totalExtraBedQty,
            'extra_bed_price' => $totalExtraBedPrice,
            'breakfast' => $request->input('addons.breakfast', 0),
            'breakfast_price' => $breakfastPrice,
            'early_checkIn_price' => $earlyCheckInPrice,
            'late_checkOut_price' => $lateCheckOutPrice,
        ]);

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
            'status'     => 'required|string|in:draft,paid,confirmed,checked_in,checked_out,cancelled,no_show,deleted'
        ]);

        try {
            $booking = Booking::findOrFail($request->booking_id);
            $newStatus = $request->status;
            
            $user = $request->user('sanctum');
            $userRole = $user ? $user->role : 'guest';
            
            // 🌟 2. สั่งให้ Model ทำงานแทน (โยน Role ไปให้ Model เช็คด้วย)
            $booking->transitionStatus($newStatus, $userRole);

            // 3. ส่ง Response กลับแบบสวยๆ
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
            // ดึง HTTP Status Code ที่โยนมาจาก Model (422 หรือ 403) ถ้าไม่มีให้ใช้ 500 ค่ะ
            $statusCode = $e->getCode() ?: 500;
            
            \Illuminate\Support\Facades\Log::error("Failed to update booking status: " . $e->getMessage());
            
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], $statusCode);
        }
    }

    
    public function showById(Request $request, string $id)
    {
        try {
            // 🛡️ ตรวจสอบการ Login
            $user = $request->user('sanctum');

            if (!$user) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'กรุณาเข้าสู่ระบบก่อนนะคะนายท่าน!'
                ], 401);
            }

            // 1. ดึงข้อมูล Booking พร้อม Relation ที่เกี่ยวข้อง
            $booking = Booking::with(['user', 'addon', 'bookingRooms.roomType', 'bookingRooms.room'])
                ->where('id', $id)
                ->firstOrFail();

            // 2. 🔐 ตรวจสอบสิทธิ์ (Authorization)
            // ถ้าไม่ใช่ Admin และ ID ผู้ใช้ไม่ตรงกับเจ้าของรายการจอง หนูไม่ให้ดูนะคะ! 🙅‍♀️
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