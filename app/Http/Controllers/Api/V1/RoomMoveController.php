<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\DiscountRedemption;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\StatusChangeLog;
use App\Services\Discount\DiscountService;
use App\Services\RoomAllocator\RoomAllocator;
use App\Support\BookingRule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoomMoveController extends Controller
{
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

            // 🛡️ Guard: BR ต้อง checked_in หรือ confirmed หรือ draft (ที่มีเลขห้องผูกไว้แล้ว — tier-endpoints-v2 มติ v2 ข้อ 7)
            if (! in_array($bookingRoom->status, ['draft', 'confirmed', 'checked_in'], true)) {
                throw new \Exception(
                    "ย้ายห้องได้เฉพาะห้องที่มีเลขห้องแล้ว (สถานะ draft หรือ confirmed ก่อนเช็คอิน หรือ checked_in ที่เข้าพักแล้ว) เท่านั้นค่ะนายท่าน ห้องนี้สถานะ '{$bookingRoom->status}' 🚪",
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

        // 🌟 1. Load + scope: บิลจาก bookingId, BR จากบิลนั้นเท่านั้น (404 ข้ามบิล — pattern :175-176)
        $booking = Booking::findOrFail($bookingId);
        $bookingRoom = $booking->bookingRooms()->where('id', $bookingRoomId)->firstOrFail();

        // 🚪 tier-endpoints-v2 (มติ v2 ข้อ 5): ตรวจสอบพารามิเตอร์ที่ตัดออกก่อน validate อื่นทั้งหมด
        if ($request->has('room_type_id') || $request->has('price_per_night')) {
            return response()->json([
                'status' => 'error',
                'message' => 'การแบ่งช่วงพักเป็นแบบประเภทห้องเดิม (same-tier) เท่านั้น ไม่รองรับ room_type_id หรือ price_per_night แล้วค่ะนายท่าน — หากต้องการเปลี่ยนประเภทห้อง กรุณาใช้ PUT โหมด 2 หรือ change-room แทนนะคะ 🚪',
            ], 422);
        }

        $validated = $request->validate([
            'split_date' => 'required|date',
            'room_id' => 'nullable|uuid|exists:rooms,id',
            'notes' => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

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

            // 🌟 4. Same-tier split (มติ v2 ข้อ 5):
            $roomType = RoomType::findOrFail($bookingRoom->room_type_id);
            $roomAmount0 = (int) $bookingRoom->room_amount;
            $previousTotal = (int) $locked->total_amount;
            $br1RoomAmount = intdiv($roomAmount0 * $n1, $n0);
            $br2RoomAmount = $roomAmount0 - $br1RoomAmount;

            // 🌟 5. Trim BR-1: check_out = split_date (ห้ามเปลี่ยน status ตามมติ #3)
            $bookingRoom->update([
                'check_out' => $splitDate->toDateString(),
            ]);

            // 🌟 6. สร้าง BR-2 (same-tier):
            $guests = $bookingRoom->guests;
            $bedPreference = $bookingRoom->bed_preference;
            if ($bedPreference !== null) {
                $hasBedOption = Room::where('room_type_id', $roomType->id)
                    ->where('bed_type', $bedPreference)
                    ->exists();
                if (! $hasBedOption) {
                    $bedPreference = null;
                }
            }

            // สร้าง status 'draft' เสมอ แล้วภายใต้บิล confirmed เรียก transitionStatus('confirmed', $userRole)
            $br2 = BookingRoom::create([
                'booking_id' => $locked->id,
                'room_type_id' => $roomType->id,
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

            // 🌟 7. Addon carry + re-slice (มติ #7):
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
                        if ($roomType->max_extra_beds !== null && $qty > $roomType->max_extra_beds) {
                            throw new \Exception(
                                "จำนวนเตียงเสริมคืน {$dateKey} ({$qty} เตียง) เกินขีดสุดของประเภทห้อง {$roomType->name_en} (สูงสุด {$roomType->max_extra_beds} ต่อคืน) ค่ะ 🛏️",
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

            // 🌟 8. จัดห้อง BR-2 (มติ v2 ข้อ 5):
            $hasPin = ! empty($validated['room_id']);

            if ($bookingRoom->room_id !== null) {
                // กรณี BR-1 มี room_id → BR-2 ต้องได้เลขห้องใหม่ต่างจาก BR-1 type เดียวกัน
                if ($hasPin) {
                    $assignedRoom = Room::with('roomType')->lockForUpdate()->findOrFail($validated['room_id']);

                    // Pin check 1: ตรวจว่า ≠ ห้อง BR-1 (422 ถ้าซ้ำ — ตรวจก่อนเช็คอื่น)
                    if ((string) $assignedRoom->id === (string) $bookingRoom->room_id) {
                        throw new \Exception(
                            "ห้องหมายเลข {$assignedRoom->room_number} เป็นห้องเดิมของช่วงแรก กรุณาเลือกห้องอื่นที่ต่างจากเดิมค่ะนายท่าน 🚪",
                            422
                        );
                    }

                    // Pin check 2: ตรวจว่า type ตรงกับ tier เดิม (422 ถ้าผิด type)
                    if ((string) $assignedRoom->room_type_id !== (string) $roomType->id) {
                        throw new \Exception(
                            "ห้องหมายเลข {$assignedRoom->room_number} (ประเภท: {$assignedRoom->roomType->name_en}) ".
                            "ไม่ตรงกับประเภทห้องเดิม ({$roomType->name_en}) ค่ะนายท่าน กรุณาตรวจสอบอีกครั้งนะคะ 🏨",
                            422
                        );
                    }

                    // ตรวจความพร้อมของห้อง
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
                    // ไม่ pin → auto-allocate ห้องเดียว exclude ห้องของ BR-1
                    $allocatorResult = app(RoomAllocator::class)->allocate(new Collection([$br2]), false, [$bookingRoom->room_id]);
                    if (! $allocatorResult->ok || empty($allocatorResult->assignments[$br2->id])) {
                        throw new \Exception(
                            "ไม่สามารถจัดหาห้องว่างสำหรับประเภทห้อง {$roomType->name_en} ในช่วงเวลาดังกล่าวได้ค่ะนายท่าน 😭",
                            422
                        );
                    }
                    $br2->update(['room_id' => $allocatorResult->assignments[$br2->id]]);
                }
            } else {
                // กรณี BR-1 ไม่มี room_id:
                if ($hasPin) {
                    $assignedRoom = Room::with('roomType')->lockForUpdate()->findOrFail($validated['room_id']);

                    if ((string) $assignedRoom->room_type_id !== (string) $roomType->id) {
                        throw new \Exception(
                            "ห้องหมายเลข {$assignedRoom->room_number} (ประเภท: {$assignedRoom->roomType->name_en}) ".
                            "ไม่ตรงกับประเภทห้องเดิม ({$roomType->name_en}) ค่ะนายท่าน กรุณาตรวจสอบอีกครั้งนะคะ 🏨",
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
                    // ไม่ pin → room_id = null ไม่ allocate
                }
            }

            // 🌟 9. Reprice ผ่าน override — pin ทุก BR
            $overrideMap = [];
            foreach ($locked->fresh()->bookingRooms as $r) {
                if ((string) $r->id === (string) $bookingRoom->id) {
                    $overrideMap[$r->id] = $br1RoomAmount;
                } elseif ((string) $r->id === (string) $br2->id) {
                    $overrideMap[$r->id] = $br2RoomAmount;
                } else {
                    $overrideMap[$r->id] = (int) $r->room_amount;
                }
            }

            app(DiscountService::class)->reprice($locked, $overrideMap);

            $lockedFresh = $locked->fresh();
            $newTotal = (int) $lockedFresh->total_amount;

            // 🌟 10. Guard invariant (มติ v2 ข้อ 5): total หลัง split == total ก่อนเป๊ะ (surcharge 0)
            if ($newTotal !== $previousTotal) {
                throw new \Exception(
                    "Invariant violation: total_amount หลังแบ่งช่วงพักต้องเท่ากับยอดเดิมเป๊ะ ({$previousTotal} !== {$newTotal}) 💥",
                    500
                );
            }

            // 🌟 11. Audit:
            StatusChangeLog::create([
                'entity_type' => 'booking_room',
                'entity_id' => $bookingRoom->id,
                'from_status' => $bookingRoom->status,
                'to_status' => $bookingRoom->status,
                'role' => $user->role,
                'causer_id' => $user->id,
                'note' => "split: check_out trimmed to {$splitDate->toDateString()}, price {$roomAmount0}→{$br1RoomAmount}",
            ]);

            $oldRoomNumber = Room::find($bookingRoom->room_id)?->room_number;
            $newRoomNumber = Room::find($br2->fresh()->room_id)?->room_number;
            $roomChangeNote = ($oldRoomNumber && $newRoomNumber) ? " · room {$oldRoomNumber}→{$newRoomNumber}" : '';
            $noteSuffix = ! empty($validated['notes']) ? ' · '.$validated['notes'] : '';

            StatusChangeLog::create([
                'entity_type' => 'booking_room',
                'entity_id' => $br2->id,
                'from_status' => $br2->status,
                'to_status' => $br2->status,
                'role' => $user->role,
                'causer_id' => $user->id,
                'note' => "split: spawned from {$bookingRoom->id}, tier {$roomType->name_en}, {$n2} nights{$roomChangeNote}".$noteSuffix,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'แบ่งช่วงพักเรียบร้อยแล้วค่ะนายท่าน ✨ ยอดรวมคงเดิมไม่มีส่วนต่างราคาค่ะ 🏨',
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
                        'room_id' => $br2->fresh()->room_id,
                        'nights' => $n2,
                    ],
                ],
                'previous_total_amount' => $previousTotal,
                'total_amount' => $newTotal,
                'surcharge_amount' => 0,
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
