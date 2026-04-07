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
        // 🌟 เปลี่ยน required เป็น nullable เพื่อให้เป็น Optional ค่ะ
        $request->validate([
            'check_in' => 'nullable|date|after_or_equal:today',
            'check_out' => 'nullable|date|after:check_in',
            'guests' => 'nullable|integer|min:1'
        ]);

        // 🌟 กำหนดค่า Default ถ้านายท่านไม่ได้ส่งพารามิเตอร์มาให้
        // ถ้าไม่ส่ง check_in มา ให้ใช้วันนี้ (Today)
        $checkIn = $request->check_in ? Carbon::parse($request->check_in) : Carbon::today();
        
        // ถ้าไม่ส่ง check_out มา ให้ใช้พรุ่งนี้ (Tomorrow)
        $checkOut = $request->check_out ? Carbon::parse($request->check_out) : Carbon::tomorrow();
        
        // ถ้าไม่ส่ง guests มา ให้ตั้งค่าเริ่มต้นที่ 1 คนค่ะ
        $guests = $request->guests ?? 1;

        // ดึง Room Types ที่รองรับจำนวนแขกที่ระบุ (หรือ Default)
        $availableRoomTypes = RoomType::where('max_guests', '>=', $guests)
            ->get()
            ->map(function ($type) use ($checkIn, $checkOut) {
                return [
                    'room_type_id' => $type->id,
                    'name' => $type->name_en,
                    'max_guests' => $type->max_guests,
                    'daily_rate' => $type->rate_daily_general,
                    'extra_bed_price' => $type->extra_bed_enabled ? $type->extra_bed_price : 0,
                    // 🎁 หนูแถมข้อมูล Search Criteria กลับไปให้ Frontend รู้ด้วยค่ะว่าดึงของวันไหนอยู่
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

    // 4️⃣ API บันทึกการจอง (Process 2.3)
    public function createBooking(Request $request)
    {
        // 1. ตรวจสอบข้อมูลที่ส่งมา (Validation)
        $validated = $request->validate([
            'source' => 'required|string',
            'check_in' => 'required|date|after_or_equal:today', 
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1',
            
            // ข้อมูลห้องพัก
            'booking_rooms' => 'required|array',
            'booking_rooms.*.room_type_id' => 'required|uuid|exists:room_types,id',
            'booking_rooms.*.quantity' => 'required|integer|min:1',
            'booking_rooms.*.extra_beds' => 'integer|min:0',

            // 🌟 [เพิ่มใหม่] ข้อมูล Add-ons (อนุญาตให้ว่างได้ถ้าลูกค้าไม่เลือก)
            'addons' => 'nullable|array',
            'addons.*.addon_id' => 'required|uuid|exists:addons,id',
            'addons.*.quantity' => 'required|integer|min:1'
        ]);

        try {
            DB::beginTransaction();

            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = Carbon::parse($validated['check_out']);
            $nights = $checkIn->diffInDays($checkOut);
            
            if ($nights === 0) $nights = 1;

            $totalAmount = 0;
            $confirmationNo = Carbon::now()->format('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT); 

            // 3. สร้างข้อมูลการจองหลัก (Booking)
            $booking = Booking::create([
                'id' => Str::uuid(),
                'confirmation_no' => $confirmationNo, 
                'booking_type' => 'individual', 
                'source' => $validated['source'],
                'status' => 'draft', 
                'check_in' => $validated['check_in'], 
                'check_out' => $validated['check_out'], 
                'adults' => $validated['adults'], 
                'total_amount' => 0, 
                'payment_deadline' => Carbon::now()->addHours(24)
            ]);

            // 4. บันทึกข้อมูลห้องที่จอง (Booking Rooms)
            foreach ($validated['booking_rooms'] as $roomRequest) {
                $roomType = RoomType::findOrFail($roomRequest['room_type_id']);
                
                for ($i = 0; $i < $roomRequest['quantity']; $i++) {
                    $roomPriceTotal = $roomType->rate_daily_general * $nights;
                    $extraBedTotal = ($roomRequest['extra_beds'] * $roomType->extra_bed_price) * $nights;
                    $subtotal = $roomPriceTotal + $extraBedTotal;
                    
                    $totalAmount += $subtotal;

                    BookingRoom::create([
                        'id' => Str::uuid(),
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

            // 🌟 [เพิ่มใหม่] 5. บันทึกข้อมูลบริการเสริม (Save Add-ons Data เข้า D3.3)
            if (!empty($validated['addons'])) {
                foreach ($validated['addons'] as $addonRequest) {
                    $addon = Addon::findOrFail($addonRequest['addon_id']);
                    
                    // สมมติว่าคิดราคา Add-on เป็นต่อชิ้น/ต่อครั้งที่เลือก (ถ้าต้องคูณจำนวนคืนด้วยก็คูณ $nights ได้เลยค่ะ)
                    $addonSubtotal = $addon->price * $addonRequest['quantity'];
                    
                    $totalAmount += $addonSubtotal; // บวกยอด Add-on เข้าไปในราคาสุทธิ

                    BookingAddon::create([
                        'id' => Str::uuid(),
                        'booking_id' => $booking->id,
                        'addon_id' => $addon->id,
                        'quantity' => $addonRequest['quantity'],
                        'price' => $addon->price,
                        'subtotal' => $addonSubtotal
                    ]);
                }
            }

            // อัปเดตยอดรวมกลับไปที่ Booking
            $booking->update(['total_amount' => $totalAmount]); 

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Booking created successfully',
                'data' => [
                    'booking_id' => $booking->id,
                    'confirmation_no' => $booking->confirmation_no,
                    'nights' => $nights,
                    'total_amount' => $booking->total_amount,
                    'payment_deadline' => $booking->payment_deadline,
                    'note' => 'Room No. will be assigned on the check-in day.'
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create booking: ' . $e->getMessage()
            ], 500);
        }
    }
}