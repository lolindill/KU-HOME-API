<?php

namespace App\Services\RoomStatePeriod;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\StatusChangeLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 🗓️ RoomStatePeriodService — domain logic ของ period (สร้าง/แก้/ลบ)
 *
 *    (wayfinder/room-state-periods tickets 02+04+05, 2026-09-24 — controller บาง ไว้ที่นี่
 *     ตาม precedent DiscountService)
 *
 *    กฎที่ implement:
 *    - same-kind overlap → **auto-merge**: row เดิม (id/created_by คงเดิม) ถูกขยายครอบ union
 *      · cascade จนไม่เหลือ same-kind overlap (invariant: DB ไม่มี same-kind ทับกันเอง
 *        → one-pass พอ — row ใด ๆ ที่จะทับ union ต้องทับช่วงที่ขอ หรือทับ row ที่ถูก absorb ไปแล้ว)
 *    - ช่วงทับ maintenance ชนะทุก semantics — ที่นี่ไม่ต้องทำอะไร (availability/gate ตัดเอง)
 *    - **booking effects บน "วันที่ครอบใหม่" เท่านั้น** (ticket 04 — หดวัน = ไม่แตะ booking
 *      ที่ค้างในช่วงเดิมเด็ดขาด): draft booking ที่ BR ของห้องนี้ overlap → ลบทันที
 *      (mechanism เดียวกับ BookingController::destroyBooking + audit draft → deleted)
 *      · confirmed/checked_in → ไม่แตะ แค่รายงาน affected_bookings
 *    - audit ทุกเหตุการณ์ → status_change_logs entity_type 'room_state_period'
 *      (from/to = ช่วงวันที่ string "YYYY-MM-DD..YYYY-MM-DD|NULL" · note = เหตุการณ์)
 */
final class RoomStatePeriodService
{
    /**
     * สร้าง period (auto-merge same-kind ถ้าชน) + booking effects บน union window
     *
     * @param  Carbon  $start  วันเริ่ม (ย้อนอดีตได้)
     * @param  Carbon|null  $end  วันจบ exclusive — null = เปิดปลาย
     * @return array{period: RoomStatePeriod, merged: bool, deleted_drafts: string[], affected_bookings: array<int, array>}
     */
    public function create(Room $room, string $kind, Carbon $start, ?Carbon $end, User $causer): array
    {
        return DB::transaction(function () use ($room, $kind, $start, $end, $causer) {
            $this->assertWindow($start, $end);

            $overlaps = $room->periods()->where('kind', $kind)
                ->overlapping($start, $end)
                ->orderBy('start_date')
                ->get();

            // ไม่ชนใคร → row ใหม่ ตรง ๆ
            if ($overlaps->isEmpty()) {
                $period = $room->periods()->create([
                    'kind' => $kind,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end?->toDateString(),
                    'created_by' => $causer->id,
                ]);
                $this->audit($period, null, $period->windowString(), 'created', $causer);

                // วันที่ครอบใหม่ = ทั้ง window
                $effects = $this->applyBookingEffects($room, [[$start->copy(), $end?->copy()]], $kind, $causer);

                return ['period' => $period, 'merged' => false] + $effects;
            }

            // auto-merge — row เดิม (start เร็วสุด) ขยายครอบ union (id/created_by เดิมคงอยู่)
            $target = $overlaps->first();
            $oldWindow = $target->windowString();

            $unionStart = $start->lt($target->start_date) ? $start->copy() : $target->start_date->copy();
            $unionEnd = $end?->copy();
            foreach ($overlaps as $row) {
                if ($row->end_date === null) {
                    $unionEnd = null; // union กับ ∞ = ∞ (ticket 05)
                } elseif ($unionEnd !== null && $row->end_date->gt($unionEnd)) {
                    $unionEnd = $row->end_date->copy();
                }
            }

            // ช่วงใหม่ซ้อนข้างในจน window ไม่ขยาย → ตอบ row เดิมตามสภาพ ไม่มีการเขียน (ticket 04)
            $windowChanged = $target->windowString() !== $unionStart->toDateString().'..'.($unionEnd?->toDateString() ?? 'NULL');
            if ($windowChanged) {
                $target->start_date = $unionStart->toDateString();
                $target->end_date = $unionEnd?->toDateString();
                $target->save();
                $this->audit($target, $oldWindow, $target->windowString(), 'merged', $causer);
            }
            $unionStart = $target->start_date->copy();
            $unionEnd = $target->end_date?->copy();

            // rows อื่นที่ถูก absorb → จด audit ก่อนแล้วลบ (cascade — ใน DB เหลือ row เดียว)
            foreach ($overlaps->reject(fn (RoomStatePeriod $p) => $p->id === $target->id) as $absorbed) {
                $this->audit($absorbed, $absorbed->windowString(), $target->windowString(), 'merged', $causer);
                $absorbed->delete();
            }

            // merge case → effects ทั้ง union window (รวมวันเดิมที่เคยครอบ — ค้นซ้ำได้เพราะ
            // draft ในช่วงเดิมถูกลบไปแล้วตาม invariant, confirmed รายงานซ้ำเป็นข้อมูลที่ดี)
            $effects = $this->applyBookingEffects($room, [[$unionStart, $unionEnd]], $kind, $causer);

            return ['period' => $target->fresh(), 'merged' => true] + $effects;
        });
    }

