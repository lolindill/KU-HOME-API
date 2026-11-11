<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UploadCheckinEvidenceRequest;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\DiscountRedemption;
use App\Models\GlobalRate;
use App\Models\HousekeepingTask;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\StatusChangeLog;
use App\Models\User;
use App\Services\Discount\DiscountService;
use App\Services\RoomAllocator\RoomAllocator;
use App\Support\BookingRule;
use App\Support\IncludeReservedGate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    // 🚪 2.5 Change Room (In-stay room move, BR-level) — 2026-11-04
    //    ย้ายห้องจริง (room_id) หลัง check-in ได้ · เปลี่ยน room_type_id ได้เมื่อ type ใหม่แพงกว่า
    //    เท่านั้น (upgrade-only) · ห้องเก่า → dirty + งานแม่บ้าน 'checkout'
    //    🚪 (2026-11-09): ① รับ BR confirmed (มีเลขห้องผูกไว้) — บิล paid/confirmed ย้ายล่วงหน้าได้
    //    โดยไม่แตะสถานะห้องทั้งสองฝั่ง · ② ห้องปลายทางรับ dirty — in-stay: เปิด task 'daily'
    //    ให้ห้องใหม่ก่อน transition → occupied · BR confirmed: คง dirty + task 'daily' รอเก็บ
    //    · ห้องเก่า in-stay เดิม checkout_makeup → เปลี่ยนเป็น dirty (มติ owner)
    public function changeRoom(Request $request, $bookingId, $bookingRoomId)
    {
        // 🌟 Defense-in-depth: ตรวจ role ใน controller ด้วย ไม่พึ่ง middleware อย่างเดียว
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินหรือเจ้าหน้าที่เท่านั้นถึงจะย้ายห้องได้ค่ะนายท่าน',
            ], 403);
        }

        $validated = $request->validate([
            'room_id' => 'required|uuid|exists:rooms,id',
            'notes' => 'nullable|string|max:255',
            'price_per_night' => 'nullable|integer|min:0',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            // 🔒 BR ต้องอยู่ใต้ booking นี้จริง (ของ booking อื่น = 404)
            $bookingRoom = $booking->bookingRooms()->where('id', $bookingRoomId)->firstOrFail();

            // 🛡️ Guard: BR ต้อง checked_in หรือ confirmed — ย้ายได้ทั้งกลางทางและก่อนเช็คอิน
            //    (2026-11-09): รับ BR confirmed ที่มีเลขห้องผูกไว้แล้ว — สลับห้องล่วงหน้าได้
            if (! in_array($bookingRoom->status, ['confirmed', 'checked_in'])) {
                throw new \Exception(
                    "ย้ายห้องได้เฉพาะห้องที่เช็คอินแล้ว (checked_in) หรือยืนยันแล้ว (confirmed) เท่านั้นค่ะนายท่าน ห้องนี้สถานะ '{$bookingRoom->status}'",
                    422
                );
            }

            // 🛡️ Guard: booking ตามสถานะ BR — BR checked_in มาจาก flow check-in (บิล confirmed) เสมอ
            //    (2026-11-09): BR confirmed ยังไม่เข้าพัก → บิล paid หรือ confirmed ก็ย้ายได้
            $isInStay = $bookingRoom->status === 'checked_in';
            $allowedBookingStatuses = $isInStay ? ['confirmed'] : ['paid', 'confirmed'];
            if (! in_array($booking->status, $allowedBookingStatuses)) {
                throw new \Exception(
                    'ย้ายห้องได้เฉพาะรายการจองสถานะ \''.implode('\' หรือ \'', $allowedBookingStatuses).
                    "' เท่านั้นค่ะนายท่าน สถานะปัจจุบันคือ '{$booking->status}'",
                    422
                );
            }

            if (! $bookingRoom->room_id) {
                throw new \Exception('ห้องนี้ยังไม่มีเลขห้องจริงที่จัดไว้ จึงไม่มีห้องให้ย้ายออกค่ะนายท่าน', 422);
            }

            if ((string) $validated['room_id'] === (string) $bookingRoom->room_id) {
                throw new \Exception('แขกอยู่ห้องนี้อยู่แล้วค่ะนายท่าน กรุณาระบุห้องใหม่ที่ต่างจากเดิมนะคะ', 422);
            }

            // 🌟 lock ทั้งบิล + ห้องเก่า + ห้องใหม่ แล้ว re-check กัน race กับ check-in/check-out พร้อมกัน
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, $allowedBookingStatuses)) {
                throw new \Exception(
                    'ย้ายห้องได้เฉพาะรายการจองสถานะ \''.implode('\' หรือ \'', $allowedBookingStatuses).
                    "' เท่านั้นค่ะนายท่าน สถานะปัจจุบันคือ '{$locked->status}'",
                    422
                );
            }

            $oldRoom = Room::lockForUpdate()->findOrFail($bookingRoom->room_id);
            $newRoom = Room::with('roomType')->lockForUpdate()->findOrFail($validated['room_id']);

            // 🛡️ ห้องใหม่ต้องพร้อมรับแขก — transition → occupied ถูกกฎ state machine เสมอ
            //    (2026-11-09): รับ dirty ด้วย — แขกยอมเข้าห้องที่ยังไม่เก็บ (occupied รับ dirty แล้ว)
            if (! in_array($newRoom->status, ['available', 'prep_checkin', 'dirty'])) {
                throw new \Exception(
                    "ห้องหมายเลข {$newRoom->room_number} ไม่พร้อมให้เข้าพัก (สถานะ: {$newRoom->status}) กรุณาเลือกห้องอื่นค่ะนายท่าน",
                    422
                );
            }

            // 🗓️ (24/09/26) room-state-periods: maintenance period ทับช่วงพักของ BR → reject
            //    (reserved period ไม่บล็อก — ตามธรรมเนียม check-in ของ admin/staff)
            if (RoomStatePeriod::where('room_id', $newRoom->id)
                ->overlapping($bookingRoom->check_in, $bookingRoom->check_out, RoomStatePeriod::KIND_MAINTENANCE)
                ->exists()) {
                throw new \Exception(
                    "ห้อง {$newRoom->room_number} อยู่ในช่วงซ่อมแซม (maintenance period) ทับช่วงเข้าพัก ".
                    "({$bookingRoom->check_in->toDateString()} – {$bookingRoom->check_out->toDateString()}) ".
                    'ไม่สามารถย้ายเข้าได้ค่ะนายท่าน',
                    422
                );
            }

            // ⏱️ overlap guard — ห้ามย้ายทับห้องที่มี BR อื่น (holdingSlot) ยึดช่วงเดียวกัน
            //    (pattern เดียวกับ check-in assigned_rooms — ตัดตัวเองออก)
            $overlap = $newRoom->bookingRooms()
                ->where('booking_rooms.id', '!=', $bookingRoom->id)
                ->holdingSlot()
                ->where('check_in', '<', $bookingRoom->check_out)
                ->where('check_out', '>', $bookingRoom->check_in)
                ->exists();
            if ($overlap) {
                throw new \Exception(
                    "ห้องหมายเลข {$newRoom->room_number} มีการจองอื่นทับช่วงเข้าพัก ".
                    "({$bookingRoom->check_in->toDateString()} – {$bookingRoom->check_out->toDateString()}) ".
                    'ไม่สามารถย้ายเข้าได้ค่ะนายท่าน กรุณาเลือกห้องอื่นนะคะ',
                    422
                );
            }

            $newRoomType = $newRoom->roomType;
            $oldRoomType = RoomType::findOrFail($bookingRoom->room_type_id);
            $typeChanged = (string) $newRoom->room_type_id !== (string) $bookingRoom->room_type_id;
            $previousTotal = (int) $locked->total_amount;

            // 👥 guests cap — จำนวนแขกเดิมต้องไม่เกิน max_guests ของ type ใหม่
            $guests = $bookingRoom->guests;
            if (! empty($guests) && is_array($guests) && $newRoomType && count($guests) > $newRoomType->max_guests) {
                throw new \Exception(
                    'ผู้เข้าพัก '.count($guests).' คนเกินจำนวนสูงสุด '.$newRoomType->max_guests.' คนของห้องประเภทนี้ค่ะ 🥺',
                    422
                );
            }

            // 🚪 Commit การย้าย — BR คงสถานะเดิม (สถานะไม่เปลี่ยน จึงไม่ผ่าน transitionStatus)
            $bookingRoom->update([
                'room_id' => $newRoom->id,
                'room_type_id' => $newRoom->room_type_id,
            ]);

            // 🧹 ห้องเก่า (in-stay) → dirty + งานแม่บ้าน task_type 'checkout' — แขกย้ายออก = ห้องต้องเก็บ
            //    duplicate-guard: ถ้ามี active task อยู่แล้วไม่สร้างซ้ำ (Fix S-B2 pattern)
            //    (2026-11-09): เดิม checkout_makeup → เปลี่ยนเป็น dirty (owner มติ) · เฉพาะ
            //    in-stay เท่านั้น — BR confirmed แขกยังไม่เคยเข้าพัก = ไม่มี checkout
            //    → ปล่อยห้องเก่าคงสถานะเดิม ไม่เปิดงานแม่บ้าน (tier-endpoints-v2 มติ v2 ข้อ 4)
            $housekeepingTaskId = null;
            $newRoomHousekeepingTaskId = null;
            if ($isInStay) {
                $oldRoom->transitionStatusTo('dirty', $user->id);
                $activeExists = HousekeepingTask::where('room_id', $oldRoom->id)
                    ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])
                    ->exists();

                if (! $activeExists) {
                    $task = HousekeepingTask::create([
                        'id' => Str::uuid(),
                        'room_id' => $oldRoom->id,
                        'task_type' => 'checkout',
                        'status' => 'unassigned',
                        'notes' => $validated['notes'] ?? ('Auto-generated from room change ('.$oldRoom->room_number.' → '.$newRoom->room_number.')'),
                    ]);
                    $housekeepingTaskId = $task->id;
                }

                // 🧹 ห้องปลายทาง dirty → เปิด task 'daily' ให้แม่บ้านก่อน transition (มติ 2026-11-09
                //    "call housekeeping task first") · ห้อง available/prep_checkin ไม่ต้อง
                if ($newRoom->status === 'dirty') {
                    $newRoomTaskExists = HousekeepingTask::where('room_id', $newRoom->id)
                        ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])
                        ->exists();
                    if (! $newRoomTaskExists) {
                        $newRoomTask = HousekeepingTask::create([
                            'id' => Str::uuid(),
                            'room_id' => $newRoom->id,
                            'task_type' => 'daily',
                            'status' => 'unassigned',
                            'notes' => 'Auto: guest moved into dirty room ('.$oldRoom->room_number.' → '.$newRoom->room_number.') — cleaning required',
                        ]);
                        $newRoomHousekeepingTaskId = $newRoomTask->id;
                    }
                }

                // 🌟 ห้องใหม่ → occupied (dirty ผ่าน — machine รับ dirty→occupied แล้ว 2026-11-09)
                $newRoom->transitionStatusTo('occupied', $user->id);
            }

            // 💰 Reprice ทั้งบิล (full-stay) — รองรับ manual price_per_night (admin+staff — tier-endpoints-v2)
            $nights = (int) Carbon::parse($bookingRoom->check_in)->startOfDay()
                ->diffInDays(Carbon::parse($bookingRoom->check_out)->startOfDay()) ?: 1;
            $roomAmountOverrides = [];
            if (isset($validated['price_per_night']) && $validated['price_per_night'] !== null) {
                $manualRate = (int) $validated['price_per_night'];
                $roomAmountOverrides[$bookingRoom->id] = $manualRate * $nights;
            }
            app(DiscountService::class)->reprice($locked, $roomAmountOverrides);

            $newTotal = (int) $locked->fresh()->total_amount;
            $paidAmount = $locked->paid_amount;

            // 🚪 tier-endpoints-v2 (มติ v2 ข้อ 1): ตัด ledger-guard 422 ออก, recompute is_paid ตามนิยาม
            $locked->fresh()->syncIsPaid();

            // 📝 Audit — note row ของ BR (from=to=สถานะ BR เดิม · note เล่าการย้าย)
            //    (tier-endpoints-v2: บันทึก pre-checkin move สำหรับ BR confirmed, บันทึก direction + ราคาเดิม→ใหม่)
            $direction = match (true) {
                $newTotal > $previousTotal => 'upgrade',
                $newTotal < $previousTotal => 'downgrade',
                default => 'same-tier',
            };
            $movePrefix = $isInStay ? 'ย้ายห้อง' : 'ย้ายห้องก่อนเช็คอิน (pre-checkin move)';
            $noteParts = ["{$movePrefix} {$oldRoom->room_number} → {$newRoom->room_number}"];
            if ($typeChanged) {
                $noteParts[] = "{$direction} type {$oldRoomType->name_en} → {$newRoomType->name_en}";
            }
            if (isset($validated['price_per_night']) && $validated['price_per_night'] !== null) {
                $noteParts[] = "manual rate {$validated['price_per_night']} บาท/คืน";
            }
            if ($newTotal !== $previousTotal) {
                $noteParts[] = "price {$previousTotal} → {$newTotal} บาท";
            }

            StatusChangeLog::create([
                'entity_type' => 'booking_room',
                'entity_id' => $bookingRoom->id,
                'from_status' => $bookingRoom->status,
                'to_status' => $bookingRoom->status,
                'role' => $user->role,
                'causer_id' => $user->id,
                'note' => implode(' · ', $noteParts),
            ]);

            DB::commit();

            $typeChangeMessage = '';
            if ($typeChanged) {
                if ($newTotal > $previousTotal) {
                    $typeChangeMessage = ' อัปเกรดประเภทห้องเป็น '.$newRoomType->name_en.' — ส่วนต่างเข้ายอดค้างชำระ เก็บต่อผ่าน front-desk ได้ค่ะ 💳';
                } elseif ($newTotal < $previousTotal) {
                    $typeChangeMessage = ' ปรับลดประเภทห้องเป็น '.$newRoomType->name_en.' — ยอดรวมปรับลดตามส่วนต่างค่ะ 💳';
                } else {
                    $typeChangeMessage = ' เปลี่ยนประเภทห้องเป็น '.$newRoomType->name_en.' เรียบร้อยแล้วค่ะ 💳';
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'ย้ายห้องเรียบร้อยแล้วค่ะนายท่าน 🚪✨ ('.$oldRoom->room_number.' → '.$newRoom->room_number.')'.$typeChangeMessage,
                'booking_id' => $booking->id,
                'booking_room_id' => $bookingRoom->id,
                'old_room_number' => $oldRoom->room_number,
                'new_room_number' => $newRoom->room_number,
                'room_type_changed' => $typeChanged,
                'old_room_type' => $oldRoomType->name_en,
                'new_room_type' => $newRoomType->name_en,
                'previous_total_amount' => $previousTotal,
                'total_amount' => $newTotal,
                'surcharge_amount' => $newTotal - $previousTotal,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => $locked->fresh()->outstanding_amount,
                'housekeeping_task_id' => $housekeepingTaskId,
                'new_room_housekeeping_task_id' => $newRoomHousekeepingTaskId,
            ], 200);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            $modelName = class_basename($e->getModel());

            return response()->json([
                'status' => 'error',
                'message' => "ไม่พบข้อมูล {$modelName} ที่ระบุในระบบค่ะนายท่าน",
            ], 404);

        } catch (\Exception $e) {
            return $this->handleCatch('Change-room', $e);
        }
    }

    /**
     * 🔀 Mid-stay split-upgrade (2026-11-11 — wayfinder/mid-stay-split-upgrade)
     * แบ่ง booking_room ของบิลที่ยังไม่ปิด ออกเป็น 2 ช่วง:
     * BR-1: ตัด check_out สั้นลงถึง split_date (สัญญาสัดส่วน intdiv)
     * BR-2: ใหม่ช่วงครึ่งหลังบน room_type tier สูงกว่า (ราคา manual admin หรือ computed staff)
     */
    public function splitUpgrade(Request $request, $bookingId, $bookingRoomId): JsonResponse
    {
        // 🌟 Defense-in-depth: ตรวจ role ใน controller ด้วย ไม่พึ่ง middleware อย่างเดียว (pattern :157-164)
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินหรือเจ้าหน้าที่เท่านั้นถึงจะดำเนินการได้ค่ะนายท่าน',
            ], 403);
        }

        $validated = $request->validate([
            'split_date' => 'required|date',
            'room_type_id' => 'required|uuid|exists:room_types,id',
            'price_per_night' => 'nullable|integer',
            'room_id' => 'nullable|uuid|exists:rooms,id',
            'notes' => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            // 🌟 1. Load + scope: บิลจาก bookingId, BR จากบิลนั้นเท่านั้น (404 ข้ามบิล — pattern :175-176)
            $bookingRoom = $booking->bookingRooms()->where('id', $bookingRoomId)->firstOrFail();

            // 🌟 Lock ทั้งบิล + ห้องเก่า (ถ้ามี) + ห้องใหม่ (ถ้ามี) แบบ changeRoom :389-399
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            // Container gate: container ต้อง confirmed|draft (มติ #1 รอบสอง)
            if (! in_array($locked->status, ['confirmed', 'draft'], true)) {
                throw new \Exception(
                    "ไม่สามารถแบ่งห้องพักได้ค่ะนายท่าน รายการจองต้องอยู่ในสถานะ confirmed หรือ draft เท่านั้น (สถานะปัจจุบันคือ '{$locked->status}')",
                    422
                );
            }

            // BR status gate: ภายใต้บิล confirmed = checked_in|confirmed · ภายใต้บิล draft = draft
            if ($locked->status === 'confirmed') {
                if (! in_array($bookingRoom->status, ['checked_in', 'confirmed'], true)) {
                    throw new \Exception(
                        "ไม่สามารถแบ่งห้องพักได้ค่ะนายท่าน ภายใต้รายการจองที่ยืนยันแล้ว ห้องพักต้องมีสถานะ checked_in หรือ confirmed เท่านั้น (สถานะปัจจุบันคือ '{$bookingRoom->status}')",
                        422
                    );
                }
            } else { // draft
                if ($bookingRoom->status !== 'draft') {
                    throw new \Exception(
                        "ไม่สามารถแบ่งห้องพักได้ค่ะนายท่าน ภายใต้รายการจองร่าง ห้องพักต้องมีสถานะ draft เท่านั้น (สถานะปัจจุบันคือ '{$bookingRoom->status}')",
                        422
                    );
                }
            }

            // 🌟 2. บล็อก v1 (มติ #6): บิลมี discount_code หรือ discount_redemptions (held/used) ใดๆ → 422
            $hasDiscount = ! empty($locked->discount_code)
                || DiscountRedemption::whereIn('booking_room_id', $locked->bookingRooms()->pluck('id'))->exists();
            if ($hasDiscount) {
                throw new \Exception(
                    'รายการจองนี้มีส่วนลด ระบบยังไม่รองรับการแบ่งห้องพักสำหรับรายการจองที่มีส่วนลดในเวอร์ชันนี้ค่ะ 🎟️',
                    422
                );
            }

            // 🌟 3. Validate split_date (มติ #2 รอบสอง):
            // ต้องอยู่ใน (check_in, check_out) เคร่งครัด ครึ่งละ ≥ 1 คืน + ≥ วันนี้ (ห้ามย้อนหลัง)
            // เทียบ Carbon::today(BookingRule::TIMEZONE) — tz Asia/Bangkok
            $today = Carbon::today(BookingRule::TIMEZONE)->startOfDay();
            $splitDate = Carbon::parse($validated['split_date'])->startOfDay();

            if ($splitDate->lt($today)) {
                throw new \Exception('วันที่ต้องการแบ่งห้องพัก (split_date) ต้องไม่เป็นวันที่ในอดีตค่ะ 🗓️', 422);
            }

            $checkIn = Carbon::parse($bookingRoom->check_in)->startOfDay();
            $checkOut = Carbon::parse($bookingRoom->check_out)->startOfDay();

            if ($splitDate->lte($checkIn) || $splitDate->gte($checkOut)) {
                throw new \Exception(
                    "วันที่ต้องการแบ่งห้องพัก ({$splitDate->toDateString()}) ต้องอยู่ระหว่างช่วงเข้าพัก ({$checkIn->toDateString()} ถึง {$checkOut->toDateString()}) และต้องมีระยะเวลาพักอย่างน้อยช่วงละ 1 คืนค่ะ 🗓️",
                    422
                );
            }

            $n0 = (int) $checkIn->diffInDays($checkOut);
            $n1 = (int) $checkIn->diffInDays($splitDate);
            $n2 = (int) $splitDate->diffInDays($checkOut);

            // 🌟 4. Validate upgrade-only (มติ #5):
            $newRoomType = RoomType::findOrFail($validated['room_type_id']);
            $oldRoomType = RoomType::findOrFail($bookingRoom->room_type_id);

            if ((string) $newRoomType->id === (string) $oldRoomType->id) {
                throw new \Exception('ประเภทห้องใหม่ต้องแตกต่างจากประเภทห้องเดิมค่ะนายท่าน 🏨', 422);
            }

            $oldEffectiveRate = GlobalRate::getEffectiveDailyRate($oldRoomType, $locked->user);
            $newEffectiveRate = GlobalRate::getEffectiveDailyRate($newRoomType, $locked->user);

            if ($newEffectiveRate <= $oldEffectiveRate) {
                throw new \Exception(
                    'การแบ่งห้องพักรองรับเฉพาะการอัปเกรดเป็นประเภทห้องที่สูงกว่าเท่านั้นค่ะนายท่าน '.
                    "({$oldRoomType->name_en} {$oldEffectiveRate} → {$newRoomType->name_en} {$newEffectiveRate} บาท/คืน) 🎫",
                    422
                );
            }

            $roomAmount0 = (int) $bookingRoom->room_amount;
            $avgRate0 = (int) ceil($roomAmount0 / $n0);
            $roomAmount1 = intdiv($roomAmount0 * $n1, $n0);
            $previousTotal = (int) $locked->total_amount;

            $hasManualPrice = array_key_exists('price_per_night', $validated) && $validated['price_per_night'] !== null;
            if ($hasManualPrice) {
                if ($user->role !== 'admin') {
                    throw new \Exception('เฉพาะผู้ดูแลระบบ (admin) เท่านั้นที่สามารถระบุราคาห้องพักต่อคืนได้ค่ะนายท่าน', 422);
                }
                $pricePerNight = (int) $validated['price_per_night'];
                if ($pricePerNight < $avgRate0) {
                    throw new \Exception(
                        "ราคาต่อคืนที่ระบุ ({$pricePerNight} บาท) ต้องไม่ต่ำกว่าเรทเฉลี่ยต่อคืนเดิมตามสัญญา ({$avgRate0} บาท/คืน) ค่ะ 💰",
                        422
                    );
                }
                $br2RoomAmount = $pricePerNight * $n2;
            } else {
                // Computed path (staff หรือ admin ไม่ได้กรอก price_per_night)
                // Pre-flight check (scrutinize): computed_rate × n₂ < room_amount₀ − intdiv(room_amount₀ × n₁, n₀) → 422
                $minBr2Needed = $roomAmount0 - $roomAmount1;
                if ($newEffectiveRate * $n2 < $minBr2Needed) {
                    throw new \Exception(
                        "เรทมาตรฐานของประเภทห้องใหม่ ({$newEffectiveRate} บาท/คืน) รวม {$n2} คืน ไม่เพียงพอต่อส่วนต่างค่าห้องเดิม แนะนำให้ผู้ดูแลระบบ (admin) กำหนดราคาต่อคืน (price_per_night) เองค่ะนายท่าน 💰",
                        422
                    );
                }
                $pricePerNight = $newEffectiveRate;
                $br2RoomAmount = $newEffectiveRate * $n2;
            }

            // 🌟 5. Trim BR-1: check_out = split_date (ห้ามเปลี่ยน status ตามมติ #3)
            $bookingRoom->update([
                'check_out' => $splitDate->toDateString(),
            ]);

            // 🌟 6. ราคา BR-1 สัญญาสัดส่วน: $roomAmount1 (intdiv) เตรียมไว้สำหรับ override map

            // 🌟 7. สร้าง BR-2:
            // guests cap check (มติ #11 scrutinize):
            $guests = $bookingRoom->guests;
            $guestCount = is_array($guests) ? count($guests) : 0;
            if ($newRoomType->max_guests !== null && $guestCount > $newRoomType->max_guests) {
                throw new \Exception(
                    "split ขึ้นประเภทห้อง {$newRoomType->name_en} ไม่ได้เพราะผู้เข้าพัก {$guestCount} คน เกินจำนวนสูงสุดที่รองรับได้ ({$newRoomType->max_guests} คน) — กรุณาเลือกประเภทห้องที่รองรับจำนวนผู้เข้าพักได้ครบนะคะ 👥",
                    422
                );
            }

            // bed_preference: ถ้า bed option ไม่มีใน tier ใหม่ → null
            $bedPreference = $bookingRoom->bed_preference;
            if ($bedPreference !== null) {
                $hasBedOption = Room::where('room_type_id', $newRoomType->id)
                    ->where('bed_type', $bedPreference)
                    ->exists();
                if (! $hasBedOption) {
                    $bedPreference = null;
                }
            }

            // สร้าง status 'draft' เสมอ แล้วภายใต้บิล confirmed เรียก transitionStatus('confirmed', $userRole)
            $br2 = BookingRoom::create([
                'booking_id' => $locked->id,
                'room_type_id' => $newRoomType->id,
                'room_id' => null,
                'check_in' => $splitDate->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'guests' => $guests,
                'status' => 'draft',
                'bed_preference' => $bedPreference,
                'billing_address' => $bookingRoom->billing_address,
                'billing_comment' => $bookingRoom->billing_comment,
                'room_amount' => 0,
                'discount_amount' => 0,
                'amount' => 0,
            ]);

            if ($locked->status === 'confirmed') {
                $br2->transitionStatus('confirmed', $user->role);
            }

            // 🌟 8. Addon carry + re-slice (มติ #7):
            $addon = $bookingRoom->addon;
            if ($addon) {
                $byNight = is_array($addon->extra_beds_by_night) ? $addon->extra_beds_by_night : [];
                $br1Beds = [];
                $br2Beds = [];

                foreach ($byNight as $dateKey => $qty) {
                    $qty = (int) $qty;
                    if ($qty <= 0) {
                        continue;
                    }
                    $nightDate = Carbon::parse($dateKey)->startOfDay();
                    if ($nightDate->lt($splitDate)) {
                        $br1Beds[$dateKey] = $qty;
                    } else {
                        if ($newRoomType->max_extra_beds !== null && $qty > $newRoomType->max_extra_beds) {
                            throw new \Exception(
                                "จำนวนเตียงเสริมคืน {$dateKey} ({$qty} เตียง) เกินขีดสุดของประเภทห้อง {$newRoomType->name_en} (สูงสุด {$newRoomType->max_extra_beds} ต่อคืน) ค่ะ 🛏️",
                                422
                            );
                        }
                        $br2Beds[$dateKey] = $qty;
                    }
                }

                // 🌟 ดักจับ late_hours ก่อน update ที่ BR-1 — อ่านหลัง update = ได้ 0 เสมอ (instance เดิมโดน mutate)
                $lateHoursToCarry = (int) ($addon->late_hours ?? 0);

                $addon->update([
                    'extra_beds_by_night' => ! empty($br1Beds) ? $br1Beds : null,
                    'late_hours' => 0,
                ]);

                Addon::create([
                    'booking_room_id' => $br2->id,
                    'breakfast_set_100' => $addon->breakfast_set_100 ?? 0,
                    'breakfast_set_200' => $addon->breakfast_set_200 ?? 0,
                    'extra_beds_by_night' => ! empty($br2Beds) ? $br2Beds : null,
                    'early_hours' => 0,
                    'late_hours' => $lateHoursToCarry,
                ]);
            }

            // 🌟 9. จัดห้อง BR-2 (มติ #8):
            if (! empty($validated['room_id'])) {
                $assignedRoom = Room::with('roomType')->lockForUpdate()->findOrFail($validated['room_id']);

                // Pin check: validate room.room_type_id === room_type_id ที่ส่งมาก่อนเป็นอันดับแรก (422 ถ้าไม่ตรง)
                if ((string) $assignedRoom->room_type_id !== (string) $newRoomType->id) {
                    throw new \Exception(
                        "ห้องหมายเลข {$assignedRoom->room_number} (ประเภท: {$assignedRoom->roomType->name_en}) ".
                        'ไม่ตรงกับประเภทห้องที่เลือกอัปเกรดค่ะนายท่าน กรุณาตรวจสอบอีกครั้งนะคะ 🏨',
                        422
                    );
                }

                if (! in_array($assignedRoom->status, ['available', 'prep_checkin', 'dirty'], true)) {
                    throw new \Exception(
                        "ห้องหมายเลข {$assignedRoom->room_number} ไม่พร้อมให้เข้าพัก (สถานะ: {$assignedRoom->status}) กรุณาเลือกห้องอื่นค่ะนายท่าน 🚪",
                        422
                    );
                }

                if (RoomStatePeriod::where('room_id', $assignedRoom->id)
                    ->overlapping($br2->check_in, $br2->check_out, RoomStatePeriod::KIND_MAINTENANCE)
                    ->exists()) {
                    throw new \Exception(
                        "ห้อง {$assignedRoom->room_number} อยู่ในช่วงซ่อมแซม (maintenance period) ทับช่วงเข้าพัก ".
                        "({$br2->check_in->toDateString()} – {$br2->check_out->toDateString()}) ".
                        'ไม่สามารถจัดเข้าพักได้ค่ะนายท่าน 🛠️',
                        422
                    );
                }

                $overlap = $assignedRoom->bookingRooms()
                    ->where('booking_rooms.id', '!=', $br2->id)
                    ->holdingSlot()
                    ->where('check_in', '<', $br2->check_out)
                    ->where('check_out', '>', $br2->check_in)
                    ->exists();
                if ($overlap) {
                    throw new \Exception(
                        "ห้องหมายเลข {$assignedRoom->room_number} มีการจองอื่นทับช่วงเข้าพัก ".
                        "({$br2->check_in->toDateString()} – {$br2->check_out->toDateString()}) ".
                        'ไม่สามารถจัดเข้าพักได้ค่ะนายท่าน กรุณาเลือกห้องอื่นนะคะ 🚪',
                        422
                    );
                }

                $br2->update(['room_id' => $assignedRoom->id]);
            } else {
                // Auto-allocate — allocate() type-hint Eloquent Collection (RoomAllocator.php:49)
                // ต้องครอบ instance จริงของ $br2 (loadMissing addon+roomType ทำงานกับตัว model ตัวเดิม)
                // ห้าม collect() (Support\Collection → TypeError) หรือ hydrate() (สำเนา detached — attribute หลุด)
                $allocatorResult = app(RoomAllocator::class)->allocate(new Collection([$br2]));
                if (! $allocatorResult->ok || empty($allocatorResult->assignments[$br2->id])) {
                    throw new \Exception(
                        "ไม่สามารถจัดหาห้องว่างสำหรับประเภทห้อง {$newRoomType->name_en} ในช่วงเวลาดังกล่าวได้ค่ะนายท่าน 😭",
                        422
                    );
                }
                $br2->update(['room_id' => $allocatorResult->assignments[$br2->id]]);
            }

            // 🌟 10. Reprice ผ่าน override — pin ทุก BR (มติ #10 scrutinize):
            $overrideMap = [];
            foreach ($locked->fresh()->bookingRooms as $r) {
                if ((string) $r->id === (string) $bookingRoom->id) {
                    $overrideMap[$r->id] = $roomAmount1;
                } elseif ((string) $r->id === (string) $br2->id) {
                    $overrideMap[$r->id] = $br2RoomAmount;
                } else {
                    $overrideMap[$r->id] = (int) $r->room_amount;
                }
            }

            app(DiscountService::class)->reprice($locked, $overrideMap);

            // 🌟 11. Money guard airtight (มติ #4 + #10 scrutinize):
            // เทียบส่วนค่าห้องรวมก่อนปัด: br1.room_amount_new + br2.room_amount ≥ room_amount₀ (exact integer)
            // ไม่เทียบ total หลัง RoundToTen เหมือน change-room เพื่อป้องกัน artifact จากการปัดเศษหลักสิบ
            if (($roomAmount1 + $br2RoomAmount) < $roomAmount0) {
                throw new \Exception(
                    'ส่วนต่างค่าห้องรวมหลังแบ่งช่วงพักต้องไม่ต่ำกว่าค่าห้องเดิมตามสัญญาค่ะนายท่าน 💰',
                    422
                );
            }

            // Ledger guard copy precedent (BookingController.php:920-934 / FrontDeskController.php:553-563)
            // 🌟 overpay-safe (scrutinize edge "บิล overpay"): บิลที่ paid > total+charges อยู่ก่อนแล้ว
            //    ต้องผ่าน ไม่ false-fire — split ลดยอดไม่ได้ (money guard ครอบ pair + BR อื่น freeze)
            //    จึงยิง 422 เฉพาะเมื่อสมดุลหลัง split (new+charges) แย่ลงกว่าก่อน split (previous+charges ≥ paid)
            $lockedFresh = $locked->fresh();
            $newTotal = (int) $lockedFresh->total_amount;
            $chargesTotal = (int) $lockedFresh->charges_total;
            $paidAmount = (int) $lockedFresh->paid_amount;

            if ($newTotal + $chargesTotal < $paidAmount && $previousTotal + $chargesTotal >= $paidAmount) {
                throw new \Exception(
                    'ไม่สามารถลดยอดรวมต่ำกว่าเงินที่ชำระเข้ามาแล้วได้ (ชำระแล้ว '.$paidAmount.' บาท ยอดใหม่ '.($newTotal + $chargesTotal).' บาท) — ระบบไม่มีการคืนเงินค่ะ 💳',
                    422
                );
            }

            if ($lockedFresh->is_paid && ($newTotal + $chargesTotal > $paidAmount)) {
                $lockedFresh->update(['is_paid' => false]);
            }

            // 🌟 12. Audit:
            // ภายใต้บิล confirmed ได้แถว machine draft→confirmed ของ BR-2 อัตโนมัติจาก transitionStatus แล้ว
            // เขียน note row from=to เพิ่ม 2 แถว:
            StatusChangeLog::create([
                'entity_type' => 'booking_room',
                'entity_id' => $bookingRoom->id,
                'from_status' => $bookingRoom->status,
                'to_status' => $bookingRoom->status,
                'role' => $user->role,
                'causer_id' => $user->id,
                'note' => "split-upgrade: check_out trimmed to {$splitDate->toDateString()}, price {$roomAmount0}→{$roomAmount1}",
            ]);

            $noteSuffix = ! empty($validated['notes']) ? ' · '.$validated['notes'] : '';
            StatusChangeLog::create([
                'entity_type' => 'booking_room',
                'entity_id' => $br2->id,
                'from_status' => $br2->status,
                'to_status' => $br2->status,
                'role' => $user->role,
                'causer_id' => $user->id,
                'note' => "split-upgrade: spawned from {$bookingRoom->id}, tier {$oldRoomType->name_en}→{$newRoomType->name_en}, {$pricePerNight} × {$n2} nights".$noteSuffix,
            ]);

            DB::commit();

            $surchargeAmount = $newTotal - $previousTotal;

            return response()->json([
                'status' => 'success',
                'message' => "แบ่งช่วงพักและอัปเกรดประเภทห้องเป็น {$newRoomType->name_en} เรียบร้อยแล้วค่ะนายท่าน ✨ ส่วนต่างราคาเข้ายอดค้างชำระ เก็บต่อผ่าน front-desk ได้ค่ะ 💳",
                'split_summary' => [
                    'br1_id' => $bookingRoom->id,
                    'br2_id' => $br2->id,
                    'br1_new' => [
                        'check_out' => $bookingRoom->fresh()->check_out->toDateString(),
                        'room_amount' => (int) $bookingRoom->fresh()->room_amount,
                    ],
                    'br2' => [
                        'check_in' => $br2->fresh()->check_in->toDateString(),
                        'check_out' => $br2->fresh()->check_out->toDateString(),
                        'room_type_id' => $br2->room_type_id,
                        'room_id' => $br2->fresh()->room_id,
                        'price_per_night' => $pricePerNight,
                        'nights' => $n2,
                    ],
                ],
                'previous_total_amount' => $previousTotal,
                'total_amount' => $newTotal,
                'surcharge_amount' => $surchargeAmount,
                'paid_amount' => (int) $lockedFresh->paid_amount,
                'outstanding_amount' => (int) $lockedFresh->outstanding_amount,
                'payment_type' => $lockedFresh->payment_type,
                'deposit_amount' => (int) $lockedFresh->deposit_amount,
            ], 200);

        } catch (ValidationException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลการจองหรือห้องพักที่ระบุค่ะนายท่าน 🥺',
            ], 404);
        } catch (\Throwable $e) {
            // 🌟 \Throwable ไม่ใช่ \Exception — TypeError/Error กลาง transaction ต้อง rollback เสมอ
            //    ไม่งั้น transaction ค้างเปิด (พิสูจน์แล้วใน test suite: "already an active transaction" cascade)
            DB::rollBack();
            $code = $e->getCode();
            if (in_array($code, [401, 403, 404, 422])) {
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ], $code);
            }

            Log::error('FrontDesk splitUpgrade error: '.$e->getMessage(), [
                'exception' => $e,
                'booking_id' => $bookingId,
                'booking_room_id' => $bookingRoomId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการแบ่งช่วงพัก กรุณาลองใหม่อีกครั้งค่ะนายท่าน 🥺',
            ], 500);
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

    /**
     * 🛎️ 6. Upload Check-in Evidence (REQ-033 — admin+staff)
     * อัปโหลดบัตร ปชช. / registration / PDPA ผูกกับ BookingRoom (เก็บ 365 วัน)
     */
    public function uploadEvidence(UploadCheckinEvidenceRequest $request, string $id)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่มีสิทธิ์อัปโหลดหลักฐานการเข้าพักค่ะนายท่าน (admin หรือ staff เท่านั้น)',
            ], 403);
        }

        try {
            $bookingRoom = BookingRoom::findOrFail($id);

            // 🛡️ State guard: อนุญาต confirmed, checked_in, checked_out
            // (draft ยังไม่ยืนยัน / no_show ไม่ได้เข้าพัก)
            if (! in_array($bookingRoom->status, ['confirmed', 'checked_in', 'checked_out'], true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => "ไม่สามารถอัปโหลดหลักฐานได้ค่ะนายท่าน เนื่องจากห้องมีสถานะ '{$bookingRoom->status}' (อนุญาตเฉพาะ confirmed, checked_in, checked_out เท่านั้น)",
                ], 422);
            }

            $validated = $request->validated();
            $file = $request->file('file');

            $storedPath = null;
            try {
                DB::beginTransaction();

                // 🖼️ บันทึกไฟล์บน private disk local (storage/app/private/checkin_evidences)
                $storedPath = $file->store('checkin_evidences', 'local');

                // 🖼️ morph evidences() ไปยัง images table
                $image = $bookingRoom->evidences()->create([
                    'path' => $storedPath,
                    'disk' => 'local',
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'original_name' => $file->getClientOriginalName(),
                    'uploaded_by' => $user->id,
                    'metadata' => [
                        'type' => $validated['type'],
                        'note' => $validated['note'] ?? null,
                    ],
                ]);

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'อัปโหลดหลักฐานการเข้าพักเรียบร้อยแล้วค่ะนายท่าน ✨',
                    'data' => [
                        'id' => $image->id,
                        'booking_room_id' => $bookingRoom->id,
                        'type' => $image->metadata['type'] ?? $validated['type'],
                        'metadata' => $image->metadata,
                        'file_name' => $image->original_name,
                        'mime_type' => $image->mime_type,
                        'size' => $image->size,
                        'url' => $image->url,
                        'created_at' => $image->created_at?->toISOString(),
                    ],
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                if ($storedPath !== null) {
                    Storage::disk('local')->delete($storedPath);
                }
                throw $e;
            }

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลห้องพักที่ระบุในระบบค่ะนายท่าน',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Upload check-in evidence failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการอัปโหลดหลักฐาน กรุณาลองใหม่อีกครั้งนะคะ 🥺',
            ], 500);
        }
    }

    /**
     * 🛎️ 7. Get Check-in Evidences (REQ-033 — admin+staff)
     * ดึงรายการหลักฐานการเข้าพักพร้อม signed URL (อายุ 15 นาที)
     */
    public function getEvidences(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่มีสิทธิ์เข้าถึงหลักฐานการเข้าพักค่ะนายท่าน (admin หรือ staff เท่านั้น)',
            ], 403);
        }

        try {
            $bookingRoom = BookingRoom::findOrFail($id);

            $evidences = $bookingRoom->evidences()
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn ($img) => [
                    'id' => $img->id,
                    'type' => $img->metadata['type'] ?? null,
                    'metadata' => $img->metadata,
                    'file_name' => $img->original_name,
                    'mime_type' => $img->mime_type,
                    'size' => $img->size,
                    'url' => $img->url,
                    'created_at' => $img->created_at?->toISOString(),
                ]);

            return response()->json([
                'status' => 'success',
                'booking_room_id' => $bookingRoom->id,
                'evidences' => $evidences,
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลห้องพักที่ระบุในระบบค่ะนายท่าน',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Get check-in evidences failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลหลักฐานค่ะ 🥺',
            ], 500);
        }
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
