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
        // 🏨 Phase 1: bed_preference สำหรับ Room Allocation Algorithm (twin | null=any)
        'bed_preference',
        // 🧾 Billing fields (04/08/26): ที่อยู่ + หมายเหตุใบกำกับภาษีระดับห้อง
        'billing_address',
        'billing_comment',
        // 🎟️ Discount fields (27/08/26): ค่าห้องก่อนลด และ ส่วนลดของห้องนี้ (satang)
        'room_amount',
        'discount_amount',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'guests' => 'array',
        'room_amount' => 'integer',
        'discount_amount' => 'integer',
    ];

    /**
     * 🌟 (26/08/26): คืน boolean early_checkin / late_checkout ในระดับ booking_room
     * derive จากราคาใน addon ที่บันทึกไว้ (price > 0 = เลือกใช้)
     */
    protected $appends = ['early_checkin', 'late_checkout'];

    /**
     * 🔒 ซ่อน relation roomType และ room ไม่ให้ serialize ออกไปใน JSON ทุก endpoint
     * (ให้ client ใช้เฉพาะ room_type_id / room_id ตรงๆ)
     */
    protected $hidden = ['roomType', 'room'];

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

    public function getEarlyCheckinAttribute(): bool
    {
        return ($this->addon?->early_checkIn_price ?? 0) > 0;
    }

    public function getLateCheckoutAttribute(): bool
    {
        return ($this->addon?->late_checkOut_price ?? 0) > 0;
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

        $requestedExtraBeds = $this->addon ? $this->addon->extra_bed : 0;

        // ค้นหาห้องว่าง — เช็คจาก BR-level ทุกสถานะตั้งแต่ draft ขึ้นไป
        $availableRoom = Room::where('room_type_id', $this->room_type_id)
            ->whereDoesntHave('bookingRooms', function ($query) use ($checkIn, $checkOut) {
                $query->whereIn('status', ['draft', 'confirmed', 'checked_in'])
                    ->where('check_in', '<', $checkOut)
                    ->where('check_out', '>', $checkIn);
            })
            ->whereNotIn('status', ['maintenance', 'reserved_closed'])
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