    /**
     * แก้ช่วงวันที่ (kind immutable — FormRequest prohibited) — กฎครบชุดเดียวกับ create
     *
     * @param  array{start_date?: string, end_date?: string|null}  $changes  จาก validated()
     *                                                                       (key absent = คงเดิม · end_date = null explicit = เปิดปลาย)
     * @return array{period: RoomStatePeriod, merged: bool, deleted_drafts: string[], affected_bookings: array<int, array>}
     */
    public function update(RoomStatePeriod $period, array $changes, User $causer): array
    {
        return DB::transaction(function () use ($period, $changes, $causer) {
            $oldStart = $period->start_date->copy();
            $oldEnd = $period->end_date?->copy();

            $newStart = isset($changes['start_date']) ? Carbon::parse($changes['start_date']) : $oldStart->copy();
            $newEnd = array_key_exists('end_date', $changes)
                ? ($changes['end_date'] !== null ? Carbon::parse($changes['end_date']) : null)
                : $oldEnd?->copy();

            $this->assertWindow($newStart, $newEnd);

            $newWindow = $newStart->toDateString().'..'.($newEnd?->toDateString() ?? 'NULL');
            $noop = $newWindow === $period->windowString();

            $overlaps = $period->room->periods()->where('kind', $period->kind)
                ->where('id', '!=', $period->id)
                ->overlapping($newStart, $newEnd)
                ->orderBy('start_date')
                ->get();

            if ($noop && $overlaps->isEmpty()) {
                // ช่วงเท่าเดิมและไม่ชนใคร — ตอบ row ตามสภาพ ไม่เขียนอะไร (ticket 04)
                return ['period' => $period, 'merged' => false, 'deleted_drafts' => [], 'affected_bookings' => []];
            }

            if ($overlaps->isEmpty()) {
                $period->start_date = $newStart->toDateString();
                $period->end_date = $newEnd?->toDateString();
                $period->save();

                // ขยาย/ขยับ — effects เฉพาะวันที่**เพิ่ม**มาจากเดิม (หดวัน = ไม่แตะ booking เดิม)
                $segments = $this->newlyCoveredSegments($oldStart, $oldEnd, $newStart, $newEnd);
                $this->audit($period, $oldStart->toDateString().'..'.($oldEnd?->toDateString() ?? 'NULL'), $period->windowString(), empty($segments) ? 'shortened' : 'extended', $causer);
                $effects = $this->applyBookingEffects($period->room, $segments, $period->kind, $causer);

                return ['period' => $period->fresh(), 'merged' => false] + $effects;
            }

            // merge — row ที่แก้เป็น target (id เดิมคงอยู่) absorb ทุก row ที่ชน
            $unionStart = $newStart->lt($overlaps->first()->start_date) ? $newStart->copy() : $overlaps->first()->start_date->copy();
            $unionEnd = $newEnd?->copy();
            foreach ($overlaps as $row) {
                if ($row->end_date === null) {
                    $unionEnd = null;
                } elseif ($unionEnd !== null && $row->end_date->gt($unionEnd)) {
                    $unionEnd = $row->end_date->copy();
                }
            }

            $oldSelfWindow = $period->windowString();
            $period->start_date = $unionStart->toDateString();
            $period->end_date = $unionEnd?->toDateString();
            $period->save();
            $this->audit($period, $oldSelfWindow, $period->windowString(), 'merged', $causer);

            foreach ($overlaps as $absorbed) {
                $this->audit($absorbed, $absorbed->windowString(), $period->windowString(), 'merged', $causer);
                $absorbed->delete();
            }

            $effects = $this->applyBookingEffects($period->room, [[$unionStart, $unionEnd]], $period->kind, $causer);

            return ['period' => $period->fresh(), 'merged' => true] + $effects;
        });
    }

