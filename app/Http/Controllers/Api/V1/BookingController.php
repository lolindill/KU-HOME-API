<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddBookingRoomsRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRoomRequest;
use App\Http\Requests\UpdateBookingRoomsRequest;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StatusChangeLog;
use App\Models\User;
use App\Services\Discount\DiscountService;
use App\Services\RoomAllocator\RoomAllocator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function getBookings(Request $request)
    {
        try {
            $user = $request->user('sanctum');

            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ต้องล็อกอินในระบบ หรือระบุเลข Guest ID ค่ะนายท่าน!',
                ], 401);
            }

            $term = $request->query('term');
            $roomTypeId = $request->query('room_type', 'all');
            $checkIn = $request->query('check_in');
            $checkOut = $request->query('check_out');

            // 🌟 Eager load bookingRooms.addon (roomType และ room ถูกซ่อน/ไม่ load เพื่อลด payload)
            $query = Booking::with('bookingRooms.addon')
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
            Log::error('Server error getting bookings: '.$e->getMessage(), [
                'user_id' => optional($request->user('sanctum'))->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    /**
     * 🎟️ ใส่ / เปลี่ยน โค้ดส่วนลดให้กับการจองสถานะ draft (เจ้าของหรือ admin)
     */
    public function setDiscountCode(Request $request, string $bookingId)
    {
        try {
            $user = $request->user('sanctum');
            if (! $user) {
                throw new \Exception('กรุณาล็อกอินก่อนดำเนินการค่ะนายท่าน! 🔒', 401);
            }

            $booking = Booking::findOrFail($bookingId);

            if ($booking->user_id !== $user->id && $user->role !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'คุณไม่มีสิทธิ์แก้ไขการจองนี้ค่ะ',
                ], 403);
            }

            if ($booking->status !== 'draft') {
                throw new \Exception('ใส่หรือลบโค้ดได้เฉพาะการจองสถานะ draft เท่านั้นค่ะ 📝', 422);
            }

            $request->validate([
                'code' => 'required|string|max:50',
            ]);

            $discountService = app(DiscountService::class);
            $updatedBooking = $discountService->applyToDraft($booking, $request->code);

            return response()->json([
                'status' => 'success',
                'message' => 'ใส่โค้ดส่วนลดเรียบร้อยแล้วค่ะ! 🎟️',
                'booking' => $updatedBooking->fresh(['bookingRooms.addon']),
            ], 200);

        } catch (ValidationException $e) {
            // 🛡️ (27/08/26): validation ต้องคืน 422 มาตรฐาน Laravel — ไม่งั้น getCode()=0 ตกไป branch 500
            throw $e;
        } catch (\Exception $e) {
            $code = $e->getCode();
            if (in_array($code, [401, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่พบรายการจองที่ระบุค่ะ',
                ], 404);
            }

            Log::error('Failed to set discount code: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการใส่โค้ดส่วนลด กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    /**
     * 🎟️ ลบโค้ดส่วนลดออกจากการจองสถานะ draft (เจ้าของหรือ admin)
     */
    public function destroyDiscountCode(Request $request, string $bookingId)
    {
        try {
            $user = $request->user('sanctum');
            if (! $user) {
                throw new \Exception('กรุณาล็อกอินก่อนดำเนินการค่ะนายท่าน! 🔒', 401);
            }

            $booking = Booking::findOrFail($bookingId);

            if ($booking->user_id !== $user->id && $user->role !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'คุณไม่มีสิทธิ์แก้ไขการจองนี้ค่ะ',
                ], 403);
            }

            if ($booking->status !== 'draft') {
                throw new \Exception('ใส่หรือลบโค้ดได้เฉพาะการจองสถานะ draft เท่านั้นค่ะ 📝', 422);
            }

            $discountService = app(DiscountService::class);
            $updatedBooking = $discountService->removeFromDraft($booking);

            return response()->json([
                'status' => 'success',
                'message' => 'ลบโค้ดส่วนลดออกจากการจองเรียบร้อยแล้วค่ะ 🎟️',
                'booking' => $updatedBooking->fresh(['bookingRooms.addon']),
            ], 200);

        } catch (\Exception $e) {
            $code = $e->getCode();
            if (in_array($code, [401, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่พบรายการจองที่ระบุค่ะ',
                ], 404);
            }

            Log::error('Failed to destroy discount code: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการลบโค้ดส่วนลด กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    /**
     * 🌟 เพิ่มห้องเข้าไปใน booking ที่สร้างไว้แล้ว (เฉพาะ draft state เท่านั้น)
     *
     * Logic reuse จาก createBooking (availability check + pricing + create BR + Addon)
     * แต่ไม่สร้าง Booking ใหม่ — เพิ่ม booking_rooms เข้าไปใน container เดิม
     *
     * Constraints:
     * - booking.status ต้องเป็น 'draft' เท่านั้น (🔒 core guard)
     * - เจ้าของ booking หรือ admin เท่านั้นที่เพิ่มได้
     * - ไม่เรียก RoomAllocator (draft ยังไม่จ่ายห้อง — เหมือน createBooking)
     * - ไม่เรียก transitionStatus (ห้องใหม่สร้างด้วย status='draft' ตรงๆ = initial state)
     */
    public function addRooms(AddBookingRoomsRequest $request, $bookingId)
    {
        $inTransaction = false;

        try {
            $validated = $request->validated();

            // 🛑 Auth check
            $user = $request->user('sanctum');
            if (! $user) {
                throw new \Exception('กรุณาล็อกอินก่อนเพิ่มห้องค่ะนายท่าน! 🔒', 401);
            }

            $booking = Booking::findOrFail($bookingId);

            // 🔐 Ownership check — เจ้าของ booking หรือ admin เท่านั้น
            if ($booking->user_id !== $user->id && $user->role !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'คุณไม่มีสิทธิ์แก้ไขการจองนี้ค่ะ',
                ], 403);
            }

            // 🔒 Draft guard — เพิ่มห้องได้เฉพาะ draft state เท่านั้น
            if ($booking->status !== 'draft') {
                throw new \Exception('ไม่สามารถเพิ่มห้องได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะนายท่าน', 422);
            }

            DB::beginTransaction();
            $inTransaction = true;

            // 🌟 Availability check (reuse pattern จาก createBooking)
            // group by room_type_id + ตรวจ overlap ของแต่ละช่วงวัน
            // 🌟 สำคัญ: existing count รวมห้องที่อยู่ใน booking นี้แล้วด้วย (มี status='draft' overlap)
            $requestedByType = [];

            foreach ($validated['booking_rooms'] as $roomRequest) {
                $rtId = $roomRequest['room_type_id'];
                $requestedByType[$rtId][] = [
                    'check_in' => $roomRequest['check_in'],
                    'check_out' => $roomRequest['check_out'],
                ];
            }

            foreach ($requestedByType as $rtId => $requests) {
                $totalRooms = Room::where('room_type_id', $rtId)->count();

                // ตรวจทีละช่วงวันของห้องที่ขอเพิ่ม — นับ existing bookings + batch overlaps
                foreach ($requests as $checkReq) {
                    $checkIn = Carbon::parse($checkReq['check_in']);
                    $checkOut = Carbon::parse($checkReq['check_out']);

                    // 🌟 availability นับที่ BR-level (block ห้องตั้งแต่ draft ขึ้นไป)
                    $existingBooked = BookingRoom::where('room_type_id', $rtId)
                        ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
                        ->where('check_in', '<', $checkOut)
                        ->where('check_out', '>', $checkIn)
                        ->count();

                    // นับห้องใน batch นี้ที่ overlap กับช่วงวันของห้องปัจจุบัน
                    $batchOverlapping = 0;
                    foreach ($requests as $otherReq) {
                        $otherIn = Carbon::parse($otherReq['check_in']);
                        $otherOut = Carbon::parse($otherReq['check_out']);
                        if ($otherIn < $checkOut && $otherOut > $checkIn) {
                            $batchOverlapping++;
                        }
                    }

                    if (($existingBooked + $batchOverlapping) > $totalRooms) {
                        throw new \Exception('ขออภัยค่ะนายท่าน ห้องพักประเภทที่เลือกเต็มแล้วในช่วงเวลาดังกล่าวค่ะ', 422);
                    }
                }
            }

            // 🌟 ดึง rate จาก global_rates (server-side) — เหมือน createBooking
            $rates = GlobalRate::getPrices(['breakfast', 'early_checkin', 'late_checkout', 'extra_bed']);

            $addedAmount = 0;
            $addedRooms = [];

            // 🌟 สร้าง BookingRoom + Addon ทีละห้อง (clone pattern จาก createBooking)
            foreach ($validated['booking_rooms'] as $roomRequest) {
                $roomType = RoomType::findOrFail($roomRequest['room_type_id']);

                // คำนวณ nights รายห้อง
                $roomCheckIn = Carbon::parse($roomRequest['check_in']);
                $roomCheckOut = Carbon::parse($roomRequest['check_out']);
                $nights = $roomCheckIn->diffInDays($roomCheckOut) ?: 1;

                // 🌟 room rate จาก global_rates (คำนึงถึงสิทธิ์ ku_member)
                $roomPriceTotal = GlobalRate::getEffectiveDailyRate($roomType, $booking->user) * $nights;
                $extraBedQty = $this->resolveExtraBed($roomRequest, $roomRequest['addons'] ?? null);
                $extraBedUnit = $rates['extra_bed'] ?? 0;
                $extraBedTotal = ($extraBedQty * $extraBedUnit) * $nights;

                // คำนวณราคา Addon ของห้องนี้ (rate จาก server เท่านั้น)
                $addons = $roomRequest['addons'] ?? [];
                $breakfastQty = $addons['breakfast'] ?? 0;
                $breakfastPrice = $breakfastQty * ($rates['breakfast'] ?? 0);
                [$earlyHours, $lateHours] = $this->resolveEarlyLate($addons);
                $earlyCheckInPrice = $earlyHours * ($rates['early_checkin'] ?? 0);
                $lateCheckOutPrice = $lateHours * ($rates['late_checkout'] ?? 0);

                // รวมยอดของห้องใหม่นี้
                $subtotal = $roomPriceTotal + $extraBedTotal + $breakfastPrice + $earlyCheckInPrice + $lateCheckOutPrice;
                $addedAmount += $subtotal;

                $bookingRoom = BookingRoom::create([
                    'booking_id' => $booking->id,
                    'room_type_id' => $roomType->id,
                    'room_id' => null, // รอจ่ายห้องตอน Check-in
                    'check_in' => $roomRequest['check_in'],
                    'check_out' => $roomRequest['check_out'],
                    'status' => 'draft', // BR-level state (initial, ไม่ใช่ transition)
                    'bed_preference' => $roomRequest['bed_preference'] ?? null,
                    'guests' => isset($roomRequest['guests']) ? $this->stripGuestFields($roomRequest['guests']) : null,
                    'billing_address' => $roomRequest['billing_address'] ?? null,
                    'billing_comment' => $roomRequest['billing_comment'] ?? null,
                ]);

                // 🌟 บันทึก Addon โดยผูกกับ booking_room_id
                Addon::create([
                    'booking_room_id' => $bookingRoom->id,
                    'extra_bed' => $extraBedQty,
                    'extra_bed_price' => $extraBedTotal,
                    'breakfast' => $breakfastQty,
                    'breakfast_price' => $breakfastPrice,
                    'early_checkIn_price' => $earlyCheckInPrice,
                    'early_hours' => $earlyHours,
                    'late_checkOut_price' => $lateCheckOutPrice,
                    'late_hours' => $lateHours,
                ]);

                $addedRooms[] = $bookingRoom;
            }

            // 🌟 Reconcile ส่วนลด & total_amount (27/08/26)
            $this->reconcileDiscount($booking);

            DB::commit();

            $bookingRoomsResponse = array_map(function ($br) {
                return $br->fresh('addon');
            }, $addedRooms);

            return response()->json([
                'status' => 'success',
                'message' => 'เพิ่มห้องเข้าการจองเรียบร้อยแล้วค่ะ',
                'booking_id' => $booking->id,
                'booking_rooms' => $bookingRoomsResponse,
                'added_amount' => $addedAmount,
                'total_amount' => $booking->fresh()->total_amount,
                'payment_deadline' => $booking->payment_deadline->toDateTimeString(),
            ], 200);

        } catch (\Exception $e) {
            // 🛡️ rollBack เฉพาะเมื่อเรา beginTransaction เอง — early guards (401/403/404/422)
            //    โยน/return ก่อน begin แล้ว rollBack เปล่าจะไปยกเลิก transaction ของผู้เรียก
            if ($inTransaction) {
                DB::rollBack();
            }

            // 🛡️ #40 pattern: Business logic errors (422) ส่ง message ได้, unexpected ซ่อน
            $code = $e->getCode();
            if (in_array($code, [401, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            // ModelNotFoundException → 404 (booking ไม่พบ)
            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่พบรายการจองที่ระบุค่ะ',
                ], 404);
            }

            Log::error('Failed to add rooms to booking: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการเพิ่มห้อง กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    /**
     * 🌟 (17/08/26): ลบ draft booking (เจ้าของหรือ admin)
     *
     * Hard delete cascade แบบเดียวกับ CleanupExpiredDrafts:
     * addon ของทุก BR → BR → payments (frozen) → confirmations → booking
     *
     * Constraints:
     * - booking.status ต้องเป็น 'draft' เท่านั้น (จ่ายเงิน/ยืนยันแล้วลบไม่ได้)
     * - เป็นการ hard delete ไม่ใช่ transition ใหม่ใน state machine
     *   แต่ยังเขียน StatusChangeLog (draft → deleted) เก็บ audit trail ไว้ค่ะ
     */
    public function destroyBooking(Request $request, $bookingId)
    {
        try {
            // 🛑 Auth check
            $user = $request->user('sanctum');
            if (! $user) {
                throw new \Exception('กรุณาล็อกอินก่อนลบรายการจองค่ะนายท่าน! 🔒', 401);
            }

            $booking = Booking::findOrFail($bookingId);

            // 🔐 Ownership check — เจ้าของ booking หรือ admin เท่านั้น
            if ($booking->user_id !== $user->id && $user->role !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'คุณไม่มีสิทธิ์ลบการจองนี้ค่ะ',
                ], 403);
            }

            // 🔒 Draft guard — ลบได้เฉพาะ draft เท่านั้น
            if ($booking->status !== 'draft') {
                throw new \Exception('ไม่สามารถลบได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะ (ชำระเงินหรือยืนยันแล้ว)', 422);
            }

            DB::beginTransaction();
            try {
                // 🌟 lock + re-check กัน race กับ confirm/verify ที่กำลัง draft→paid พร้อมกัน
                $locked = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'draft') {
                    throw new \Exception('ไม่สามารถลบได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะ (ชำระเงินหรือยืนยันแล้ว)', 422);
                }

                foreach ($locked->bookingRooms as $br) {
                    $br->addon()?->delete();
                    $br->delete();
                }
                // frozen legacy table — ลบทิ้งเหมือน CleanupExpiredDrafts
                $locked->payments()->delete();
                // defense-in-depth: draft ไม่ควรมี confirmation (confirm จะ transition เป็น paid ทันที) แต่ลบกันเหนียว
                // 🖼️ ลบรูปสลิปก่อนลบ confirmation — morph ไม่ cascade เอง (hook deleting ลบไฟล์บน disk ให้)
                $locked->confirmations->each(fn ($confirmation) => $confirmation->slipImage?->delete());
                $locked->confirmations()->delete();

                // 📝 Audit log — เก็บไว้แม้ booking row จะหายไปแล้ว (append-only)
                //    role ใช้ ?? 'user' กันกรณี DB default ยังไม่ถูกอ่านกลับมาใน model instance
                StatusChangeLog::create([
                    'entity_type' => 'booking',
                    'entity_id' => $locked->id,
                    'from_status' => 'draft',
                    'to_status' => 'deleted',
                    'role' => $user->role ?? 'user',
                    'causer_id' => $user->id,
                    'note' => 'ผู้ใช้ลบ draft booking เอง (hard delete)',
                ]);

                $locked->delete();

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'ลบรายการจองเรียบร้อยแล้วค่ะนายท่าน 🗑️',
                'booking_id' => $bookingId,
            ], 200);

        } catch (\Exception $e) {
            // 🛡️ #40 pattern: business errors (401/422) ส่ง message ได้, unexpected ซ่อน
            $code = $e->getCode();
            if (in_array($code, [401, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่พบรายการจองที่ระบุค่ะ',
                ], 404);
            }

            Log::error('Failed to delete draft booking: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการลบรายการจอง กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    /**
     * 🌟 (17/08/26): แก้ไข booking room รายห้อง (เจ้าของหรือ admin)
     *
     * Constraints:
     * - booking.status = 'draft' และ booking_room.status = 'draft' เท่านั้น
     * - แก้ room_type_id/check_in/check_out ได้ โดยเช็ค availability ใหม่ (ตัดตัวเองออกจาก count)
     * - ราคาคิดใหม่ทั้งหมดที่ server (global_rates) — ไม่รับ price จาก client เด็ดขาด
     * - ห้ามแก้ room_id (ต้องผ่าน RoomAllocator) / status (ต้องผ่าน transitionStatus)
     * - payment_deadline คงเดิม (เหมือน addRooms)
     */
    public function updateRoom(UpdateBookingRoomRequest $request, $bookingId, $bookingRoomId)
    {
        try {
            $validated = $request->validated();

            // 🛑 Auth check
            $user = $request->user('sanctum');
            if (! $user) {
                throw new \Exception('กรุณาล็อกอินก่อนแก้ไขห้องค่ะนายท่าน! 🔒', 401);
            }

            $booking = Booking::findOrFail($bookingId);

            // 🔐 Ownership check — เจ้าของ booking หรือ admin เท่านั้น
            if ($booking->user_id !== $user->id && $user->role !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'คุณไม่มีสิทธิ์แก้ไขการจองนี้ค่ะ',
                ], 403);
            }

            // 🔒 Draft guard — booking ต้องเป็น draft
            if ($booking->status !== 'draft') {
                throw new \Exception('ไม่สามารถแก้ไขห้องได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะ', 422);
            }

            // 🔒 BR ต้องอยู่ใต้ booking นี้จริง (ของ booking อื่น = 404)
            $bookingRoom = $booking->bookingRooms()->where('id', $bookingRoomId)->firstOrFail();
            if ($bookingRoom->status !== 'draft') {
                throw new \Exception('ไม่สามารถแก้ไขห้องได้ เนื่องจากห้องไม่ได้อยู่ในสถานะ draft ค่ะ', 422);
            }

            DB::beginTransaction();
            try {
                // 🌟 lock + re-check กัน race กับ confirm/verify ระหว่างแก้ไข
                $locked = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'draft') {
                    throw new \Exception('ไม่สามารถแก้ไขห้องได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะ', 422);
                }

                // 🎯 Merge ฟิลด์ที่ส่งมาลง BR — เช็คค่าเดิมใน DB เพื่ออัปเดตเฉพาะฟิลด์ที่เปลี่ยนจริง
                $dirtyFields = [];
                foreach (['room_type_id', 'check_in', 'check_out', 'guests', 'bed_preference', 'billing_address', 'billing_comment'] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $isDirty = match ($field) {
                            'room_type_id' => (string) $validated['room_type_id'] !== (string) $bookingRoom->room_type_id,
                            'check_in' => Carbon::parse($validated['check_in'])->toDateString() !== $bookingRoom->check_in->toDateString(),
                            'check_out' => Carbon::parse($validated['check_out'])->toDateString() !== $bookingRoom->check_out->toDateString(),
                            'guests' => json_encode($this->stripGuestFields($validated['guests'] ?? null) ?? []) !== json_encode($bookingRoom->guests ?? []),
                            'bed_preference', 'billing_address', 'billing_comment' => ($validated[$field] ?? null) !== ($bookingRoom->$field ?? null),
                            default => true,
                        };

                        if ($isDirty) {
                            $dirtyFields[$field] = $field === 'guests'
                                ? $this->stripGuestFields($validated['guests'] ?? null)
                                : $validated[$field];
                        }
                    }
                }

                // ค่า effective = ค่าใหม่ถ้าส่งมา ไม่งั้นค่าเดิม (ใช้ทั้ง availability + pricing)
                $effectiveTypeId = $validated['room_type_id'] ?? $bookingRoom->room_type_id;
                $effectiveCheckIn = isset($validated['check_in'])
                    ? Carbon::parse($validated['check_in'])->toDateString()
                    : $bookingRoom->check_in->toDateString();
                $effectiveCheckOut = isset($validated['check_out'])
                    ? Carbon::parse($validated['check_out'])->toDateString()
                    : $bookingRoom->check_out->toDateString();

                // 🌟 Availability re-check — เฉพาะเมื่อแก้ประเภทห้องหรือวันที่จริงเท่านั้น
                //    (นับ existing แบบตัดตัวเองออก — ไม่งั้นเปลี่ยนวันที่แล้วนับซ้ำตัวเอง)
                $shapeChanged = isset($dirtyFields['room_type_id'])
                    || isset($dirtyFields['check_in'])
                    || isset($dirtyFields['check_out']);

                if ($shapeChanged) {
                    $checkIn = Carbon::parse($effectiveCheckIn);
                    $checkOut = Carbon::parse($effectiveCheckOut);
                    $totalRooms = Room::where('room_type_id', $effectiveTypeId)->count();

                    $existingBooked = BookingRoom::where('room_type_id', $effectiveTypeId)
                        ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
                        ->where('check_in', '<', $checkOut)
                        ->where('check_out', '>', $checkIn)
                        ->where('id', '!=', $bookingRoom->id)
                        ->count();

                    // +1 = ห้องที่กำลังแก้ไขตัวเอง
                    if (($existingBooked + 1) > $totalRooms) {
                        throw new \Exception('ขออภัยค่ะนายท่าน ห้องพักประเภทที่เลือกเต็มแล้วในช่วงเวลาดังกล่าวค่ะ', 422);
                    }
                }

                if (! empty($dirtyFields)) {
                    $bookingRoom->update($dirtyFields);
                }

                // 🌟 Reprice server-side — extra_beds/addons: ใช้ค่าใหม่ถ้าส่งมา ไม่งั้นค่าเดิมจาก Addon row
                $rates = GlobalRate::getPrices(['breakfast', 'early_checkin', 'late_checkout', 'extra_bed']);
                $existingAddon = $bookingRoom->addon;
                $addonInput = $validated['addons'] ?? null;

                $extraBedQty = $this->resolveExtraBed($validated, $addonInput, $existingAddon);
                $breakfastQty = is_array($addonInput)
                    ? ($addonInput['breakfast'] ?? ($existingAddon?->breakfast ?? 0))
                    : ($existingAddon?->breakfast ?? 0);
                [$earlyHours, $lateHours] = $this->resolveEarlyLate($addonInput, $existingAddon);

                $roomType = RoomType::findOrFail($effectiveTypeId);
                $nights = Carbon::parse($effectiveCheckIn)->diffInDays(Carbon::parse($effectiveCheckOut)) ?: 1;

                $roomPriceTotal = GlobalRate::getEffectiveDailyRate($roomType, $booking->user) * $nights;
                $extraBedTotal = ($extraBedQty * ($rates['extra_bed'] ?? 0)) * $nights;
                $breakfastPrice = $breakfastQty * ($rates['breakfast'] ?? 0);
                $earlyCheckInPrice = $earlyHours * ($rates['early_checkin'] ?? 0);
                $lateCheckOutPrice = $lateHours * ($rates['late_checkout'] ?? 0);

                $addonData = [
                    'extra_bed' => $extraBedQty,
                    'extra_bed_price' => $extraBedTotal,
                    'breakfast' => $breakfastQty,
                    'breakfast_price' => $breakfastPrice,
                    'early_checkIn_price' => $earlyCheckInPrice,
                    'early_hours' => $earlyHours,
                    'late_checkOut_price' => $lateCheckOutPrice,
                    'late_hours' => $lateHours,
                ];
                if ($existingAddon) {
                    $dirtyAddonFields = [];
                    foreach ($addonData as $k => $v) {
                        if ((int) $existingAddon->$k !== (int) $v) {
                            $dirtyAddonFields[$k] = $v;
                        }
                    }
                    if (! empty($dirtyAddonFields)) {
                        $existingAddon->update($dirtyAddonFields);
                    }
                } else {
                    Addon::create(array_merge(['booking_room_id' => $bookingRoom->id], $addonData));
                }

                // 🌟 Reconcile ส่วนลด & total_amount (27/08/26)
                $this->reconcileDiscount($locked);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'แก้ไขห้องเรียบร้อยแล้วค่ะ',
                'booking_id' => $booking->id,
                'booking_room' => $bookingRoom->fresh('addon'),
                'total_amount' => $locked->fresh()->total_amount,
            ], 200);

        } catch (\Exception $e) {
            // 🛡️ #40 pattern: business errors (401/422) ส่ง message ได้, unexpected ซ่อน
            $code = $e->getCode();
            if (in_array($code, [401, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่พบรายการจองหรือห้องที่ระบุค่ะ',
                ], 404);
            }

            Log::error('Failed to update booking room: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการแก้ไขห้อง กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    /**
     * 🌟 (19/08/26): แก้ไข booking room หลายห้องพร้อมกัน — batch update (เจ้าของหรือ admin)
     *
     * 1 array entry = การแก้ 1 ห้อง (payload ต่างกันได้รายห้อง) — ต่างจาก updateRoom
     * ที่ระบุ bookingRoomId ใน path ตัวเดียว ตรงนี้ระบุ booking_room_id รายแถวใน body
     *
     * Constraints (all-or-nothing — ห้องใดห้องหนึ่ง fail = rollback ทั้งชุด):
     * - booking.status = 'draft' และ booking_room.status = 'draft' ทุกห้องเท่านั้น
     * - แก้ room_type_id/check_in/check_out ได้ โดยเช็ค availability ใหม่จาก final state ของทั้ง batch
     *   (existing count ตัดทุกห้องใน batch ออก แล้วนับ batch overlaps รวมห้องที่ไม่ได้เปลี่ยน shape)
     * - ราคาคิดใหม่ทั้งหมดที่ server (global_rates) — ไม่รับ price จาก client เด็ดขาด
     * - ห้ามแก้ room_id (ต้องผ่าน RoomAllocator) / status (ต้องผ่าน transitionStatus)
     * - payment_deadline คงเดิม (เหมือน addRooms/updateRoom)
     */
    public function updateRooms(UpdateBookingRoomsRequest $request, $bookingId)
    {
        try {
            $validated = $request->validated();

            // 🛑 Auth check
            $user = $request->user('sanctum');
            if (! $user) {
                throw new \Exception('กรุณาล็อกอินก่อนแก้ไขห้องค่ะนายท่าน! 🔒', 401);
            }

            $booking = Booking::findOrFail($bookingId);

            // 🔐 Ownership check — เจ้าของ booking หรือ admin เท่านั้น
            if ($booking->user_id !== $user->id && $user->role !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'คุณไม่มีสิทธิ์แก้ไขการจองนี้ค่ะ',
                ], 403);
            }

            // 🔒 Draft guard — booking ต้องเป็น draft
            if ($booking->status !== 'draft') {
                throw new \Exception('ไม่สามารถแก้ไขห้องได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะ', 422);
            }

            // 🔒 ทุก BR ต้องอยู่ใต้ booking นี้จริง (ของ booking อื่น = 404) — ดึงครั้งเดียว
            //    (distinct rule รับประกันไม่มี id ซ้ำใน batch)
            $ids = array_column($validated['booking_rooms'], 'booking_room_id');
            $rooms = $booking->bookingRooms()->whereIn('id', $ids)->get()->keyBy('id');
            if ($rooms->count() !== count($ids)) {
                throw new ModelNotFoundException;
            }

            // 🔒 Pre-flight: ทุกห้องต้องเป็น draft ก่อนแตะอะไร (all-or-nothing)
            foreach ($rooms as $room) {
                if ($room->status !== 'draft') {
                    throw new \Exception('ไม่สามารถแก้ไขห้องได้ เนื่องจากห้องไม่ได้อยู่ในสถานะ draft ค่ะ', 422);
                }
            }

            DB::beginTransaction();
            try {
                // 🌟 lock + re-check กัน race กับ confirm/verify ระหว่างแก้ไข
                $locked = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'draft') {
                    throw new \Exception('ไม่สามารถแก้ไขห้องได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะ', 422);
                }

                // 🎯 เตรียมข้อมูลรายห้อง: fields ที่จะ merge + effective (final) values
                //    ห้าม room_id/status/booking_id — validated ไม่มีฟิลด์พวกนี้อยู่แล้ว
                $fields = ['room_type_id', 'check_in', 'check_out', 'guests', 'bed_preference', 'billing_address', 'billing_comment'];
                $updates = []; // booking_room_id => ['model', 'request', 'dirty_fields', 'type_id', 'check_in', 'check_out', 'shape_changed']

                foreach ($validated['booking_rooms'] as $roomRequest) {
                    $brId = $roomRequest['booking_room_id'];
                    $bookingRoom = $rooms[$brId];

                    $dirtyFields = [];
                    foreach ($fields as $field) {
                        if (array_key_exists($field, $roomRequest)) {
                            $isDirty = match ($field) {
                                'room_type_id' => (string) $roomRequest['room_type_id'] !== (string) $bookingRoom->room_type_id,
                                'check_in' => Carbon::parse($roomRequest['check_in'])->toDateString() !== $bookingRoom->check_in->toDateString(),
                                'check_out' => Carbon::parse($roomRequest['check_out'])->toDateString() !== $bookingRoom->check_out->toDateString(),
                                'guests' => json_encode($this->stripGuestFields($roomRequest['guests'] ?? null) ?? []) !== json_encode($bookingRoom->guests ?? []),
                                'bed_preference', 'billing_address', 'billing_comment' => ($roomRequest[$field] ?? null) !== ($bookingRoom->$field ?? null),
                                default => true,
                            };

                            if ($isDirty) {
                                $dirtyFields[$field] = $field === 'guests'
                                    ? $this->stripGuestFields($roomRequest['guests'] ?? null)
                                    : $roomRequest[$field];
                            }
                        }
                    }

                    $typeId = $roomRequest['room_type_id'] ?? $bookingRoom->room_type_id;
                    $checkInStr = isset($roomRequest['check_in'])
                        ? Carbon::parse($roomRequest['check_in'])->toDateString()
                        : $bookingRoom->check_in->toDateString();
                    $checkOutStr = isset($roomRequest['check_out'])
                        ? Carbon::parse($roomRequest['check_out'])->toDateString()
                        : $bookingRoom->check_out->toDateString();

                    $shapeChanged = isset($dirtyFields['room_type_id'])
                        || isset($dirtyFields['check_in'])
                        || isset($dirtyFields['check_out']);

                    $updates[$brId] = [
                        'model' => $bookingRoom,
                        'request' => $roomRequest,
                        'dirty_fields' => $dirtyFields,
                        // ค่า final = ค่าใหม่ถ้าส่งมา ไม่งั้นค่าเดิม (ใช้ทั้ง availability + pricing)
                        'type_id' => $typeId,
                        'check_in' => $checkInStr,
                        'check_out' => $checkOutStr,
                        'shape_changed' => $shapeChanged,
                    ];

                    // 🛡️ Guard effective dates จากค่า final — ครอบเคส partial update
                    //    (validation after:* เทียบฟิลด์ที่ไม่ได้ส่งมาไม่ได้ เช่น ส่งแต่ check_in
                    //     ทับ check_out เดิม หรือส่งแต่ check_out ก่อน check_in เดิม)
                    if (Carbon::parse($updates[$brId]['check_out'])->lte(Carbon::parse($updates[$brId]['check_in']))) {
                        throw new \Exception('วันที่เช็คเอาท์ต้องอยู่หลังวันที่เช็คอินของห้องนั้นค่ะ', 422);
                    }
                }

                // 🌟 Availability re-check จาก final state ของทั้ง batch
                //    - ตรวจเฉพาะห้องที่ shape เปลี่ยนจริง (ห้อง unchanged ผ่านมาแล้วโดย invariant)
                //    - existing count ตัด "ทุกห้องใน batch" ออก (มีอยู่แล้วเป็น draft rows)
                //    - batch overlap นับจากค่า final ของทุกห้องใน batch รวมห้องที่ไม่ได้เปลี่ยน shape
                //      (ไม่งั้นห้อง unchanged ที่ยังยึดพื้นที่อยู่จะหายไปจากการนับ → overbook)
                $batchIds = array_keys($updates);
                $totalByType = [];

                foreach ($updates as $u) {
                    if (! $u['shape_changed']) {
                        continue;
                    }

                    if (! isset($totalByType[$u['type_id']])) {
                        $totalByType[$u['type_id']] = Room::where('room_type_id', $u['type_id'])->count();
                    }

                    $checkIn = Carbon::parse($u['check_in']);
                    $checkOut = Carbon::parse($u['check_out']);

                    $existingBooked = BookingRoom::where('room_type_id', $u['type_id'])
                        ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
                        ->where('check_in', '<', $checkOut)
                        ->where('check_out', '>', $checkIn)
                        ->whereNotIn('id', $batchIds)
                        ->count();

                    $batchOverlapping = 0;
                    foreach ($updates as $other) {
                        if ($other['type_id'] !== $u['type_id']) {
                            continue;
                        }
                        $otherIn = Carbon::parse($other['check_in']);
                        $otherOut = Carbon::parse($other['check_out']);
                        if ($otherIn < $checkOut && $otherOut > $checkIn) {
                            $batchOverlapping++;
                        }
                    }

                    if (($existingBooked + $batchOverlapping) > $totalByType[$u['type_id']]) {
                        throw new \Exception('ขออภัยค่ะนายท่าน ห้องพักประเภทที่เลือกเต็มแล้วในช่วงเวลาดังกล่าวค่ะ', 422);
                    }
                }

                // 🌟 Apply + reprice รายห้อง — extra_beds/addons: ใช้ค่าใหม่ถ้าส่งมา ไม่งั้นค่าเดิมจาก Addon row
                $rates = GlobalRate::getPrices(['breakfast', 'early_checkin', 'late_checkout', 'extra_bed']);

                foreach ($updates as $u) {
                    $bookingRoom = $u['model'];
                    $roomRequest = $u['request'];

                    if (! empty($u['dirty_fields'])) {
                        $bookingRoom->update($u['dirty_fields']);
                    }

                    $existingAddon = $bookingRoom->addon;
                    $addonInput = $roomRequest['addons'] ?? null;

                    $extraBedQty = $this->resolveExtraBed($roomRequest, $addonInput, $existingAddon);
                    $breakfastQty = is_array($addonInput)
                        ? ($addonInput['breakfast'] ?? ($existingAddon?->breakfast ?? 0))
                        : ($existingAddon?->breakfast ?? 0);
                    [$earlyHours, $lateHours] = $this->resolveEarlyLate($addonInput, $existingAddon);

                    $roomType = RoomType::findOrFail($u['type_id']);
                    $nights = Carbon::parse($u['check_in'])->diffInDays(Carbon::parse($u['check_out'])) ?: 1;

                    $extraBedTotal = ($extraBedQty * ($rates['extra_bed'] ?? 0)) * $nights;
                    $breakfastPrice = $breakfastQty * ($rates['breakfast'] ?? 0);
                    $earlyCheckInPrice = $earlyHours * ($rates['early_checkin'] ?? 0);
                    $lateCheckOutPrice = $lateHours * ($rates['late_checkout'] ?? 0);

                    $addonData = [
                        'extra_bed' => $extraBedQty,
                        'extra_bed_price' => $extraBedTotal,
                        'breakfast' => $breakfastQty,
                        'breakfast_price' => $breakfastPrice,
                        'early_checkIn_price' => $earlyCheckInPrice,
                        'early_hours' => $earlyHours,
                        'late_checkOut_price' => $lateCheckOutPrice,
                        'late_hours' => $lateHours,
                    ];
                    if ($existingAddon) {
                        $dirtyAddonFields = [];
                        foreach ($addonData as $k => $v) {
                            if ((int) $existingAddon->$k !== (int) $v) {
                                $dirtyAddonFields[$k] = $v;
                            }
                        }
                        if (! empty($dirtyAddonFields)) {
                            $existingAddon->update($dirtyAddonFields);
                        }
                    } else {
                        Addon::create(array_merge(['booking_room_id' => $bookingRoom->id], $addonData));
                    }
                }

                // 🌟 Reconcile ส่วนลด & total_amount (27/08/26)
                $this->reconcileDiscount($locked);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            // 🌟 Response — เรียงตามลำดับ rooms ใน request
            $updatedRooms = [];
            foreach ($validated['booking_rooms'] as $roomRequest) {
                $updatedRooms[] = $rooms[$roomRequest['booking_room_id']]->fresh('addon');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'แก้ไขห้องเรียบร้อยแล้วค่ะ',
                'booking_id' => $booking->id,
                'booking_rooms' => $updatedRooms,
                'total_amount' => $locked->fresh()->total_amount,
            ], 200);

        } catch (\Exception $e) {
            // 🛡️ #40 pattern: business errors (401/422) ส่ง message ได้, unexpected ซ่อน
            $code = $e->getCode();
            if (in_array($code, [401, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่พบรายการจองหรือห้องที่ระบุค่ะ',
                ], 404);
            }

            Log::error('Failed to batch update booking rooms: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการแก้ไขห้อง กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    /**
     * 🌟 (17/08/26): ลบ booking room รายห้องออกจาก draft booking (เจ้าของหรือ admin)
     *
     * Constraints:
     * - booking.status = 'draft' และ booking_room.status = 'draft' เท่านั้น
     * - ห้องสุดท้ายของ booking ลบไม่ได้ (422) — ให้ลบทั้ง booking ด้วย DELETE /bookings/{id} แทน
     *   (กันเกิด draft เปล่าที่ไปล็อกโควตา "มี draft ค้าง" ของผู้ใช้)
     * - BR draft ยังไม่มี room_id (จ่ายห้องตอน paid/confirmed) — ไม่มี room ต้อง release
     */
    public function destroyRoom(Request $request, $bookingId, $bookingRoomId)
    {
        try {
            // 🛑 Auth check
            $user = $request->user('sanctum');
            if (! $user) {
                throw new \Exception('กรุณาล็อกอินก่อนลบห้องค่ะนายท่าน! 🔒', 401);
            }

            $booking = Booking::findOrFail($bookingId);

            // 🔐 Ownership check — เจ้าของ booking หรือ admin เท่านั้น
            if ($booking->user_id !== $user->id && $user->role !== 'admin') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'คุณไม่มีสิทธิ์แก้ไขการจองนี้ค่ะ',
                ], 403);
            }

            // 🔒 Draft guard — booking ต้องเป็น draft
            if ($booking->status !== 'draft') {
                throw new \Exception('ไม่สามารถลบห้องได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะ', 422);
            }

            // 🔒 BR ต้องอยู่ใต้ booking นี้จริง (ของ booking อื่น = 404)
            $bookingRoom = $booking->bookingRooms()->where('id', $bookingRoomId)->firstOrFail();
            if ($bookingRoom->status !== 'draft') {
                throw new \Exception('ไม่สามารถลบห้องได้ เนื่องจากห้องไม่ได้อยู่ในสถานะ draft ค่ะ', 422);
            }

            DB::beginTransaction();
            try {
                // 🌟 lock + re-check กัน race กับ confirm/verify ระหว่างลบ
                $locked = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'draft') {
                    throw new \Exception('ไม่สามารถลบห้องได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะ', 422);
                }

                // 🔒 ห้องสุดท้าย → ห้ามลบ ให้ลบทั้ง booking แทน
                if ($locked->bookingRooms()->count() <= 1) {
                    throw new \Exception('ไม่สามารถลบห้องสุดท้ายของการจองได้ค่ะ — ให้ลบทั้งรายการจอง (DELETE /bookings/{bookingId}) แทนนะคะ', 422);
                }

                $bookingRoom->addon()?->delete();
                $bookingRoom->delete();

                // 📝 Audit log — เก็บไว้แม้ BR row จะหายไปแล้ว (append-only)
                StatusChangeLog::create([
                    'entity_type' => 'booking_room',
                    'entity_id' => $bookingRoom->id,
                    'from_status' => 'draft',
                    'to_status' => 'deleted',
                    'role' => $user->role ?? 'user',
                    'causer_id' => $user->id,
                    'note' => 'ผู้ใช้ลบห้องออกจาก draft booking (hard delete)',
                ]);

                // 🌟 Reprice total_amount ของ booking ใหม่ (27/08/26)
                app(DiscountService::class)->reprice($locked);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'status' => 'success',
                'message' => 'ลบห้องออกจากการจองเรียบร้อยแล้วค่ะ',
                'booking_id' => $booking->id,
                'remaining_rooms' => $locked->bookingRooms()->count(),
                'total_amount' => $locked->fresh()->total_amount,
            ], 200);

        } catch (\Exception $e) {
            // 🛡️ #40 pattern: business errors (401/422) ส่ง message ได้, unexpected ซ่อน
            $code = $e->getCode();
            if (in_array($code, [401, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            if ($e instanceof ModelNotFoundException) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่พบรายการจองหรือห้องที่ระบุค่ะ',
                ], 404);
            }

            Log::error('Failed to delete booking room: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการลบห้อง กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }

    public function createBooking(StoreBookingRequest $request)
    {
        $inTransaction = false;

        try {
            $validated = $request->validated();

            // 🛑 1. ดักจับสายดอง: ถ้านายท่านมีบิล Draft ที่ยังไม่หมดเวลา ห้ามสร้างใหม่เด็ดขาด!
            // 🌟 Refactor (18/06/26): Guest/Non-member ใช้งานไม่ได้แล้ว — เช็คแค่ user ที่ล็อกอิน
            $userId = $request->user('sanctum')?->id;
            if (! $userId) {
                throw new \Exception('กรุณาล็อกอินก่อนทำการจองค่ะนายท่าน! 🔒', 401);
            }

            $hasDraft = Booking::where('user_id', $userId)
                ->where('status', 'draft')
                ->where('payment_deadline', '>', Carbon::now())
                ->exists();

            if ($hasDraft) {
                throw new \Exception('มีรายการจองที่รอชำระเงินอยู่คะ กรุณาทำรายการเดิมให้เสร็จสิ้นก่อนนะคะ', 422);
            }

            DB::beginTransaction();
            $inTransaction = true;

            // 🌟 Refactor (02/07/26): ย้าย check_in/check_out ไป BR-level — แต่ละห้องมีวันที่ของตัวเอง
            // ต้องเช็ค availability แบบ per-room-request (group by room_type + ดู overlap ของแต่ละช่วงวัน)
            $requestedByType = [];

            foreach ($validated['booking_rooms'] as $roomRequest) {
                $rtId = $roomRequest['room_type_id'];
                $requestedByType[$rtId][] = [
                    'check_in' => $roomRequest['check_in'],
                    'check_out' => $roomRequest['check_out'],
                ];
            }

            foreach ($requestedByType as $rtId => $requests) {
                $totalRooms = Room::where('room_type_id', $rtId)->count();

                // ตรวจทีละช่วงวันของห้องที่ขอจอง — นับทั้ง existing bookings และ batch requests ที่ overlap
                // (เพื่อกันกรณีห้อง 2 ห้องใน booking เดียวกันจองช่วงเวลาที่ทับซ้อนกัน)
                foreach ($requests as $checkReq) {
                    $checkIn = Carbon::parse($checkReq['check_in']);
                    $checkOut = Carbon::parse($checkReq['check_out']);

                    // 🌟 Refactor (25/06/26): availability นับที่ BR-level (มี check_in/check_out ของตัวเอง)
                    // นับตั้งแต่ draft ขึ้นไป (availability counting = C — block ห้องเมื่อมีคนจองตั้งแต่ตอนนั้น)
                    $existingBooked = BookingRoom::where('room_type_id', $rtId)
                        ->whereIn('status', ['draft', 'confirmed', 'checked_in'])
                        ->where('check_in', '<', $checkOut)
                        ->where('check_out', '>', $checkIn)
                        ->count();

                    // นับห้องใน batch นี้ที่ overlap กับช่วงวันของห้องปัจจุบัน
                    $batchOverlapping = 0;
                    foreach ($requests as $otherReq) {
                        $otherIn = Carbon::parse($otherReq['check_in']);
                        $otherOut = Carbon::parse($otherReq['check_out']);
                        if ($otherIn < $checkOut && $otherOut > $checkIn) {
                            $batchOverlapping++;
                        }
                    }

                    if (($existingBooked + $batchOverlapping) > $totalRooms) {
                        throw new \Exception('ขออภัยค่ะนายท่าน ห้องพักประเภทที่เลือกเต็มแล้วในช่วงเวลาดังกล่าวค่ะ', 422);
                    }
                }
            }

            $confirmationNo = Booking::generateUniqueConfirmation();

            $booking = Booking::create([
                'confirmation' => $confirmationNo,
                'user_id' => $userId,
                'source' => $validated['source'],
                'status' => 'draft',

                // 🌟 Refactor (25/06/26): ย้าย check_in/check_out ไปที่ booking_rooms แล้ว
                // bookings เก็บแค่ container + payment info เท่านั้น

                'total_amount' => 0,
                'payment_deadline' => Carbon::now()->addHours(24),
            ]);

            // 🌟 Refactor (19/06/26): ดึง rate จาก global_rates (server-side) ทีเดียวจบ
            // ไม่รับ price จาก client อีกต่อไป — ป้องกัน price manipulation (#20)
            // 🌟 Refactor (22/07/26): ย้ายจาก addon_rates → global_rates (rate_type='addon')
            $rates = GlobalRate::getPrices(['breakfast', 'early_checkin', 'late_checkout', 'extra_bed']);

            $createdRooms = [];

            // 🌟 ปรับลูปให้สร้าง BookingRoom และ Addon ไปพร้อมๆ กันต่อห้องเลยค่ะ
            foreach ($validated['booking_rooms'] as $roomRequest) {
                $roomType = RoomType::findOrFail($roomRequest['room_type_id']);

                // 🌟 Refactor (02/07/26): คำนวณ nights รายห้อง (แต่ละห้องมีวันที่ต่างกันได้)
                $roomCheckIn = Carbon::parse($roomRequest['check_in']);
                $roomCheckOut = Carbon::parse($roomRequest['check_out']);
                $nights = $roomCheckIn->diffInDays($roomCheckOut) ?: 1;

                // 🌟 Refactor (22/07/26): อ่าน room rate จาก global_rates (คำนึงถึงสิทธิ์ ku_member)
                $roomPriceTotal = GlobalRate::getEffectiveDailyRate($roomType, $request->user('sanctum')) * $nights;
                $extraBedQty = $this->resolveExtraBed($roomRequest, $roomRequest['addons'] ?? null);
                $extraBedUnit = $rates['extra_bed'] ?? 0;
                $extraBedTotal = ($extraBedQty * $extraBedUnit) * $nights;

                // คำนวณราคา Addon ของห้องนี้ (rate จาก server เท่านั้น)
                $addons = $roomRequest['addons'] ?? [];
                $breakfastQty = $addons['breakfast'] ?? 0;
                $breakfastPrice = $breakfastQty * ($rates['breakfast'] ?? 0);
                [$earlyHours, $lateHours] = $this->resolveEarlyLate($addons);
                $earlyCheckInPrice = $earlyHours * ($rates['early_checkin'] ?? 0);
                $lateCheckOutPrice = $lateHours * ($rates['late_checkout'] ?? 0);

                $bookingRoom = BookingRoom::create([
                    'booking_id' => $booking->id,
                    'room_type_id' => $roomType->id,
                    'room_id' => null, // รอจ่ายห้องตอน Check-in
                    // 🌟 Refactor (02/07/26): วันที่เช็คอิน/เช็คเอาท์อยู่ที่ระดับห้อง (แต่ละห้องต่างวันได้)
                    'check_in' => $roomRequest['check_in'],
                    'check_out' => $roomRequest['check_out'],
                    'status' => 'draft', // BR-level state
                    'bed_preference' => $roomRequest['bed_preference'] ?? null,
                    // 🌟 Refactor (18/06/26): เก็บข้อมูลผู้เข้าพักหลายคนในห้องนี้
                    'guests' => isset($roomRequest['guests']) ? $this->stripGuestFields($roomRequest['guests']) : null,
                    // 🧾 Billing fields (04/08/26)
                    'billing_address' => $roomRequest['billing_address'] ?? null,
                    'billing_comment' => $roomRequest['billing_comment'] ?? null,
                ]);

                // 🌟 บันทึก Addon โดยผูกกับ booking_room_id แทนค่ะ
                Addon::create([
                    'booking_room_id' => $bookingRoom->id,
                    'extra_bed' => $extraBedQty,
                    'extra_bed_price' => $extraBedTotal,
                    'breakfast' => $breakfastQty,
                    'breakfast_price' => $breakfastPrice,
                    'early_checkIn_price' => $earlyCheckInPrice,
                    'early_hours' => $earlyHours,
                    'late_checkOut_price' => $lateCheckOutPrice,
                    'late_hours' => $lateHours,
                ]);

                $createdRooms[] = $bookingRoom;
            }

            // 🎟️ Reconcile ส่วนลด & total_amount (27/08/26)
            if (! empty($validated['discount_code'])) {
                app(DiscountService::class)->applyToDraft($booking, $validated['discount_code']);
            } else {
                app(DiscountService::class)->reprice($booking);
            }

            DB::commit();

            // 🌟 โหลด relations ของห้องที่สร้างขึ้นทั้งหมด เพื่อส่งกลับใน response
            $bookingRoomsResponse = array_map(function ($br) {
                return $br->fresh('addon');
            }, $createdRooms);

            return response()->json([
                'status' => 'success',
                'message' => 'Booking and Add-ons created successfully',
                'booking_id' => $booking->id,
                'confirmation' => $booking->confirmation,
                'total_amount' => $booking->fresh()->total_amount,
                'payment_deadline' => $booking->payment_deadline->toDateTimeString(),
                'user_id' => $userId,
                'booking_rooms' => $bookingRoomsResponse,
            ], 201);

        } catch (\Exception $e) {
            // 🛡️ rollBack เฉพาะเมื่อเรา beginTransaction เอง — early guards (401/draft-limit 422)
            //    โยนก่อน begin แล้ว rollBack เปล่าจะไปยกเลิก transaction ของผู้เรียก
            if ($inTransaction) {
                DB::rollBack();
            }

            // 🛡️ #40 Fixed: Business logic errors (422) ส่ง message ได้, unexpected errors ซ่อน
            $code = $e->getCode();
            if (in_array($code, [401, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            Log::error('Failed to create booking: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการสร้างการจอง กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
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
        // 🌟 Fix scrutinize (29/06/26): JSON_EXTRACT ใช้ได้แค่ MySQL/SQLite — ทำให้ cross-DB compatible
        $lowerEscaped = '%'.strtolower($escaped).'%';
        $guestNameFilter = function ($brQuery) use ($lowerEscaped) {
            $driver = DB::getDriverName();
            if (in_array($driver, ['mysql', 'sqlite'])) {
                $brQuery->where(function ($q) use ($lowerEscaped) {
                    $q->whereRaw('LOWER(JSON_EXTRACT(guests, "$[0].name")) LIKE ?', [$lowerEscaped])
                        ->orWhereRaw('LOWER(JSON_EXTRACT(guests, "$[0].firstName")) LIKE ?', [$lowerEscaped])
                        ->orWhereRaw('LOWER(JSON_EXTRACT(guests, "$[0].lastName")) LIKE ?', [$lowerEscaped])
                        ->orWhereRaw('LOWER(JSON_EXTRACT(guests, "$[0].first_name")) LIKE ?', [$lowerEscaped])
                        ->orWhereRaw('LOWER(JSON_EXTRACT(guests, "$[0].last_name")) LIKE ?', [$lowerEscaped])
                        ->orWhereRaw('LOWER(JSON_EXTRACT(guests, "$[0].email")) LIKE ?', [$lowerEscaped])
                        ->orWhereRaw('LOWER(JSON_EXTRACT(guests, "$[0].phone")) LIKE ?', [$lowerEscaped]);
                });
            } elseif ($driver === 'pgsql') {
                $brQuery->where(function ($q) use ($lowerEscaped) {
                    $q->whereRaw("LOWER(guests #>> '{0,name}') LIKE ?", [$lowerEscaped])
                        ->orWhereRaw("LOWER(guests #>> '{0,firstName}') LIKE ?", [$lowerEscaped])
                        ->orWhereRaw("LOWER(guests #>> '{0,lastName}') LIKE ?", [$lowerEscaped])
                        ->orWhereRaw("LOWER(guests #>> '{0,first_name}') LIKE ?", [$lowerEscaped])
                        ->orWhereRaw("LOWER(guests #>> '{0,last_name}') LIKE ?", [$lowerEscaped])
                        ->orWhereRaw("LOWER(guests #>> '{0,email}') LIKE ?", [$lowerEscaped])
                        ->orWhereRaw("LOWER(guests #>> '{0,phone}') LIKE ?", [$lowerEscaped]);
                });
            } else {
                // Fallback: search across whole JSON blob (less precise but safe)
                $brQuery->whereRaw('LOWER(CAST(guests AS TEXT)) LIKE ?', [$lowerEscaped]);
            }
        };

        return $query->where(function ($q) use ($escaped, $term, $guestNameFilter) {
            if (Str::isUuid($term)) {
                $q->where('user_id', $term)
                    ->orWhereHas('user', function ($userQuery) use ($escaped) {
                        $userQuery->where('name', 'LIKE', '%'.$escaped.'%');
                    })
                    ->orWhereHas('bookingRooms', $guestNameFilter);
            } else {
                $q->whereHas('user', function ($userQuery) use ($escaped) {
                    $userQuery->where('name', 'LIKE', '%'.$escaped.'%');
                })
                    ->orWhereHas('bookingRooms', $guestNameFilter);
            }
        });
    }

    private function applyDateFilter($query, $checkIn, $checkOut)
    {
        $startDate = Carbon::parse($checkIn)->startOfDay();
        $endDate = Carbon::parse($checkOut)->endOfDay();

        // 🌟 Refactor (25/06/26): วันที่ย้ายไป BR-level แล้ว — filter ผ่าน whereHas('bookingRooms', ...)
        return $query->whereHas('bookingRooms', function ($br) use ($startDate, $endDate) {
            $br->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('check_in', [$startDate, $endDate])
                    ->orWhereBetween('check_out', [$startDate, $endDate])
                    ->orWhere(function ($subQ) use ($startDate, $endDate) {
                        $subQ->where('check_in', '<=', $startDate)
                            ->where('check_out', '>=', $endDate);
                    });
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
            // 🌟 Refactor (25/06/26): booking container states เท่านั้น
            // (checked_in/checked_out/no_show อยู่ที่ BookingRoom)
            'status' => 'required|string|in:draft,paid,confirmed,complete',
        ]);

        try {
            $booking = Booking::findOrFail($id);
            $newStatus = $request->status;

            $user = $request->user('sanctum');
            $userRole = $user ? $user->role : 'guest';

            $booking->transitionStatus($newStatus, $userRole);

            return response()->json([
                'status' => 'success',
                'message' => "อัปเดตสถานะเป็น {$newStatus} โดยคุณ {$userRole} เรียบร้อยแล้วค่ะ",
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
            ], 200);

        } catch (ModelNotFoundException $e) {
            $modelName = class_basename($e->getModel());

            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ",
            ], 404);

        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            Log::error('Failed to update booking status: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }

    /**
     * 📝 Audit log (04/08/26): ประวัติการเปลี่ยนสถานะของ booking
     *
     * ดึง log ของ booking container + ทุก booking_room ที่อยู่ใต้ booking นี้
     * เรียงตามเวลา (เก่า → ใหม่) เพื่อให้เห็นลำดับเหตุการณ์เต็มๆ
     *
     * Route: GET /api/v1/bookings/{id}/status-logs (admin only)
     */
    public function statusLogs(string $id)
    {
        try {
            // โหลด booking + booking_rooms (เพื่อเอา BR ids สำหรับ query log)
            $booking = Booking::with('bookingRooms')->findOrFail($id);
            $brIds = $booking->bookingRooms->pluck('id');

            // เก็บ log ของ booking container + ทุก booking_room ที่อยู่ใต้ booking นี้
            $logs = StatusChangeLog::where(function ($q) use ($id, $brIds) {
                $q->where(function ($qq) use ($id) {
                    $qq->where('entity_type', 'booking')
                        ->where('entity_id', $id);
                })->orWhere(function ($qq) use ($brIds) {
                    $qq->where('entity_type', 'booking_room')
                        ->whereIn('entity_id', $brIds);
                });
            })
                ->orderBy('created_at', 'asc')
                ->get([
                    'id', 'entity_type', 'entity_id',
                    'from_status', 'to_status', 'role', 'causer_id',
                    'note', 'created_at',
                ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Status change logs retrieved',
                'booking_id' => $booking->id,
                'logs' => $logs,
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูล Booking ที่ระบุในระบบค่ะนายท่าน',
            ], 404);
        }
    }

    public function showById(Request $request, string $id)
    {
        try {
            $user = $request->user('sanctum');

            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'กรุณาเข้าสู่ระบบก่อนนะคะนายท่าน!',
                ], 401);
            }

            $booking = Booking::with(['user', 'bookingRooms.addon'])
                ->where('id', $id)
                ->firstOrFail();

            if ($user->role !== 'admin' && $booking->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'นายท่านไม่มีสิทธิ์ดูข้อมูลการจองของผู้อื่นนะคะ! 🔒',
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
            Log::error('Failed to show booking: '.$e->getMessage());

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
            if (! $user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'นายท่านยังไม่ได้ล็อกอินนะคะ! กรุณาแนบ Token ก่อนทำรายการค่ะ 🔒',
                    'user' => $user,
                ], 401);
            }

            // ถ้าไม่ใช่ Admin และ ID ไม่ตรงกับคนจอง
            if ($user->role !== 'admin' && $booking->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่อนุญาตค่ะนายท่าน! สิทธิ์นี้เฉพาะแอดมินหรือเจ้าของบุ๊กกิ้งเท่านั้นนะคะ 🙅‍♀️',
                ], 403);
            }

            // =========================================================
            // 🛡️ ด่านตรวจที่ 2: เช็คสถานะ Booking (ต้อง paid หรือ confirmed เท่านั้น)
            // =========================================================
            if (! in_array($booking->status, ['paid', 'confirmed'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "ยังระบุเลขห้องไม่ได้ค่ะนายท่าน! สถานะปัจจุบันคือ '{$booking->status}' (ต้องจ่ายเงิน 'paid' หรือยืนยัน 'confirmed' ก่อนนะคะ) 💳",
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
                        'message' => 'เอ๊ะ! บุ๊กกิ้งนี้ยังไม่มีการจองห้องพักเข้ามาเลยนะคะนายท่าน! 💦',
                    ], 422);
                }

                return response()->json([
                    'status' => 'info',
                    'message' => 'ห้องพักทั้งหมดในบุ๊กกิ้งนี้ถูกระบุเลขห้องเรียบร้อยแล้วค่ะนายท่าน! ✨',
                ]);
            }

            // 🌟 Refactor (25/06/26): BR-level dates แล้ว — assignAvailableRoom() ใช้ $this->check_in/check_out เอง
            // 🏨 Phase 5: เปลี่ยนจาก first-available greedy แบบเดิม → RoomAllocator (Hybrid+ v3 cluster algorithm)
            //    เหตุผล: algorithm ใหม่จัดห้องเป็น cluster (ระยะเดินใกล้กันที่สุด) แทนที่จะ assign ทีละ BR แยกกัน
            $assignedCount = 0;

            $result = app(RoomAllocator::class)->allocate($unassignedRooms);

            if (! $result->ok) {
                $failedTypes = $unassignedRooms->map(fn ($br) => $br->room_type_id)->unique()->implode(', ');
                $winner = $result->winner ?? 'none';
                throw new \Exception("แย่แล้วค่ะนายท่าน! ไม่สามารถจัดห้องเป็น cluster ได้ในช่วงเวลาดังกล่าวค่ะ 😭 (room_type_id: {$failedTypes}) — algorithm: {$winner}");
            }

            foreach ($result->assignments as $brId => $roomId) {
                BookingRoom::where('id', $brId)->update(['room_id' => $roomId]);
                $assignedCount++;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "หนูจัดการระบุเลขห้องอัตโนมัติให้จำนวน {$assignedCount} ห้องเรียบร้อยแล้วค่ะนายท่าน! 🎉",
                // 🌟 (26/08/26): load addon ให้ format booking_rooms เหมือนกับ endpoint อื่นๆ
                'booking' => $booking->load('bookingRooms.addon'),
                // 🏨 debug info: algorithm ที่ชนะ + cluster cost
                'allocation' => [
                    'winner' => $result->winner,
                    'cost' => round($result->cost, 2),
                    'algo' => $result->algo,
                ],
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลการจองนี้ในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to auto-assign room: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'หนูขอโทษค่ะ เกิดข้อผิดพลาด: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * 👥 ตัดฟิลด์ที่ไม่จัดเก็บออกจาก guests array (เช่น is_ku_member)
     */
    private function stripGuestFields(?array $guests): ?array
    {
        if ($guests === null) {
            return null;
        }

        return array_map(function ($guest) {
            if (is_array($guest)) {
                unset($guest['is_ku_member']);
            }

            return $guest;
        }, $guests);
    }

    /**
     * 🕐 (27/08/26): สูตรรายชั่วโมง — addons.early_checkin / addons.late_checkout รับ int จำนวนชั่วโมง (0-7)
     * 🔧 (01/09/26): รับ alias early_hours / late_hours (frontend echo ชื่อ column กลับมา — bug "แก้ชั่วโมงแล้วไม่อัปเดต")
     *                ลำดับ resolve ของแต่ละ key: canonical → alias → คงค่าเดิมจากแถว addon
     * ไม่ส่ง addons key มาเลย = ใช้ค่าจากแถว addon เดิม (fallback ตามพฤติกรรมเดิมของ updateRoom/updateRooms)
     */
    private function resolveEarlyLate(?array $addonInput, ?Addon $existing = null): array
    {
        if (is_array($addonInput)) {
            $earlyHours = (int) ($addonInput['early_checkin'] ?? $addonInput['early_hours']
                ?? ($existing?->early_hours ?? 0));
            $lateHours = (int) ($addonInput['late_checkout'] ?? $addonInput['late_hours']
                ?? ($existing?->late_hours ?? 0));

            return [$earlyHours, $lateHours];
        }

        $earlyHours = ! empty($existing?->early_checkIn_price) ? ($existing?->early_hours ?? 0) : 0;
        $lateHours = ! empty($existing?->late_checkOut_price) ? ($existing?->late_hours ?? 0) : 0;

        return [$earlyHours, $lateHours];
    }

    /**
     * 🛏️ (04/09/26): input format = output format — เตียงเสริมอยู่ใน addons object เหมือน addon อื่น ๆ
     *    canonical: addons.extra_bed (ตรงกับ addon.extra_bed ตอน response)
     *    alias: extra_beds (หัวห้อง — โครงสร้างเก่า เก็บไว้ให้ frontend เดิม)
     *    ลำดับ resolve ตาม resolveEarlyLate(): canonical → alias → คงค่าเดิมจากแถว addon
     */
    private function resolveExtraBed(array $roomRequest, ?array $addonInput, ?Addon $existing = null): int
    {
        return (int) ($addonInput['extra_bed']
            ?? $roomRequest['extra_beds']
            ?? ($existing?->extra_bed ?? 0));
    }

    /**
     * 🎟️ Reconcile ส่วนลด & total_amount หลังแก้ไขห้องใน draft booking (28/08/26 F2)
     * ถ้าการแก้ไขทำให้ห้องทั้งหมดไม่เข้าเกณฑ์โค้ดส่วนลด จะแจ้งเตือนให้ user ลบโค้ดก่อน
     */
    private function reconcileDiscount(Booking $booking): void
    {
        if (! $booking->discount_code) {
            app(DiscountService::class)->reprice($booking);

            return;
        }

        try {
            app(DiscountService::class)->applyToDraft($booking, $booking->discount_code);
        } catch (\Exception $e) {
            if ($e->getCode() === 422) {
                throw new \Exception(
                    'การแก้ไขทำให้การจองไม่เข้าเกณฑ์โค้ด '.$booking->discount_code.' อีกต่อไป — '.
                    'กรุณาลบโค้ดส่วนลดก่อน (DELETE /bookings/'.$booking->id.'/discount-code) แล้วลองแก้ไขอีกครั้งค่ะ',
                    422
                );
            }

            throw $e;
        }
    }
}
