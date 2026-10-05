<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

class BookingRoom extends Model
{
    use HasFactory, HasUuids;

    /**
     * 🌟 Refactor (25/06/26): Booking Room State Machine
     *    - เก็บ check_in/check_out + status รายห้อง
     *    - State: draft → confirmed → {checked_in → checked_out | no_show}
     */
    protected $fillable = [
        'booking_id',
        'room_type_id',
        'room_id',     // nullable — assign ตอน check-in
        'check_in',    // ย้ายมาจาก bookings (แต่ละห้องมีวันที่ต่างกันได้)
        'check_out',
        'guests',      // JSON: [{ title, name, firstName, lastName, email, phone, nationality }, ...]
        'status',      // draft | confirmed | checked_in | checked_out | no_show
        // 🏨 bed_preference สำหรับ Room Allocation Algorithm (king_size = ชั้น 8 | null=any)
        'bed_preference',
        // 🧾 Billing fields (04/08/26): ที่อยู่ + หมายเหตุใบกำกับภาษีระดับห้อง
        'billing_address',
        'billing_comment',
        // 🎟️ Discount fields (27/08/26): ค่าห้องก่อนลด และ ส่วนลดของห้องนี้ (integer บาท)
        'room_amount',
        'discount_amount',
        // 🧾 Net total ต่อห้อง (03/09/26): room_amount − discount_amount + addon รวมทุกอย่าง (integer บาท)
        //    invariant Σ booking_rooms.amount == bookings.total_amount (บังคับด้วย test)
        'amount',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'guests' => 'array',
        'room_amount' => 'integer',
        'discount_amount' => 'integer',
        'amount' => 'integer',
    ];

    /**
     * 🔒 ซ่อน relation roomType และ room ไม่ให้ serialize ออกไปใน JSON ทุก endpoint
     * (ให้ client ใช้เฉพาะ room_type_id / room_id ตรงๆ)
     */
    protected $hidden = ['roomType', 'room'];

    /**
     * 🗓️ (2026-09-24, SRS v2 REQ-026/027): derived fields — nights (จำนวนคืน) และ
     * stay_type ('daily' | 'block' | 'monthly' — จองเหมา/รายเดือน ตาม config/booking.php)
     */
    protected $appends = ['nights', 'stay_type'];

    // =========================================================
    // 🚦 State Machine (BR-level)
    // =========================================================

    /**
     * BR State Machine:
     *   draft → confirmed (admin/system)
     *   confirmed → checked_in (admin)       [walk-in skip มาตรงนี้]
     *   confirmed → no_show (admin)
     *   checked_in → checked_out (admin)
     */
    public function transitionStatus(string $newStatus, string $userRole = 'admin'): void
    {
        $current = $this->status;

        $validTransitions = [
            'draft' => [
                'confirmed' => ['admin', 'system'],
            ],
            'confirmed' => [
                'checked_in' => ['admin'],
                'no_show' => ['admin'],
            ],
            'checked_in' => [
                'checked_out' => ['admin'],
            ],
        ];

        if (! isset($validTransitions[$current]) ||
            ! isset($validTransitions[$current][$newStatus])) {
            throw new Exception(
                "ไม่อนุญาตให้เปลี่ยนสถานะห้องจาก '{$current}' → '{$newStatus}' ตาม Flow ระบบค่ะ",
                422
            );
        }

        $requiredRoles = $validTransitions[$current][$newStatus];
        if (! in_array($userRole, $requiredRoles)) {
            throw new Exception('ไม่มีสิทธิ์ดำเนินการสถานะห้องค่ะ!', 403);
        }

        $this->status = $newStatus;
        $this->save();

        // 📝 Audit log (04/08/26): เก็บประวัติการเปลี่ยนสถานะ BR (chokepoint เดียว)
        StatusChangeLog::create([
            'entity_type' => 'booking_room',
            'entity_id' => $this->id,
            'from_status' => $current,
            'to_status' => $newStatus,
            'role' => $userRole,
            'causer_id' => Auth::id(),
            'note' => null,
        ]);
    }

    // =========================================================
    // 🌟 Helpers: ดึงข้อมูลผู้เข้าพัก
    // =========================================================

    public function getPrimaryGuestAttribute(): ?array
    {
        return $this->guests[0] ?? null;
    }

    /**
     * 🗓️ จำนวนคืนของห้องนี้ (mirror สูตรใน BookingController — ครึ่งวันนับเป็น 1 คืน)
     */
    public function getNightsAttribute(): int
    {
        if (empty($this->check_in) || empty($this->check_out)) {
            return 0;
        }

        return $this->check_in->copy()->startOfDay()->diffInDays($this->check_out->copy()->startOfDay()) ?: 1;
    }

    /**
     * 🗓️ ประเภทการเข้าพักตามจำนวนคืน (derived — SRS v2 REQ-026/027)
     *    monthly = จองรายเดือน (>= monthly_min_nights), block = จองเหมา (>= block_min_nights)
     */
    public function getStayTypeAttribute(): string
    {
        $nights = $this->nights;

        if ($nights >= (int) config('booking.monthly_min_nights', 30)) {
            return 'monthly';
        }

        if ($nights >= (int) config('booking.block_min_nights', 21)) {
            return 'block';
        }

        return 'daily';
    }