    /**
     * ยกเลิก period — hard delete + เขียน audit log ก่อนลบ (row คือตารางเวลา ไม่ใช่หลักฐานการเงิน)
     * ไม่มีผลข้างเคียงกับ booking (เพียงปล่อยห้องคืน — derived ตอน query)
     */
    public function delete(RoomStatePeriod $period, User $causer): void
    {
        DB::transaction(function () use ($period, $causer) {
            $this->audit($period, $period->windowString(), 'deleted', 'deleted', $causer);
            $period->delete();
        });
    }

    // =========================================================
    // 🔒 internals
    // =========================================================

    /**
     * cross-field validation ที่ FormRequest ทำไม่ได้ (PATCH เทียบกับค่าเดิมใน DB ด้วย)
     */
    private function assertWindow(Carbon $start, ?Carbon $end): void
    {
        if ($end !== null && $end->lte($start)) {
            throw new \Exception('end_date ต้องมากกว่า start_date ค่ะนายท่าน (end เป็น exclusive — วันที่ห้องกลับมาขาย)', 422);
        }
    }

    /**
     * คำนวณช่วงวันที่ "เพิ่ม" จากเดิม — คืน list ของ [from, to) (to = null คือปลายเปิด)
     * ช่วงใหม่ถูกเดิมครอบหมด → [] (shrink/move-inside — ไม่มีวันใหม่)
     *
     * @return array<int, array{0: Carbon, 1: Carbon|null}>
     */
    private function newlyCoveredSegments(Carbon $oldStart, ?Carbon $oldEnd, Carbon $newStart, ?Carbon $newEnd): array
    {
        $coversAll = $oldStart->lte($newStart)
            && ($oldEnd === null || ($newEnd !== null && $newEnd->lte($oldEnd)));
        if ($coversAll) {
            return [];
        }

        $segments = [];

        // ส่วนหัว — วันใหม่ที่เริ่มก่อนช่วงเดิม
        if ($newStart->lt($oldStart)) {
            $headTo = ($newEnd !== null && $newEnd->lt($oldStart)) ? $newEnd->copy() : $oldStart->copy();
            if ($newStart->lt($headTo)) {
                $segments[] = [$newStart->copy(), $headTo];
            }
        }

        // ส่วนท้าย — วันใหม่หลังจบช่วงเดิม (ช่วงเดิมเปิดปลาย = ไม่มีท้ายให้ขยาย)
        if ($oldEnd !== null && ($newEnd === null || $oldEnd->lt($newEnd))) {
            $tailFrom = $oldEnd->gt($newStart) ? $oldEnd->copy() : $newStart->copy();
            $segments[] = [$tailFrom, $newEnd?->copy()];
        }

        return $segments;
    }

