<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UploadCheckinEvidenceRequest;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\HousekeepingTask;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\StatusChangeLog;
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
use Illuminate\Support\Facades\Storage;
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

    // 🛎️ 2. Check-In (BR-level)
    public function checkIn(Request $request, $bookingId)
    {
        // 🌟 Defense-in-depth: ตรวจ role ใน controller ด้วย ไม่พึ่ง middleware อย่างเดียว
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'staff'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'ต้องเป็นแอดมินหรือเจ้าหน้าที่เท่านั้นถึงจะ Check-in ได้ค่ะนายท่าน',
            ], 403);
        }

        if (is_string($request->input('assigned_rooms'))) {
            $request->merge([
                'assigned_rooms' => [$request->input('assigned_rooms')],
            ]);
        }
        $validated = $request->validate([
            'assigned_rooms' => 'nullable|array',
            'assigned_rooms.*' => 'required|uuid|exists:rooms,id',
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);

            $userId = $user->id;
            $userRole = $user->role;

            // จับคู่ห้องพัก
            if (! empty($validated['assigned_rooms'])) {
                $assignedRooms = $validated['assigned_rooms'];
                $bookingRooms = $booking->bookingRooms;

                if (count($assignedRooms) !== $bookingRooms->count()) {
                    throw new \Exception('จำนวนห้องที่ส่งมา ('.count($assignedRooms).') ไม่ตรงกับจำนวนห้องที่จองไว้ ('.$bookingRooms->count().') ค่ะนายท่าน');
                }

                foreach ($bookingRooms as $index => $bRoom) {
                    $assignedRoom = Room::lockForUpdate()->findOrFail($assignedRooms[$index]);

                    // ✅ #32 Fixed: ตรวจว่าห้องที่ assign ตรงกับ room type ที่จองไว้
                    if ($assignedRoom->room_type_id !== $bRoom->room_type_id) {
                        throw new \Exception(
                            "ห้องหมายเลข {$assignedRoom->room_number} (ประเภท: {$assignedRoom->roomType->name_en}) ".
                            'ไม่ตรงกับประเภทห้องที่จองไว้ค่ะนายท่าน กรุณาตรวจสอบอีกครั้งนะคะ'
                        );
                    }

                    // ⏱️ (2026-10-27) overlap guard — ห้าม assign ห้องที่มี BR อื่น (holdingSlot) ยึดทับช่วงพัก
                    //    ปิดช่องจองซ้ำตอนเช็คอินด้วยมือ (auto-assign มี allocator กันให้อยู่แล้ว)
                    $overlap = $assignedRoom->bookingRooms()
                        ->where('booking_rooms.id', '!=', $bRoom->id)
                        ->holdingSlot()
                        ->where('check_in', '<', $bRoom->check_out)
                        ->where('check_out', '>', $bRoom->check_in)
                        ->exists();
                    if ($overlap) {
                        throw new \Exception(
                            "ห้องหมายเลข {$assignedRoom->room_number} มีการจองอื่นทับช่วงเข้าพัก ".
                            "({$bRoom->check_in->toDateString()} – {$bRoom->check_out->toDateString()}) ".
                            'ไม่สามารถเช็คอินได้ค่ะนายท่าน กรุณาเลือกห้องอื่นด้วย assigned_rooms นะคะ',
                            422
                        );
                    }

                    $bRoom->update(['room_id' => $assignedRooms[$index]]);
                }

                $booking->load('bookingRooms');
            }

            $roomUpdates = [];

            foreach ($booking->bookingRooms as $bRoom) {
                if (! $bRoom->room_id) {
                    throw new \Exception("ไม่สามารถเช็คอินได้ค่ะ รายการจอง ID {$bRoom->id} ยังไม่ได้ระบุหมายเลขห้องพักค่ะนายท่าน");
                }

                $room = Room::lockForUpdate()->findOrFail($bRoom->room_id);

                // 🗓️ (24/09/26) room-state-periods: ห้องปลายทางมี maintenance period ทับช่วงพัก
                //    ของ BR → reject (reserved period **ไม่บล็อก** — booking include_reserved ของ
                //    admin ถูกต้องแล้ว · ฟรอนต์ย้ายแขกด้วย assigned_rooms ที่มีอยู่)
                //    (ปิดช่องเก่า "เช็คอินห้องสถานะ maintenance หลุดผ่าน" ด้วย — สถานะถูกถอดไปแล้ว)
                if (RoomStatePeriod::where('room_id', $room->id)
                    ->overlapping($bRoom->check_in, $bRoom->check_out, RoomStatePeriod::KIND_MAINTENANCE)
                    ->exists()) {
                    throw new \Exception(
                        "ห้อง {$room->room_number} อยู่ในช่วงซ่อมแซม (maintenance period) ทับช่วงเข้าพัก ".
                        "({$bRoom->check_in->toDateString()} – {$bRoom->check_out->toDateString()}) ไม่สามารถเช็คอินได้ค่ะนายท่าน กรุณาย้ายห้องด้วย assigned_rooms นะคะ",
                        422
                    );
                }

                // 🌟 ใช้ state machine เปลี่ยนสถานะห้อง
                $room->transitionStatusTo('occupied', $userId);

                // 🌟 Refactor (25/06/26): BR state machine → checked_in
                //    BR ต้องเป็น confirmed ก่อน — ถ้ายังเป็น draft ให้ confirmed ก่อน
                if ($bRoom->status === 'draft') {
                    $bRoom->transitionStatus('confirmed', $userRole);
                }
                $bRoom->transitionStatus('checked_in', $userRole);

                $roomUpdates[] = [
                    'room_number' => $room->room_number,
                    'new_status' => $room->status,
                ];
            }

            // 🌟 Fix scrutinize (29/06/26): ลบ bypass state machine
            // Booking container ต้องผ่าน flow ปกติ: draft → (record payment) → paid → (check-in) → confirmed
            // ห้ามข้ามขั้นตอนการชำระเงิน — staff ต้อง record payment ก่อนทุกครั้ง
            if ($booking->status === 'draft') {
                throw new \Exception(
                    'ไม่สามารถเช็คอินได้ค่ะนายท่าน เนื่องจากรายการจองยังไม่ได้รับชำระเงิน '.
                    'กรุณาบันทึกการรับชำระเงินก่อน (draft → paid) แล้วจึงกลับมาเช็คอินนะคะ'
                );
            } elseif ($booking->status === 'paid') {
                $booking->transitionStatus('confirmed', $userRole);
            } elseif ($booking->status !== 'confirmed') {
                throw new \Exception(
                    "ไม่สามารถเช็คอินได้ค่ะนายท่าน สถานะการจองปัจจุบันคือ '{$booking->status}' (ต้องเป็น paid หรือ confirmed เท่านั้นนะคะ)",
                    422
                );
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-in completed successfully! 🎉',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
                'room_updates' => $roomUpdates,
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
    //    เท่านั้น (upgrade-only) · ห้องเก่า → checkout_makeup + งานแม่บ้าน (mirror checkOut)
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
        ]);

        try {
            DB::beginTransaction();

            $booking = Booking::findOrFail($bookingId);

            // 🔒 BR ต้องอยู่ใต้ booking นี้จริง (ของ booking อื่น = 404)
            $bookingRoom = $booking->bookingRooms()->where('id', $bookingRoomId)->firstOrFail();

            // 🛡️ Guard: booking ต้อง confirmed (BR checked_in มาจาก flow check-in เสมอ)
            if ($booking->status !== 'confirmed') {
                throw new \Exception(
                    "ย้ายห้องได้เฉพาะรายการจองที่เข้าพักแล้ว (confirmed) เท่านั้นค่ะนายท่าน สถานะปัจจุบันคือ '{$booking->status}'",
                    422
                );
            }

            // 🛡️ Guard: BR ต้อง checked_in — ย้ายได้เฉพาะกลางทางเท่านั้น
            if ($bookingRoom->status !== 'checked_in') {
                throw new \Exception(
                    "ย้ายห้องได้เฉพาะห้องที่เช็คอินแล้ว (checked_in) เท่านั้นค่ะนายท่าน ห้องนี้สถานะ '{$bookingRoom->status}'",
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
            if ($locked->status !== 'confirmed') {
                throw new \Exception(
                    "ย้ายห้องได้เฉพาะรายการจองที่เข้าพักแล้ว (confirmed) เท่านั้นค่ะนายท่าน สถานะปัจจุบันคือ '{$locked->status}'",
                    422
                );
            }

            $oldRoom = Room::lockForUpdate()->findOrFail($bookingRoom->room_id);
            $newRoom = Room::with('roomType')->lockForUpdate()->findOrFail($validated['room_id']);

            // 🛡️ ห้องใหม่ต้องพร้อมเข้าพัก — transition → occupied ถูกกฎ state machine เสมอ
            if (! in_array($newRoom->status, ['available', 'prep_checkin'])) {
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

            // 🎫 กติกา upgrade-only — type ใหม่ต้องแพงกว่าหรือเท่ากัน
            //    เทียบ effective daily rate ของเจ้าของบิล (บริบทเดียวกับ reprice — ku_member ใช้ daily_ku)
            if ($typeChanged) {
                $oldRate = GlobalRate::getEffectiveDailyRate($oldRoomType, $locked->user);
                $newRate = GlobalRate::getEffectiveDailyRate($newRoomType, $locked->user);
                if ($newRate < $oldRate) {
                    throw new \Exception(
                        'เปลี่ยนประเภทห้องได้เฉพาะประเภทที่แพงกว่าเท่านั้นค่ะนายท่าน '.
                        "({$oldRoomType->name_en} {$oldRate} → {$newRoomType->name_en} {$newRate} บาท/คืน) — ระบบไม่อนุญาต downgrade ค่ะ 🎫",
                        422
                    );
                }
            }

            // 🚪 Commit การย้าย — BR คงสถานะ checked_in (สถานะไม่เปลี่ยน จึงไม่ผ่าน transitionStatus)
            $bookingRoom->update([
                'room_id' => $newRoom->id,
                'room_type_id' => $newRoom->room_type_id,
            ]);

            // 🧹 ห้องเก่า → checkout_makeup + งานแม่บ้าน (mirror checkOut — แขกย้ายออก = ห้องต้องเก็บ)
            //    duplicate-guard: ถ้ามี active task อยู่แล้วไม่สร้างซ้ำ (Fix S-B2 pattern)
            $oldRoom->transitionStatusTo('checkout_makeup', $user->id);
            $activeExists = HousekeepingTask::where('room_id', $oldRoom->id)
                ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])
                ->exists();

            $housekeepingTaskId = null;
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

            // 🌟 ห้องใหม่ → occupied
            $newRoom->transitionStatusTo('occupied', $user->id);

            // 💰 Reprice ทั้งบิล (full-stay) — chokepoint เดียวกับ surcharge (ticket 10)
            //    type ใหม่แพงขึ้น = ส่วนต่างเข้า outstanding เก็บต่อผ่าน front-desk / check-out
            app(DiscountService::class)->reprice($locked);

            // 🎫 airtight — type เปลี่ยนแล้วยอดรวมต้องไม่ต่ำกว่าเดิม (กัน group/month tier พายอดตก
            //    แม้เรทรายวันแพงขึ้น)
            $newTotal = (int) $locked->fresh()->total_amount;
            if ($typeChanged && $newTotal < $previousTotal) {
                throw new \Exception(
                    "อัปเกรดประเภทห้องแล้วยอดรวมต้องไม่ต่ำกว่าเดิมค่ะ ({$previousTotal} → {$newTotal} บาท) กรุณาตรวจสอบเรทของประเภทห้องนะคะ",
                    422
                );
            }

            // 💳 money guards — pattern เดียวกับ updateRoom (ticket 10 + ticket 13):
            //    ยอดรวมใหม่ (รวม charges) ต้องไม่ต่ำกว่าเงินที่เข้า ledger · is_paid=true เดิม
            //    แต่เกิดยอดค้าง → reset false (recordPayment/check-out เก็บส่วนต่างต่อ)
            $chargesTotal = (int) $locked->charges_total;
            $paidAmount = $locked->paid_amount;
            if ($newTotal + $chargesTotal < $paidAmount) {
                throw new \Exception(
                    'ไม่สามารถลดยอดรวมต่ำกว่าเงินที่ชำระเข้ามาแล้วได้ (ชำระแล้ว '.$paidAmount.' บาท ยอดใหม่ '.($newTotal + $chargesTotal).' บาท) — ระบบไม่มีการคืนเงินค่ะ 💳',
                    422
                );
            }
            if ($locked->is_paid && $newTotal + $chargesTotal > $paidAmount) {
                $locked->update(['is_paid' => false]);
            }

            // 📝 Audit — note row ของ BR (from=to=checked_in · note เล่าการย้าย)
            //    (2026-11-04) convention extension: row นี้เขียนตรงใน controller ไม่ผ่าน
            //    transitionStatus เพราะสถานะ BR ไม่เปลี่ยน — note column ถูกใช้เป็นครั้งแรก
            //    · room transitions ถูก log เองผ่าน Room::transitionStatusTo (entity_type=room)
            StatusChangeLog::create([
                'entity_type' => 'booking_room',
                'entity_id' => $bookingRoom->id,
                'from_status' => 'checked_in',
                'to_status' => 'checked_in',
                'role' => $user->role,
                'causer_id' => $user->id,
                'note' => 'ย้ายห้อง '.$oldRoom->room_number.' → '.$newRoom->room_number
                    .($typeChanged ? ' · type '.$oldRoomType->name_en.' → '.$newRoomType->name_en : ''),
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'ย้ายห้องเรียบร้อยแล้วค่ะนายท่าน 🚪✨ ('.$oldRoom->room_number.' → '.$newRoom->room_number.')'
                    .($typeChanged ? ' อัปเกรดประเภทห้องเป็น '.$newRoomType->name_en.' — ส่วนต่างเข้ายอดค้างชำระ เก็บต่อผ่าน front-desk ได้ค่ะ 💳' : ''),
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

    // 🧹 3. Check-Out (BR-level)
    public function checkOut(Request $request, $bookingId)
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

            $booking = Booking::with('bookingRooms')->findOrFail($bookingId);

            // 🌟 Refactor (25/06/26): เช็คที่ BR-level ว่าทุกห้อง checked_in หรือไม่
            $allCheckedIn = $booking->bookingRooms->every(fn ($br) => $br->status === 'checked_in');
            if (! $allCheckedIn) {
                throw new \Exception('ยังมีห้องที่ไม่ได้ Check-in อยู่ค่ะ จะ Check-out ไม่ได้น้า');
            }

            // ตรวจสอบยอดชำระเงิน
            $totalPaid = Payment::where('booking_id', $bookingId)
                ->where('status', 'completed')
                ->sum('amount');

            $grandTotal = $booking->total_amount + $booking->charges_total;

            if ($totalPaid < $grandTotal) {
                $pendingAmount = $grandTotal - $totalPaid;
                throw new \Exception("ยังมีรายการค้างชำระอยู่ {$pendingAmount} บาทค่ะนายท่าน กรุณารับชำระเงินก่อนนะคะ");
            }

            $roomUpdates = [];

            foreach ($booking->bookingRooms as $bRoom) {
                if (! $bRoom->room_id) {
                    continue;
                }

                $room = Room::findOrFail($bRoom->room_id);

                // 🌟 Refactor (25/06/26): BR state machine → checked_out
                $bRoom->transitionStatus('checked_out', $user->role);

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

                $roomUpdates[] = [
                    'room_number' => $room->room_number,
                    'room_status' => $room->status,
                    'housekeeping_task_id' => $task?->id,
                ];
            }

            // 🌟 Auto-sync booking container → complete ถ้า BR ทุกห้องจบแล้ว
            $booking->syncStatusFromRooms();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Check-out completed successfully. สร้างงานให้ทีมแม่บ้านเรียบร้อยค่ะ!',
                'booking_id' => $booking->id,
                'booking_status' => $booking->status,
                'room_updates' => $roomUpdates,
            ], 200);

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
