<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\RoomType;
use App\Models\Addon;         
use App\Models\BookingAddon;  
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;


class BookingController extends Controller
{   
    public function GetAllBookings(Request $request)
    {
        try {
             $user = $request->user();

            $bookings = Booking::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
            return response()->json([
                'bookings' => $bookings
            ], 200);

        } catch (\Exception $e) {
            // 🚨 4. ดักจับ Error ตามสไตล์โค้ดเดิมเป๊ะๆ เลยค่ะนายท่าน!
            Log::error("Server error getting bookings: " . $e->getMessage());
            
            return response()->json([
                'error' => 'Server error getting bookings'
            ], 500);
        }
    }
    // 1️⃣ API ค้นหาห้องว่างพร้อมราคา 
   public function availability(Request $request)
{
    // 🌟 เอา guests ออกจากการ validate ไปเลยค่ะ
    $request->validate([
        'check_in' => 'nullable|date|after_or_equal:today',
        'check_out' => 'nullable|date|after:check_in',
    ]);

    $checkIn = $request->check_in ? Carbon::parse($request->check_in) : Carbon::today();
    $checkOut = $request->check_out ? Carbon::parse($request->check_out) : Carbon::tomorrow();

    // 🎀 ดึง Room Types ทั้งหมดมาโชว์แบบไม่ต้องกรองจำนวนคนแล้วค่ะ!
    $availableRoomTypes = RoomType::withCount(['rooms' => function ($query) {
            $query->where('status', 'available');
        }])
        ->withCount(['bookingRooms as booked_rooms_count' => function ($query) use ($checkIn, $checkOut) {
            $query->whereHas('booking', function ($q) use ($checkIn, $checkOut) {
                // เช็ควันทับซ้อน (Overlap)
                $q->where('check_in', '<', $checkOut)
                  ->where('check_out', '>', $checkIn)
                  // 🛑 ตัดการจองที่ยังเป็น draft หรือ cancelled ทิ้งไป
                  ->whereNotIn('status', ['draft', 'cancelled']); 
            });
        }])
        ->get()
        ->map(function ($type) use ($checkIn, $checkOut) {
            // ✨ คณิตศาสตร์ของน้องเมด
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
        'data' => $availableRoomTypes
    ]);
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

    // 🌟 [เพิ่มใหม่] 3️⃣ API แสดงรายการบริการเสริม (Process 2.4 - Choose Add-ons)
    public function addons()
    {
        // ดึงบริการเสริมที่เปิดใช้งานอยู่
        $addons = Addon::where('is_active', true)->get();
        
        return response()->json([
            'status' => 'success',
            'data' => $addons
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

            // 🧍‍♂️ ข้อมูลผู้เข้าพัก (Guest Details Snapshot)
            'guest_title' => 'nullable|string',
            'guest_first_name' => 'required|string',
            'guest_last_name' => 'required|string',
            'guest_email' => 'required|email',
            'guest_phone' => 'required|string',
            'guest_id_number' => 'nullable|string',
            'guest_nationality' => 'required|string',
            'is_ku_member' => 'required|in:true,false,1,0,True,False',
        ]);
            DB::beginTransaction();

            // 2. คำนวณจำนวนคืน
            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = Carbon::parse($validated['check_out']);
            $nights = $checkIn->diffInDays($checkOut);
            if ($nights === 0) $nights = 1;

            // สุ่มเลข Confirmation No. สุดเท่ให้นายท่านค่ะ
            $confirmationNo = Carbon::now()->format('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT); 

            // 3. สร้างข้อมูลการจองหลัก (Booking)
            // หนูทำการแมปข้อมูล Guest เข้ากับคอลัมน์ใน DB ตาม Migration ใหม่ให้แล้วค่ะ!
            $booking = Booking::create([
                'confirmation_no' => $confirmationNo, 
                'user_id' => $request->user()?->id, // ดึง ID จากคนล็อกอิน (ถ้ามี)
                'booking_type' => 'individual', 
                'source' => $validated['source'],
                'status' => 'pending', 
                'check_in' => $validated['check_in'], 
                'check_out' => $validated['check_out'], 
                
                // ข้อมูลผู้เข้าพักแบบ Snapshot
                'guest_title' => $validated['guest_title'],
                'guest_first_name' => $validated['guest_first_name'],
                'guest_last_name' => $validated['guest_last_name'],
                'guest_email' => $validated['guest_email'],
                'guest_phone' => $validated['guest_phone'],
                'guest_id_number' => $validated['guest_id_number'],
                'guest_nationality' => $validated['guest_nationality'],
                'is_ku_member' => $validated['is_ku_member'],

                'total_amount' => 0, // รออัปเดตหลังจากรวมราคาห้องพักค่ะ
                'payment_deadline' => Carbon::now()->addHours(24)
            ]);
            
            $totalAmount = 0;

            // 4. บันทึกข้อมูลห้องที่จอง (Booking Rooms)
            foreach ($validated['booking_rooms'] as $roomRequest) {
                $roomType = RoomType::findOrFail($roomRequest['room_type_id']);
                
                for ($i = 0; $i < $roomRequest['quantity']; $i++) {
                    $roomPriceTotal = $roomType->rate_daily_general * $nights;
                    $extraBedTotal = ($roomRequest['extra_beds'] * $roomType->extra_bed_price) * $nights;
                    $subtotal = $roomPriceTotal + $extraBedTotal;
                    
                    $totalAmount += $subtotal;

                    BookingRoom::create([
                        'booking_id' => $booking->id, 
                        'room_type_id' => $roomType->id, 
                        'room_id' => null, // จะระบุตอน Check-in
                        'extra_beds' => $roomRequest['extra_beds'], 
                        'room_price' => $roomType->rate_daily_general, 
                        'extra_bed_price' => $roomType->extra_bed_price, 
                        'subtotal' => $subtotal 
                    ]);
                }
            }

            // 🌟 [TODO: Add-ons]
            // if (!empty($validated['addons'])) {
            //     foreach ($validated['addons'] as $addonRequest) {
            //         $addon = Addon::findOrFail($addonRequest['addon_id']);
                    
            //         // สมมติว่าคิดราคา Add-on เป็นต่อชิ้น/ต่อครั้งที่เลือก (ถ้าต้องคูณจำนวนคืนด้วยก็คูณ $nights ได้เลยค่ะ)
            //         $addonSubtotal = $addon->price * $addonRequest['quantity'];
                    
            //         $totalAmount += $addonSubtotal; // บวกยอด Add-on เข้าไปในราคาสุทธิ

            //         BookingAddon::create([
            //             'id' => Str::uuid(),
            //             'booking_id' => $booking->id,
            //             'addon_id' => $addon->id,
            //             'quantity' => $addonRequest['quantity'],
            //             'price' => $addon->price,
            //             'subtotal' => $addonSubtotal
            //         ]);
            //     }
            // }


            // 🌟 [TODO: Billing]
            // พักไว้ก่อนตามคำสั่งนายท่านค่ะ: บันทึกข้อมูล billing_entities ลงใน Booking

            // อัปเดตยอดรวมสุดท้ายกลับไปที่ Booking
            $booking->update(['total_amount' => $totalAmount]); 

            DB::commit();

            // 🌟 [TODO: Payment]
            // พักไว้ก่อนตามคำสั่งนายท่านค่ะ: เตรียมยิง API Payment ในลำดับถัดไป

            return response()->json([
                'status' => 'success',
                'message' => 'Booking created successfully',
                'data' => [
                    'booking_id' => $booking->id,
                    'confirmation_no' => $booking->confirmation_no,
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

    // 📅 3. API ปฏิทินการเข้าพัก (ดึงข้อมูลตามเดือน/ปี)
    public function bookingCalendar(Request $request)
    {
        $month = $request->query('month', Carbon::now()->month);
        $year = $request->query('year', Carbon::now()->year);

        $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $endOfMonth = Carbon::createFromDate($year, $month, 1)->endOfMonth()->endOfDay();

        // 🚨 แก้ไขชื่อฟิลด์เป็น check_in และ check_out ตาม Migration ของนายท่านแล้วค่ะ!
        $bookings = Booking::with(['bookingRooms.roomType', 'bookingRooms.room'])
            ->where(function ($query) use ($startOfMonth, $endOfMonth) {
                $query->whereBetween('check_in', [$startOfMonth, $endOfMonth])
                      ->orWhereBetween('check_out', [$startOfMonth, $endOfMonth])
                      ->orWhere(function ($q) use ($startOfMonth, $endOfMonth) {
                          $q->where('check_in', '<=', $startOfMonth)
                            ->where('check_out', '>=', $endOfMonth);
                      });
            })
            ->orderBy('check_in', 'asc')
            ->get()
            ->map(function ($booking) {
                return [
                    'booking_id' => $booking->id,
                    'confirmation_no' => $booking->confirmation_no, // เพิ่มเลข Confirm ให้ด้วยค่ะ! เผื่อเอาไปโชว์ในปฏิทิน
                    'guest_name' => "Guest (Walk-in/Admin)", // เนื่องจาก user_id เป็น nullable เลยใส่เผื่อไว้ก่อนค่ะ
                    'check_in' => Carbon::parse($booking->check_in)->format('Y-m-d'), // เปลี่ยนชื่อฟิลด์ตรงนี้ด้วยค่ะ
                    'check_out' => Carbon::parse($booking->check_out)->format('Y-m-d'), // เปลี่ยนชื่อฟิลด์ตรงนี้ด้วยค่ะ
                    'status' => $booking->status,
                    
                    'booked_details' => $booking->bookingRooms->groupBy('room_type_id')->map(function ($group) {
                        $assignedRooms = $group->whereNotNull('room_id')->map(function($br) {
                            return $br->room->room_number ?? null;
                        })->filter()->values();

                        return [
                            'room_type' => $group->first()->roomType->name_en ?? 'Unknown',
                            'quantity' => $group->count(),
                            'assigned_rooms' => $assignedRooms->isEmpty() ? 'Pending check-in' : $assignedRooms
                        ];
                    })->values()
                ];
            });

        return response()->json([
            'status' => 'success',
            'message' => "Booking calendar for {$month}/{$year}",
            'total_bookings' => $bookings->count(),
            'data' => $bookings
        ]);
    }
}