    // =========================================================
    // 🛏️ Availability: ชุดสถานะที่ "กิน slot" (ทุก endpoint ใช้ scope เดียวกัน)
    // =========================================================

    /**
     * Slot-holding set — สถานะ BR ที่นับลด availability: draft + confirmed + checked_in
     *
     * ⏱️ (2026-09-24, REQ-008 + owner decision "draft + payment_deadline รวม 15 นาที"):
     * draft ที่ payment_deadline ผ่านไปแล้ว "ไม่กิน slot" อีก — ปลดล็อกเป็นห้องว่าง
     * ทันทีตอน query (ไม่รอ CleanupExpiredDrafts มาลบ ซึ่งเป็นแค่ garbage-collect)
     * draft ที่ deadline เป็น null (walk-in style) นับเป็นกิน slot ตามเดิม
     * ห้ามใช้ whereIn('status', ['draft',...]) เองนอก scope นี้ — ไม่งั้น draft หมดอายุ
     * จะยังล็อกห้องค้างจนกว่า sweep จะมาลบค่ะ
     *
     * 💳 (25/09/26, booking-payment-types ticket 03): draft **deferred** ยึด slot ต่อ
     * แม้ payment_deadline ผ่านไปแล้ว — จุดประสงค์ของ deferred คือการันตีห้องให้องค์กร
     * ก่อนชำระ (ถ้าปล่อย slot การยกเว้น CleanupExpiredDrafts ก็ไร้ความหมาย) —
     * draft deferred สร้าง/ดูแลโดย admin เท่านั้น จึงมีคนรับผิดชอบเก็บกวาดเสมอ
     */
    public function scopeHoldingSlot($query)
    {
        return $query->whereIn('status', ['draft', 'confirmed', 'checked_in'])
            ->where(function ($q) {
                $q->whereIn('status', ['confirmed', 'checked_in'])
                    ->orWhere(function ($draft) {
                        $draft->where('status', 'draft')
                            ->whereHas('booking', fn ($b) => $b->where(function ($w) {
                                $w->whereNull('payment_deadline')
                                    ->orWhere('payment_deadline', '>', now())
                                    // 💳 draft deferred — ยึด slot จน admin confirm หรือลบ
                                    ->orWhere('payment_type', 'deferred');
                            }));
                    });
            });
    }

    public function getPrimaryGuestNameAttribute(): string
    {
        $primary = $this->primary_guest;
        if (! $primary) {
            return 'Customer';
        }

        $firstName = $primary['firstName'] ?? $primary['first_name'] ?? '';
        $lastName = $primary['lastName'] ?? $primary['last_name'] ?? '';
        $fullName = trim($firstName.' '.$lastName);

        if (empty($fullName)) {
            $fullName = $primary['name'] ?? '';
        }

        if (empty($fullName)) {
            return 'Customer';
        }

        return trim(($primary['title'] ?? '').' '.$fullName);
    }

    public function getTotalGuestsAttribute(): int
    {
        return is_array($this->guests) ? count($this->guests) : 0;
    }

    // =========================================================
    // 🌟 Relationships
    // =========================================================

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function addon(): HasOne
    {
        return $this->hasOne(Addon::class);
    }

    // =========================================================
    // 🌟 Logic: หาห้องว่าง + Assign
    // =========================================================

    /**
     * หาห้องว่างและ assign ให้ BR ตัวเอง
     * - ใช้ $this->check_in/check_out (BR-level dates)
     * - นับตั้งแต่ draft (availability counting = C)
     */
    public function assignAvailableRoom(): bool
    {
        if ($this->room_id !== null) {
            return true;
        }

        $checkIn = $this->check_in;
        $checkOut = $this->check_out;

        // 📊 (05/10/26) extra-bed เป็นรายคืนแล้ว — จับคู่ห้องด้วยจำนวนสูงสุดต่อคืน (accessor extra_beds_max)
        $requestedExtraBeds = $this->addon ? $this->addon->extra_beds_max : 0;

        // ค้นหาห้องว่าง — เช็คจาก BR-level ชุด slot-holding (draft ที่ยังไม่หมดเวลา + confirmed + checked_in)
        // 🗓️ (24/09/26) room-state-periods: ตัด whereNotIn(status maintenance/reserved_closed) ออก —
        //    สองสถานะนี้ถูกถอดจาก machine แล้ว (period-check อยู่ที่ allocator/availability)
        //    ⚠️ method นี้เป็น dead code (assignment จริงไหลผ่าน RoomAllocator) — คงไว้ตาม spec เดิม
        $availableRoom = Room::where('room_type_id', $this->room_type_id)
            ->whereDoesntHave('bookingRooms', function ($query) use ($checkIn, $checkOut) {
                $query->holdingSlot()
                    ->where('check_in', '<', $checkOut)
                    ->where('check_out', '>', $checkIn);
            })
            ->orderByRaw('builtin_extra_beds >= ? DESC', [$requestedExtraBeds])
            ->orderBy('builtin_extra_beds', 'ASC')
            ->lockForUpdate()
            ->first();

        if ($availableRoom) {
            $this->update(['room_id' => $availableRoom->id]);

            return true;
        }

        return false;
    }
}
