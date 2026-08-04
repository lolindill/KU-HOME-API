<?php

namespace App\Models;

use App\Casts\PgBoolean;
use Exception;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'guests',      // JSON: [{ title, name, nationality, is_ku_member }, ...]
        // 🧒 Refactor (04/08/26): เปลี่ยนจาก integer count → boolean flag
        'has_children', // bool: มีเด็กเข้าพักในห้องนี้ไหม
        'status',      // draft | confirmed | checked_in | checked_out | no_show
        // 🏨 Phase 1: bed_preference สำหรับ Room Allocation Algorithm (twin | null=any)
        'bed_preference',
        // 🧾 Billing fields (04/08/26): ที่อยู่ + หมายเหตุใบกำกับภาษีระดับห้อง
        'billing_address',
        'billing_comment',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'guests' => 'array',
        // 🌟 Fix PostgreSQL strict boolean (04/08/26): PgBoolean cast
        'has_children' => PgBoolean::class,
    ];

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

        return trim(($primary['title'] ?? '').' '.($primary['name'] ?? ''));
    }

    public function getTotalGuestsAttribute(): int
    {
        // 🧒 Refactor (04/08/26): เดิมนับ children (int) รวมด้วย
        //    ตอนนี้ has_children เป็น boolean flag ไม่ใช่ count แล้ว จึงนับเฉพาะ adults ใน guests[]
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
