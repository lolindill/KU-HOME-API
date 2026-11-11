<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\HousekeepingTask;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\User;
use App\Services\Discount\DiscountService;
use App\Support\IncludeReservedGate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FrontDeskController extends Controller
{
    // 🌟 1. Walk-in Booking (Admin only)
    // 🌟 Refactor (25/06/26): BR-level state machine — BR เริ่มที่ checked_in ตรงๆ (skip draft→confirmed)
    public function walkIn(Request $request)
    {
        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
            'nights' => 'required|integer|min:1',
            'room_id' => 'required|uuid|exists:rooms,id',

            // 👥 ข้อมูลผู้เข้าพัก (เก็บใน booking_rooms.guests แทน รองรับหลายคนต่อห้อง)
            'guests' => 'nullable|array',
            'guests.*.title' => 'nullable|string|max:50',
            'guests.*.firstName' => 'nullable|string|max:255',
            'guests.*.lastName' => 'nullable|string|max:255',
            'guests.*.first_name' => 'nullable|string|max:255',
            'guests.*.last_name' => 'nullable|string|max:255',
            'guests.*.name' => 'nullable|string|max:255',
            'guests.*.email' => 'nullable|string|email|max:255',
            'guests.*.phone' => 'nullable|string|max:50',
            'guests.*.nationality' => 'nullable|string|max:100',
            // 🧾 Billing fields (04/08/26)
            'billing_address' => 'nullable|string|max:255',
            'billing_comment' => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $room = Room::with('roomType')->lockForUpdate()->findOrFail($validated['room_id']);
            if (! in_array($room->status, ['available', 'prep_checkin'])) {
                throw new \Exception("Room number {$room->room_number} is not ready for walk-in. Current status: {$room->status}");
            }

            $staffUser = User::findOrFail($validated['verified_by']);

            $checkIn = Carbon::now();
            $checkOut = Carbon::now()->addDays($validated['nights']);

            // 🗓️ (24/09/26) room-state-periods: period-check แทนเงื่อนไข is_reserved เดิม —
            //    maintenance period ตัดเด็ดขาดทุกกรณี · reserved period ผ่านเฉพาะ admin ส่ง
            //    include_reserved (route admin-only อยู่แล้ว — gate re-check ให้เอง)
            if (RoomStatePeriod::where('room_id', $room->id)
                ->overlapping($checkIn, $checkOut, RoomStatePeriod::KIND_MAINTENANCE)
                ->exists()) {
                throw new \Exception("Room number {$room->room_number} is under a maintenance period for those dates and cannot be used for walk-in.");
            }
            $hasReservedPeriod = RoomStatePeriod::where('room_id', $room->id)
                ->overlapping($checkIn, $checkOut, RoomStatePeriod::KIND_RESERVED)
                ->exists();
            if ($hasReservedPeriod && ! IncludeReservedGate::enabled($request)) {
                throw new \Exception("Room number {$room->room_number} is a reserved (สำรอง) room for those dates — admin ต้องส่ง include_reserved เพื่อใช้ห้องสำรองค่ะนายท่าน");
            }

            // ⏱️ overlap guard — ห้าม walk-in ทับห้องที่มี BR อื่น (holdingSlot) ยึดทับช่วงพัก
            $overlap = $room->bookingRooms()
                ->holdingSlot()
                ->where('check_in', '<', $checkOut)
                ->where('check_out', '>', $checkIn)
                ->exists();
            if ($overlap) {
                throw new \Exception("Room number {$room->room_number} has overlapping booking.", 422);
            }

            $confirmationNo = Booking::generateUniqueConfirmation();

            $booking = Booking::create([
                'user_id' => $staffUser->id,
                'confirmation' => $confirmationNo,
                'source' => 'admin',
                'status' => 'draft', // immediately transitioned to confirmed below
                // 🧾 (03/09/26) total_amount ให้ reprice() เขียนแทน — single source of truth
                'total_amount' => 0,
                // 🌟 Fix (03/07/26): walk-in จ่ายเงินสดเสร็จแล้ว ไม่มี payment deadline
                // (ตั้งเป็น null แทน now() ที่ทำให้ดูเหมือน "หมดอายุทันที")
                'payment_deadline' => null,
            ]);

            $bookingRoom = BookingRoom::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'room_type_id' => $room->room_type_id,
                'room_id' => $room->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'guests' => isset($validated['guests']) ? $this->stripGuestFields($validated['guests']) : null,
                // 🧾 Billing fields (04/08/26)
                'billing_address' => $validated['billing_address'] ?? null,
                'billing_comment' => $validated['billing_comment'] ?? null,
                'status' => 'draft',
            ]);

            // 🧾 (03/09/26) เขียน room_amount/amount/total_amount ผ่าน chokepoint เดียวกับ flow ออนไลน์
            //    (booking ยัง draft — reprice ไม่มี guard สถานะ; walk-in ไม่มีโค้ดส่วนลด → amount = rate × nights)
            app(DiscountService::class)->reprice($booking);

            // 🌟 Refactor (25/06/26): BR สองสเต็ปเพราะ state machine บังคับ draft→confirmed→checked_in
            $bookingRoom->transitionStatus('confirmed', 'admin');
            $bookingRoom->transitionStatus('checked_in', 'admin');

            // 🌟 ใช้ state machine เปลี่ยนสถานะห้องเป็น occupied
            $room->transitionStatusTo('occupied', $validated['verified_by']);

            // 🌟 Booking container → confirmed (walk-in skip draft→paid)
            $booking->transitionStatus('confirmed', 'admin');

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Walk-in booking and Check-in completed!',
                'booking_id' => $booking->id,
                'room_number' => $room->room_number,
            ], 201);

        } catch (\Exception $e) {
            return $this->handleCatch('Walk-in', $e);
        }
    }

    // 🛎️ 2. Check-In (per-booking-room) — (2026-11-06) แทนที่ booking-level เดิมทั้งหมด:
    //    เช็คอิน "รายห้อง" — บิล multi-room แขกมาถึงไม่พร้อมกันเช็คอินย่อยได้
    //    · ห้องแรกที่เข้า promote บิล paid → confirmed · บิล complete ปิดรับ check-in
    //    · body {room_id?} = มอบหมาย/เปลี่ยนห้องตอนเช็คอิน · ไม่ส่ง = ใช้ห้องที่ผูกไว้ (BR.room_id)
    public function checkInRoom(Request $request, $bookingId, $bookingRoomId)
    {
        // 🌟 Defense-in-depth: ตรวจ role ใน controller ด้วย ไม่พึ่ง middleware อย่างเดียว
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินหรือเจ้าหน้าที่เท่านั้นถึงจะ Check-in ได้ค่ะนายท่าน',
            ], 403);
        }

        $validated = $request->validate([
            'room_id' => 'nullable|uuid|exists:rooms,id',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            // 🌟 scope ผ่านบิลเสมอ — bookingRoomId ของบิลอื่น = 404 (pattern เดียวกับ changeRoom)
            $bookingRoom = $booking->bookingRooms()->where('id', $bookingRoomId)->firstOrFail();

            $userId = $user->id;
            $userRole = $user->role;

            // 🌟 Guard สถานะห้อง (BR): เช็คอินได้เฉพาะ draft/confirmed (draft จะถูก promote ต่อไป)
            if ($bookingRoom->status === 'checked_in') {
                throw new \Exception(
                    "ไม่สามารถเช็คอินได้ค่ะนายท่าน ห้องรายการจอง ID {$bookingRoom->id} ถูกเช็คอินไปแล้ว ไม่สามารถเช็คอินซ้ำได้นะคะ",
                    422
                );
            }
            if (! in_array($bookingRoom->status, ['draft', 'confirmed'])) {
                throw new \Exception(
                    "ไม่สามารถเช็คอินได้ค่ะนายท่าน ห้องนี้สถานะ '{$bookingRoom->status}' แล้ว (รับเฉพาะ draft หรือ confirmed เท่านั้นนะคะ)",
                    422
                );
            }

            // 🌟 Fix scrutinize (29/06/26): ลบ bypass state machine
            // Booking container ต้องผ่าน flow ปกติ: draft → (record payment) → paid → (check-in) → confirmed
            // ห้ามข้ามขั้นตอนการชำระเงิน — staff ต้อง record payment ก่อนทุกครั้ง
            // 🌟 (2026-11-06) per-room: ห้องแรกที่เข้าเป็นตัว promote บิล paid → confirmed
            if ($booking->status === 'draft') {
                throw new \Exception(
                    'ไม่สามารถเช็คอินได้ค่ะนายท่าน เนื่องจากรายการจองยังไม่ได้รับชำระเงิน '.
                    'กรุณาบันทึกการรับชำระเงินก่อน (draft → paid) แล้วจึงกลับมาเช็คอินนะคะ'
                );
            } elseif (in_array($booking->status, ['pending', 'verify_error'])) {
                throw new \Exception(
                    "ไม่สามารถเช็คอินได้ค่ะนายท่าน สถานะการจองปัจจุบันคือ '{$booking->status}' (ต้องเป็น paid หรือ confirmed เท่านั้นนะคะ)",
                    422
                );
            } elseif ($booking->status === 'complete') {
                throw new \Exception(
                    'ไม่สามารถเช็คอินได้ค่ะนายท่าน รายการจองนี้ปิดบิล (complete) เรียบร้อยแล้วนะคะ',
                    422
                );
            } elseif ($booking->status === 'paid') {
                $booking->transitionStatus('confirmed', $userRole);
            }

            // 🌟 จับคู่ห้องพัก: ส่ง room_id มา = มอบหมายห้องตอนเช็คอิน (เปลี่ยนจากที่ผูกไว้ได้)
            if (! empty($validated['room_id']) && $validated['room_id'] !== $bookingRoom->room_id) {
                $assignedRoom = Room::lockForUpdate()->findOrFail($validated['room_id']);

                // ✅ #32 Fixed: ตรวจว่าห้องที่ assign ตรงกับ room type ที่จองไว้
                if ($assignedRoom->room_type_id !== $bookingRoom->room_type_id) {
                    throw new \Exception(
                        "ห้องหมายเลข {$assignedRoom->room_number} (ประเภท: {$assignedRoom->roomType->name_en}) ".
                        'ไม่ตรงกับประเภทห้องที่จองไว้ค่ะนายท่าน กรุณาตรวจสอบอีกครั้งนะคะ'
                    );
                }

                // ⏱️ (2026-10-27) overlap guard — ห้าม assign ห้องที่มี BR อื่น (holdingSlot) ยึดทับช่วงพัก
                //    ปิดช่องจองซ้ำตอนเช็คอินด้วยมือ (auto-assign มี allocator กันให้อยู่แล้ว)
                $overlap = $assignedRoom->bookingRooms()
                    ->where('booking_rooms.id', '!=', $bookingRoom->id)
                    ->holdingSlot()
                    ->where('check_in', '<', $bookingRoom->check_out)
                    ->where('check_out', '>', $bookingRoom->check_in)
                    ->exists();
                if ($overlap) {
                    throw new \Exception(
                        "ห้องหมายเลข {$assignedRoom->room_number} มีการจองอื่นทับช่วงเข้าพัก ".
                        "({$bookingRoom->check_in->toDateString()} – {$bookingRoom->check_out->toDateString()}) ".
                        'ไม่สามารถเช็คอินได้ค่ะนายท่าน กรุณาเลือกห้องอื่นด้วย room_id นะคะ',
                        422
                    );
                }

                $bookingRoom->update(['room_id' => $validated['room_id']]);
            }

            if (! $bookingRoom->room_id) {
                throw new \Exception("ไม่สามารถเช็คอินได้ค่ะ รายการจอง ID {$bookingRoom->id} ยังไม่ได้ระบุหมายเลขห้องพักค่ะนายท่าน");
            }

            $room = Room::lockForUpdate()->findOrFail($bookingRoom->room_id);

            // 🗓️ (24/09/26) room-state-periods: ห้องปลายทางมี maintenance period ทับช่วงพัก
            //    ของ BR → reject (reserved period **ไม่บล็อก** — booking include_reserved ของ
            //    admin ถูกต้องแล้ว · ฟรอนต์ย้ายแขกด้วย room_id ที่มีอยู่)
            //    (ปิดช่องเก่า "เช็คอินห้องสถานะ maintenance หลุดผ่าน" ด้วย — สถานะถูกถอดไปแล้ว)
            if (RoomStatePeriod::where('room_id', $room->id)
                ->overlapping($bookingRoom->check_in, $bookingRoom->check_out, RoomStatePeriod::KIND_MAINTENANCE)
                ->exists()) {
                throw new \Exception(
                    "ห้อง {$room->room_number} อยู่ในช่วงซ่อมแซม (maintenance period) ทับช่วงเข้าพัก ".
                    "({$bookingRoom->check_in->toDateString()} – {$bookingRoom->check_out->toDateString()}) ไม่สามารถเช็คอินได้ค่ะนายท่าน กรุณาย้ายห้องด้วย room_id นะคะ",
                    422
                );
            }

            // 🧹 (2026-11-09) ห้อง dirty ที่เช็คอินเข้า → เปิด task 'daily' ให้แม่บ้านก่อน
            //    transition ("call housekeeping task first" — มติ owner) · duplicate-guard
            //    · ครอบทั้งเคสส่ง room_id ใหม่และใช้ห้องที่ผูกไว้ (จุดเดียวหลังจับคู่ห้องแล้ว)
            if ($room->status === 'dirty') {
                $dirtyTaskExists = HousekeepingTask::where('room_id', $room->id)
                    ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])
                    ->exists();
                if (! $dirtyTaskExists) {
                    HousekeepingTask::create([
                        'id' => Str::uuid(),
                        'room_id' => $room->id,
                        'task_type' => 'daily',
                        'status' => 'unassigned',
                        'notes' => 'Auto: checked into dirty room ('.$room->room_number.') — cleaning required',
                    ]);
                }
            }

            // 🌟 ใช้ state machine เปลี่ยนสถานะห้อง
            $room->transitionStatusTo('occupied', $userId);

            // 🌟 Refactor (25/06/26): BR state machine → checked_in
            //    BR ต้องเป็น confirmed ก่อน — ถ้ายังเป็น draft ให้ confirmed ก่อน
            if ($bookingRoom->status === 'draft') {
                $bookingRoom->transitionStatus('confirmed', $userRole);
            }
            $bookingRoom->transitionStatus('checked_in', $userRole);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-in completed successfully! 🎉',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
                'booking_room_id' => $bookingRoom->id,
                'room_updates' => [
                    [
                        'room_number' => $room->room_number,
                        'new_status' => $room->status,
                    ],
                ],
            ], 200);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            $modelName = class_basename($e->getModel());

            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน",
            ], 404);

        } catch (\Exception $e) {
            return $this->handleCatch('Check-in', $e);
        }
    }

    // 🧹 3. Check-Out (per-booking-room) — (2026-11-06) แทนที่ booking-level เดิมทั้งหมด:
    //    เช็คเอาท์ "รายห้อง" — บิล multi-room แขกออกไม่พร้อมกันได้
    //    · มติ "ตั้งแต่ห้องแรก": ทุกครั้งตรวจยอดรวมทั้งบิล (deferred ยกเว้น) — กติกาการเงินเดิมเป๊ะ
    //    · ห้องสุดท้ายที่จบ → syncStatusFromRooms ปิดบิล complete เอง
    public function checkOutRoom(Request $request, $bookingId, $bookingRoomId)
    {
        // 🌟 Fix L4 (03/07/26): defense-in-depth — ตรวจ role ใน controller ด้วย ไม่พึ่ง middleware อย่างเดียว
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินหรือเจ้าหน้าที่เท่านั้นถึงจะ Check-out ได้ค่ะนายท่าน',
            ], 403);
        }

        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            // 🌟 scope ผ่านบิลเสมอ — bookingRoomId ของบิลอื่น = 404 (pattern เดียวกับ changeRoom)
            $bookingRoom = $booking->bookingRooms()->where('id', $bookingRoomId)->firstOrFail();

            // 🌟 Refactor (25/06/26): เช็คที่ BR-level — เช็คเอาท์ได้เฉพาะห้องที่ checked_in
            if ($bookingRoom->status !== 'checked_in') {
                throw new \Exception(
                    "ไม่สามารถเช็คเอาท์ได้ค่ะนายท่าน ห้องนี้สถานะ '{$bookingRoom->status}' (ต้องเป็น checked_in เท่านั้นนะคะ)",
                    422
                );
            }

            // 💳 (2026-11-06) บิล deferred (ค้างชำระ) ข้ามการตรวจยอดค้างตอน Check-out —
            //    เก็บเงินปลายทางทีหลังผ่าน recordPayment ได้ (บิล complete จ่ายครบแล้วจะ set is_paid=true ตามปกติ)
            //    🌟 มติ (06/11/26) "ตั้งแต่ห้องแรก": เช็คเอาท์ห้องไหนก็ตามต้องชำระครบทั้งบิลก่อนเสมอ
            if ($booking->payment_type !== 'deferred') {
                // ตรวจสอบยอดชำระเงิน
                $totalPaid = Payment::where('booking_id', $bookingId)
                    ->where('status', 'completed')
                    ->sum('amount');

                $grandTotal = $booking->total_amount + $booking->charges_total;

                if ($totalPaid < $grandTotal) {
                    $pendingAmount = $grandTotal - $totalPaid;
                    throw new \Exception("ยังมีรายการค้างชำระอยู่ {$pendingAmount} บาทค่ะนายท่าน กรุณารับชำระเงินก่อนนะคะ");
                }
            }

            $room = $bookingRoom->room_id ? Room::findOrFail($bookingRoom->room_id) : null;

            // 🌟 Refactor (25/06/26): BR state machine → checked_out
            $bookingRoom->transitionStatus('checked_out', $user->role);

            if ($room) {
                // 🌟 ใช้ state machine เปลี่ยนสถานะห้อง → checkout_makeup
                $room->transitionStatusTo('checkout_makeup', $validated['verified_by']);

                // 🧹 Phase A (15/07/26): สร้าง housekeeping task — เพิ่ม task_type + duplicate guard (Fix S-B2)
                //    ก่อนสร้าง → เช็คว่ามี active task ในห้องนี้อยู่แล้วหรือไม่ ถ้ามีไม่สร้างซ้ำ
                $activeExists = HousekeepingTask::where('room_id', $room->id)
                    ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])
                    ->exists();

                if (! $activeExists) {
                    // checkout_then_in = checkout ที่มี confirmed booking check_in วันนี้ ใน room_type เดียวกัน
                    // (กรณีรีบเคลียร์ห้องเพราะแขกใหม่จะเข้าทันที)
                    $rush = BookingRoom::where('room_type_id', $room->room_type_id)
                        ->whereDate('check_in', Carbon::today())
                        ->where('status', 'confirmed')
                        ->exists();

                    $task = HousekeepingTask::create([
                        'id' => Str::uuid(),
                        'room_id' => $room->id,
                        'task_type' => $rush ? 'checkout_then_in' : 'checkout',
                        'status' => 'unassigned',
                        'notes' => $validated['notes'] ?? 'Auto-generated from Check-out',
                    ]);
                } else {
                    $task = null;
                }
            } else {
                $task = null;
            }

            // 🌟 Auto-sync booking container → complete ถ้า BR ทุกห้องจบแล้ว (checked_out|no_show)
            $booking->syncStatusFromRooms();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-out completed successfully. สร้างงานให้ทีมแม่บ้านเรียบร้อยค่ะ!',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
                'booking_room_id' => $bookingRoom->id,
                'room_updates' => [
                    [
                        'room_number' => $room?->room_number,
                        'room_status' => $room?->status,
                        'housekeeping_task_id' => $task?->id,
                    ],
                ],
            ], 200);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            $modelName = class_basename($e->getModel());

            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน",
            ], 404);

        } catch (\Exception $e) {
            return $this->handleCatch('Check-out', $e);
        }
    }

    // 🚫 5. Mark No-Show (BR-level) — รองรับ partial (เลือกเฉพาะห้องได้)
    public function markNoShow(Request $request, $bookingId)
    {
        // 🌟 Fix L4 (03/07/26): defense-in-depth — ตรวจ role ใน controller ด้วย
        $user = $request->user();
        if (! $user || $user->role !== 'admin') {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินเท่านั้นถึงจะ Mark No-Show ได้ค่ะนายท่าน',
            ], 403);
        }

        $validated = $request->validate([
            'verified_by' => 'required|uuid|exists:users,id',
            // 🌟 Fix (03/07/26): รองรับ partial no-show — ถ้าไม่ส่ง = mark ทุกห้องใน booking
            'booking_room_ids' => 'nullable|array',
            'booking_room_ids.*' => 'required|uuid|exists:booking_rooms,id',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);

            // 🛡️ Guard 1: booking ที่จบไปแล้ว ทำอะไรต่อไม่ได้
            if ($booking->status === 'complete') {
                throw new \Exception('รายการจองนี้จบสิ้นไปแล้วค่ะ ไม่สามารถ mark No-Show ได้');
            }

            // 🌟 เลือก BR ที่จะ mark (ถ้าไม่ส่ง booking_room_ids = ทุกห้อง)
            $targetRooms = ! empty($validated['booking_room_ids'])
                ? $booking->bookingRooms->whereIn('id', $validated['booking_room_ids'])
                : $booking->bookingRooms;

            if ($targetRooms->isEmpty()) {
                throw new \Exception('ไม่พบห้องที่ระบุในรายการจองนี้ค่ะนายท่าน');
            }

            // 🛡️ Guard 2: ทุกห้องที่จะ mark ต้องเป็น draft หรือ confirmed เท่านั้น
            // (⏱️ 2026-10-27: ยอมรับ draft เพราะ verify ไม่ได้ย้ายสถานะห้อง — ห้องบน prod ค้าง draft ทั้งหมด)
            // (checked_in เข้าพักแล้ว / checked_out / no_show จบไปแล้ว → ยัง reject)
            foreach ($targetRooms as $bRoom) {
                if (! in_array($bRoom->status, ['draft', 'confirmed'], true)) {
                    throw new \Exception(
                        "ไม่สามารถ mark No-Show ได้ค่ะนายท่าน เนื่องจากห้องมีสถานะ '{$bRoom->status}' ".
                        "(ต้องเป็น 'draft' หรือ 'confirmed' เท่านั้น) กรุณาตรวจสอบอีกครั้งนะคะ"
                    );
                }
            }

            // 🌟 Fix (03/07/26): ถ้า booking ยังเป็น paid (จ่ายแล้วยังไม่ confirm) → confirmed ก่อน
            // รองรับสถานการณ์จริง: ลูกค้าจ่ายเงินแล้วแต่ไม่มา ระบบยังปิดการจองได้
            if ($booking->status === 'paid') {
                $booking->transitionStatus('confirmed', 'admin');
            }

            // Mark no-show ทีละห้อง (ใช้ BR state machine)
            // ⏱️ (2026-10-27) ห้องค้าง draft → ยืนยันก่อน (สองสเต็ปตาม state machine เหมือน checkIn)
            foreach ($targetRooms as $bRoom) {
                if ($bRoom->status === 'draft') {
                    $bRoom->transitionStatus('confirmed', 'admin');
                }
                $bRoom->transitionStatus('no_show', 'admin');
            }

            // 🌟 Auto-sync booking container → complete (ถ้าทุกห้องจบแล้ว)
            // กรณี partial no-show (ยังมีห้อง confirmed/checked_in) → booking ยังเป็น confirmed รอเหลือห้องจบ
            $booking->refresh();
            $booking->syncStatusFromRooms();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Mark No-Show สำเร็จแล้วค่ะนายท่าน',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
            ], 200);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            $modelName = class_basename($e->getModel());

            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน",
            ], 404);

        } catch (\Exception $e) {
            return $this->handleCatch('Mark no-show', $e);
        }
    }

    // 💸 4. บันทึกการรับชำระเงิน
    public function recordPayment(StorePaymentRequest $request, $bookingId)
    {
        // 🌟 Fix L4 (03/07/26): defense-in-depth — ตรวจ role ใน controller ด้วย
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินหรือเจ้าหน้าที่เท่านั้นถึงจะบันทึกการรับชำระเงินได้ค่ะนายท่าน',
            ], 403);
        }

        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            $payment = Payment::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'amount' => $validated['amount'],
                'status' => 'completed',
                'reference_number' => $validated['reference_number'] ?? null,
                'received_by' => $validated['received_by'] ?? null,
            ]);

            // ✅ #18 Fixed: อัปเดต is_paid + booking status เมื่อชำระครบแล้ว
            // 💸 (15/10/26, ticket 13): "ครบ" = total + charges (ค่าปรับ/ค่าเสียหาย/addon
            //    บวกยอดจริง — เก็บส่วนต่าง charge ที่ front desk ตัวนี้)
            $grandTotal = $booking->total_amount + $booking->charges_total;
            $totalPaid = Payment::where('booking_id', $booking->id)
                ->where('status', 'completed')
                ->sum('amount');

            if ($totalPaid >= $grandTotal && ! $booking->is_paid) {
                // 🌟 Fix H3 (03/07/26): unify is_paid write ให้เป็นวิธีเดียวกับ webhook
                // ใช้ Eloquent update (PHP boolean) แทน DB::raw('TRUE') — portable ข้าม DB + trigger events
                $booking->update(['is_paid' => true]);

                // 🌟 Refactor (25/06/26): draft | pending → paid (container)
                if (in_array($booking->status, ['draft', 'pending'], true)) {
                    $booking->transitionStatus('paid', $user->role);
                }

                // ❄️ FROZEN (24/07/26): Receipt table deprecated — ไม่สร้าง receipt row ใหม่อีกต่อไป
                //    ใช้ booking_confirmations table แทน (ดู POST /bookings/{id}/confirm + admin verify)
            }

            DB::commit();

            $freshBooking = $booking->fresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Payment recorded successfully!',
                'payment' => $payment,
                'booking_is_paid' => $freshBooking->is_paid,
                'booking_status' => $freshBooking->status,
                // 💳 (25/09/26, ticket 05 หัวข้อ 7): overpay ยอมรับ (เขียน row ตามจริง —
                //    outstanding clamp 0) · multiple calls ได้ (1 ครั้ง = 1 row ใน ledger)
                'payment_type' => $freshBooking->payment_type,
                'deposit_amount' => $freshBooking->deposit_amount,
                'paid_amount' => $freshBooking->paid_amount,
                'outstanding_amount' => $freshBooking->outstanding_amount,
            ], 201);

        } catch (\Exception $e) {
            return $this->handleCatch('Record payment', $e);
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

    private function handleCatch(string $action, \Exception $e): JsonResponse
    {
        DB::rollBack();

        if ($e instanceof QueryException || $e instanceof \PDOException) {
            Log::error("{$action} database error: ".$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => "เกิดข้อผิดพลาดในการดำเนินการ {$action} กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭",
            ], 500);
        }

        Log::error("{$action} failed: ".$e->getMessage());
        $statusCode = $e->getCode();
        $statusCode = ($statusCode >= 400 && $statusCode <= 599) ? $statusCode : 400;

        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], $statusCode);
    }
}