    /**
     * booking effects บนช่วงวันที่ครอบใหม่ (ticket 02):
     * - draft (BR + container ยัง draft) ที่ BR ของห้องนี้ overlap → ลบทันที + audit draft → deleted
     * - confirmed/checked_in → ไม่แตะ — รวมเป็น affected_bookings ให้ admin เก็บงานเอง
     *
     * @param  array<int, array{0: Carbon, 1: Carbon|null}>  $segments
     * @return array{deleted_drafts: string[], affected_bookings: array<int, array>}
     */
    private function applyBookingEffects(Room $room, array $segments, string $kind, User $causer): array
    {
        $draftBookingIds = [];
        $affectedBookings = [];

        foreach ($segments as [$from, $to]) {
            if ($from === null) {
                continue;
            }

            $query = BookingRoom::where('room_id', $room->id)
                ->holdingSlot()
                // overlap half-open เดียวกับที่ period ใช้: start < to AND end > from
                ->where('check_out', '>', $from->toDateString());
            if ($to !== null) {
                $query->where('check_in', '<', $to->toDateString());
            }

            foreach ($query->with('booking')->get() as $br) {
                $booking = $br->booking;
                if ($booking === null) {
                    continue;
                }

                if ($br->status === 'draft' && $booking->status === 'draft') {
                    $draftBookingIds[$booking->id] = true;
                } elseif (in_array($br->status, ['confirmed', 'checked_in'], true) && ! isset($affectedBookings[$br->booking_id])) {
                    $affectedBookings[$br->booking_id] = [
                        'booking_id' => $booking->id,
                        'confirmation_number' => $booking->confirmation,
                        'status' => $br->status,
                        'check_in' => $br->check_in->toDateString(),
                        'check_out' => $br->check_out->toDateString(),
                    ];
                }
            }
        }

        $deletedDrafts = [];
        foreach (array_keys($draftBookingIds) as $bookingId) {
            if ($this->deleteDraftBooking($bookingId, $kind, $causer)) {
                $deletedDrafts[] = $bookingId;
            }
        }

        return ['deleted_drafts' => $deletedDrafts, 'affected_bookings' => array_values($affectedBookings)];
    }

    /**
     * ลบ draft booking — mechanism เดียวกับ BookingController::destroyBooking
     * (lock + re-check กัน race กับ confirm/verify · cascade addon/payment/confirmation+slip · audit)
     */
    private function deleteDraftBooking(string $bookingId, string $kind, User $causer): bool
    {
        $locked = Booking::where('id', $bookingId)->lockForUpdate()->first();

        if ($locked === null || $locked->status !== 'draft') {
            return false; // โดน race (เช่น verify พร้อมกัน) — ปล่อยเป็น affected รอบหน้า
        }

        foreach ($locked->bookingRooms as $br) {
            $br->addon()?->delete();
            $br->delete();
        }
        // frozen legacy table — ลบทิ้งเหมือน CleanupExpiredDrafts
        $locked->payments()->delete();
        // defense-in-depth: draft ไม่ควรมี confirmation — แต่ลบรูปสลิปก่อนเสมอ (morph hook ลบไฟล์)
        $locked->confirmations->each(fn ($confirmation) => $confirmation->slipImage?->delete());
        $locked->confirmations()->delete();

        StatusChangeLog::create([
            'entity_type' => 'booking',
            'entity_id' => $locked->id,
            'from_status' => 'draft',
            'to_status' => 'deleted',
            'role' => $causer->role ?? 'system',
            'causer_id' => $causer->id,
            'note' => "ถูกลบอัตโนมัติ: room_state_period (kind={$kind}) ครอบช่วงที่ draft นี้ overlap",
        ]);

        $locked->delete();

        return true;
    }

    /**
     * audit log ของ period — from/to เก็บ "ช่วงวันที่" ไม่ใช่สถานะ
     * (create ไม่มีช่วงเดิม = '-' · delete = to_status 'deleted' — log ถูกเขียนก่อนลบ row)
     */
    private function audit(RoomStatePeriod $period, ?string $from, string $to, string $note, User $causer): void
    {
        StatusChangeLog::create([
            'entity_type' => 'room_state_period',
            'entity_id' => $period->id,
            'from_status' => $from ?? '-',
            'to_status' => $to,
            'role' => $causer->role ?? 'system',
            'causer_id' => $causer->id,
            'note' => $note,
        ]);
    }
}
