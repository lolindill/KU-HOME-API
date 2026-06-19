<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Addon;
use App\Models\AddonRate;
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
                    'status' => 'error', 
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

            if ($term) {
                $query = $this->applyUserFilter($query, $term);
            }
            if ($checkIn && $checkOut) {
                $query = $this->applyDateFilter($query, $checkIn, $checkOut);
            }
            if ($roomTypeId !== 'all') {
                $query = $this->applyRoomTypeFilter($query, $roomTypeId);
            }

            $perPage = $request->query('per_page', 15);
            $bookings = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'ดึงข้อมูลสำเร็จแล้วค่ะนายท่าน! ✨',
                'user' => $user->id,
                'search_criteria' => [
                    'term' => $term,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'room_type' => $roomTypeId,
                ],
                'bookings' => $bookings->items(),
                'pagination' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total(),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error("Server error getting bookings: " . $e->getMessage(), [
                'user_id' => optional($request->user('sanctum'))->id,
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭'
            ], 500);
        }
    }

    // 🚧 DRAFT / TESTING — ยังไม่ใช้งานจริง ระบบส่วนลดยังไม่สมบูรณ์
    public function validateDiscount(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'subtotal' => 'required|numeric',
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
  
    public function createBooking(StoreBookingRequest $request)
    {
        try {
            $validated = $request->validated();

            // 🛑 1. ดักจับสายดอง: ถ้านายท่านมีบิล Draft ที่ยังไม่หมดเวลา ห้ามสร้างใหม่เด็ดขาด!
            // 🌟 Refactor (18/06/26): Guest/Non-member ใช้งานไม่ได้แล้ว — เช็คแค่ user ที่ล็อกอิน
            $userId = $request->user('sanctum')?->id;
            if (!$userId) {
                throw new \Exception("กรุณาล็อกอินก่อนทำการจองค่ะนายท่าน! 🔒", 401);
            }

            $hasDraft = Booking::where('user_id', $userId)
                ->where('status', 'draft')
                ->where('payment_deadline', '>', Carbon::now())
                ->exists();

            if ($hasDraft) {
                throw new \Exception("มีรายการจองที่รอชำระเงินอยู่ค่ะ กรุณาทำรายการเดิมให้เสร็จสิ้นก่อนนะคะ", 422);
            }

            DB::beginTransaction();

            $checkIn = Carbon::parse($validated['check_in']);
            $checkOut = Carbon::parse($validated['check_out']);
            $nights = $checkIn->diffInDays($checkOut) ?: 1;

            $requestedRoomTypes = [];

            foreach ($validated['booking_rooms'] as $roomRequest) {
                $rtId = $roomRequest['room_type_id'];
                $requestedRoomTypes[$rtId] = ($requestedRoomTypes[$rtId] ?? 0) + 1; 
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

            $confirmationNo = Booking::generateUniqueConfirmation();

            $booking = Booking::create([
                'confirmation' => $confirmationNo, 
                'user_id' => $userId,
                'source' => $validated['source'],
                'status' => 'draft',
                'check_in' => $validated['check_in'], 
                'check_out' => $validated['check_out'], 

                // 🌟 Refactor (18/06/26): ข้อมูลผู้เข้าพักย้ายไป booking_rooms แล้ว

                'total_amount' => 0, 
                'payment_deadline' => Carbon::now()->addHours(24)
            ]);
            
            $totalAmount = 0;

            // 🌟 Refactor (19/06/26): ดึง rate จาก addon_rates (server-side) ทีเดียวจบ
            // ไม่รับ price จาก client อีกต่อไป — ป้องกัน price manipulation (#20)
            $rates = AddonRate::getPrices(['breakfast', 'early_checkin', 'late_checkout', 'extra_bed']);

            // 🌟 ปรับลูปให้สร้าง BookingRoom และ Addon ไปพร้อมๆ กันต่อห้องเลยค่ะ
            foreach ($validated['booking_rooms'] as $roomRequest) {
                $roomType = RoomType::findOrFail($roomRequest['room_type_id']);

                $roomPriceTotal = $roomType->rate_daily_general * $nights;
                $extraBedQty = $roomRequest['extra_beds'] ?? 0;
                $extraBedUnit = $rates['extra_bed'] ?? 0;
                $extraBedTotal = ($extraBedQty * $extraBedUnit) * $nights;

                // คำนวณราคา Addon ของห้องนี้ (rate จาก server เท่านั้น)
                $addons = $roomRequest['addons'] ?? [];
                $breakfastQty = $addons['breakfast'] ?? 0;
                $breakfastPrice = $breakfastQty * ($rates['breakfast'] ?? 0);
                $earlyCheckInPrice = !empty($addons['early_checkin']) ? ($rates['early_checkin'] ?? 0) : 0;
                $lateCheckOutPrice = !empty($addons['late_checkout']) ? ($rates['late_checkout'] ?? 0) : 0;

                // รวมยอดของห้องนี้ทั้งหมด
                $subtotal = $roomPriceTotal + $extraBedTotal + $breakfastPrice + $earlyCheckInPrice + $lateCheckOutPrice;
                $totalAmount += $subtotal;

                $bookingRoom = BookingRoom::create([
                    'booking_id' => $booking->id,
                    'room_type_id' => $roomType->id,
                    'room_id' => null, // รอจ่ายห้องตอน Check-in
                    // 🌟 Refactor (18/06/26): เก็บข้อมูลผู้เข้าพักหลายคนในห้องนี้
                    'guests' => $roomRequest['guests'] ?? null,
                    'children' => $roomRequest['children'] ?? 0,
                ]);

                // 🌟 บันทึก Addon โดยผูกกับ booking_room_id แทนค่ะ
                Addon::create([
                    'booking_room_id' => $bookingRoom->id,
                    'extra_bed' => $extraBedQty,
                    'extra_bed_price' => $extraBedTotal,
                    'breakfast' => $breakfastQty,
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
                'booking_id' => $booking->id,
                'total_amount' => $booking->total_amount,
                'payment_deadline' => $booking->payment_deadline->toDateTimeString(),
                'user_id' => $userId,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // 🛡️ #40 Fixed: Business logic errors (422) ส่ง message ได้, unexpected errors ซ่อน
            $code = $e->getCode();
            if (in_array($code, [401, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ], $code);
            }

            Log::error("Failed to create booking: " . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการสร้างการจอง กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭'
            ], 500);
        }
    }

    private function applyUserFilter($query, $term)
    {
        if (empty($term)) {
            return $query;
        }

        // 🛡️ Escape LIKE wildcards เพื่อป้องกัน wildcard abuse (#24)
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);

        // 🌟 Refactor (18/06/26): ลบ guest_name (ย้ายไป booking_rooms.guests แล้ว) — ค้นผ่าน user กับ primary_guest_name แทน
        return $query->where(function ($q) use ($escaped, $term) {
            if (Str::isUuid($term)) {
                $q->where('user_id', $term)
                  ->orWhereHas('user', function ($userQuery) use ($escaped) {
                      $userQuery->where('name', 'LIKE', '%' . $escaped . '%');
                  })
                  ->orWhereHas('bookingRooms', function ($brQuery) use ($escaped) {
                      $brQuery->whereRaw('LOWER(JSON_EXTRACT(guests, "$[0].name")) LIKE ?', ['%' . strtolower($escaped) . '%']);
                  });
            } else {
                $q->whereHas('user', function ($userQuery) use ($escaped) {
                    $userQuery->where('name', 'LIKE', '%' . $escaped . '%');
                })
                ->orWhereHas('bookingRooms', function ($brQuery) use ($escaped) {
                    $brQuery->whereRaw('LOWER(JSON_EXTRACT(guests, "$[0].name")) LIKE ?', ['%' . strtolower($escaped) . '%']);
                });
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
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
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
                    'status' => 'error',
                    'message' => 'กรุณาเข้าสู่ระบบก่อนนะคะนายท่าน!'
                ], 401);
            }

            $booking = Booking::with(['user', 'bookingRooms.addon', 'bookingRooms.roomType', 'bookingRooms.room'])
                ->where('id', $id)
                ->firstOrFail();

            if ($user->role !== 'admin' && $booking->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'นายท่านไม่มีสิทธิ์ดูข้อมูลการจองของผู้อื่นนะคะ! 🔒'
                ], 403);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'ดึงข้อมูลการจองเรียบร้อยแล้วค่ะ! ✨',
                'booking' => $booking,
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบรหัสการจองนี้ในระบบค่ะนายท่าน 🔎',
            ], 404);
        } catch (\Exception $e) {
            Log::error("Failed to show booking: " . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    // 🌟 Refactor (18/06/26): lookupBooking ถูกลบแล้ว — Guest/Non-member ไม่สามารถใช้งานระบบได้
    // นายท่านต้องล็อกอินก่อน แล้วใช้ showById เพื่อดูข้อมูลการจองของตัวเองได้เลยค่ะ

   public function autoAssignRooms(Request $request, $bookingId)
    {
        try {
            DB::beginTransaction();

            // 🌟 1. โหลดข้อมูลการจอง พร้อมกับห้องพัก และ Addon มาด้วยเลย
            $booking = Booking::with(['bookingRooms.addon'])->findOrFail($bookingId);
  
            // =========================================================
            // 🛡️ ด่านตรวจที่ 1: เช็คสิทธิ์ User (Token ตรงกับเจ้าของ หรือเป็น Admin)
            // =========================================================
            $user = $request->user('sanctum');
            
            // ถ้านายท่านยังไม่ได้ล็อกอิน
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'นายท่านยังไม่ได้ล็อกอินนะคะ! กรุณาแนบ Token ก่อนทำรายการค่ะ 🔒',
                    'user' => $user
                ], 401);
            }

            // ถ้าไม่ใช่ Admin และ ID ไม่ตรงกับคนจอง
            if ($user->role !== 'admin' && $booking->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่อนุญาตค่ะนายท่าน! สิทธิ์นี้เฉพาะแอดมินหรือเจ้าของบุ๊กกิ้งเท่านั้นนะคะ 🙅‍♀️'
                ], 403);
            }

            // =========================================================
            // 🛡️ ด่านตรวจที่ 2: เช็คสถานะ Booking (ต้อง paid หรือ confirmed เท่านั้น)
            // =========================================================
            if (!in_array($booking->status, ['paid', 'confirmed'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "ยังระบุเลขห้องไม่ได้ค่ะนายท่าน! สถานะปัจจุบันคือ '{$booking->status}' (ต้องจ่ายเงิน 'paid' หรือยืนยัน 'confirmed' ก่อนนะคะ) 💳"
                ], 422);
            }

            // =========================================================
            // 🌟 เริ่มกระบวนการ Assign ห้อง (เรียกใช้ Logic จาก Model)
            // =========================================================
            $unassignedRooms = $booking->bookingRooms()->whereNull('room_id')->get();
            $unassignedRooms->load('addon');

            if ($unassignedRooms->isEmpty()) {
                // เช็คกันเหนียวเผื่อบุ๊กกิ้งนี้ไม่มีห้องเลยจริงๆ
                if ($booking->bookingRooms()->count() === 0) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'เอ๊ะ! บุ๊กกิ้งนี้ยังไม่มีการจองห้องพักเข้ามาเลยนะคะนายท่าน! 💦'
                    ], 422);
                }

                return response()->json([
                    'status' => 'info',
                    'message' => 'ห้องพักทั้งหมดในบุ๊กกิ้งนี้ถูกระบุเลขห้องเรียบร้อยแล้วค่ะนายท่าน! ✨'
                ]);
            }

            $checkIn = $booking->check_in;
            $checkOut = $booking->check_out;
            $assignedCount = 0;

            foreach ($unassignedRooms as $bookingRoom) {
                // 🌟 เรียกใช้ Method จาก Model แบบหล่อๆ เท่ๆ ไปเลยค่ะ ไม่มี Parameter มากวนใจ!
                $isSuccess = $bookingRoom->assignAvailableRoom();

                if ($isSuccess) {
                    $assignedCount++;
                } else {
                    // ถ้าหาห้องไม่ได้ ให้ Throw Exception ออกไปให้ Catch ทำงาน
                    throw new \Exception("แย่แล้วค่ะนายท่าน! ไม่มีห้องพักว่างให้ระบุเลขห้องได้ในช่วงเวลาดังกล่าวค่ะ 😭 (ตรวจสอบห้องประเภท ID: {$bookingRoom->room_type_id})");
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "หนูจัดการระบุเลขห้องอัตโนมัติให้จำนวน {$assignedCount} ห้องเรียบร้อยแล้วค่ะนายท่าน! 🎉",
                'booking' => $booking->load('bookingRooms.room'),
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลการจองนี้ในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to auto-assign room: " . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'หนูขอโทษค่ะ เกิดข้อผิดพลาด: ' . $e->getMessage()
            ], 422);
        }
    }
}