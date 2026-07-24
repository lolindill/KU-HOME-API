<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Carbon\Carbon;
use Exception;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\BookingConfirmation;

class Booking extends Model
{
    use HasFactory, HasUuids;

    /**
     * ✅ #8 Fixed: เปลี่ยนจาก $guarded = [] เป็น $fillable
     * 🌟 Refactor (18/06/26): ย้ายข้อมูลผู้เข้าพักไปที่ booking_rooms (guests JSON)
     *    bookings จะเก็บแค่ข้อมูลการจอง + user_id (ลูกค้า) เท่านั้น
     *
     * 'status' อยู่ใน $fillable เพื่อให้ Booking::create() และ tests ทำงานได้
     * การเปลี่ยนสถานะทั้งหมดควรผ่าน transitionStatus() ซึ่งมี role-based guard คุ้มอยู่
     */
    protected $fillable = [
        'user_id',
        'confirmation',
        'source',
        'status',
        'total_amount',
        'is_paid',
        'payment_deadline',
    ];

    protected $casts = [
        'total_amount' => 'integer',
        // 🌟 Fix PostgreSQL strict boolean (03/07/26): ใช้ PgBoolean cast แทน 'boolean'
        // (PDO ส่ง PHP bool → integer 0/1 → PostgreSQL ปฏิเสธ)
        'is_paid' => \App\Casts\PgBoolean::class,
        'payment_deadline' => 'datetime',
    ];

    // 🌟 Helper: ดึงชื่อผู้เข้าพักหลัก (primary guest) จาก booking_rooms แรกที่มี guests
    // ใช้สำหรับ Receipt billing_name และแสดงผล — สำรองด้วย user.name
    public function getPrimaryGuestNameAttribute(): string
    {
        $bookingRoom = $this->bookingRooms()
            ->whereNotNull('guests')
            ->orderBy('created_at')
            ->first();

        if ($bookingRoom && $bookingRoom->primary_guest_name !== 'Customer') {
            return $bookingRoom->primary_guest_name;
        }

        return $this->user?->name ?? 'Customer';
    }

    // 🌟 Helper: นับจำนวนผู้เข้าพักรวมทุกห้อง (สำหรับ dashboard / summary)
    public function getTotalGuestsAttribute(): int
    {
        return $this->bookingRooms->sum(fn ($br) => $br->total_guests);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class, 'booking_id');
    }

    // 🌟 Fix (03/07/26): relationship ที่หายไป — CleanupExpiredDrafts เรียก $booking->payments()->delete()
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /**
     * 🌟 Refactor (24/07/26): Payment confirmations (1:N) — replaces payments/receipts
     * - เก็บ history ทุกครั้งที่ user ส่งหลักฐานการชำระ
     * - state machine: pending → verified | rejected
     */
    public function confirmations(): HasMany
    {
        return $this->hasMany(BookingConfirmation::class, 'booking_id');
    }

    /**
     * 🌟 Refactor (29/06/26): Booking = Container State Machine (Final — no cancelled)
     *
     * Booking (container) เก็บสถานะ payment/admin flow:
     *   draft → paid (user, guest, admin, system webhook)
     *   draft → confirmed (admin, walk-in เข้าตรงๆ)
     *   paid → confirmed (admin)
     *   confirmed → complete (auto เมื่อ BR ทุกห้อง checked_out/no_show)
     *
     * ❌ ไม่มี cancelled แล้ว — draft ที่หมดอายุจะถูก hard delete (CleanupExpiredDrafts)
     * ⚠️ checked_in / checked_out / no_show อยู่ที่ BookingRoom (BR-level state machine)
     */
    public function transitionStatus(string $newStatus, string $userRole)
    {
        $currentStatus = $this->status;

        $validTransitions = [
            'draft' => [
                'paid'       => ['user', 'guest', 'admin', 'system'],
                'confirmed'  => ['admin'], // walk-in by admin (skip paid)
            ],
            'paid' => [
                'confirmed' => ['admin'],
            ],
            'confirmed' => [
                'complete'  => ['admin', 'system'], // auto เมื่อ BR ครบ
            ],
        ];

        // เช็คว่า flow ถูกต้องไหม
        if (!array_key_exists($currentStatus, $validTransitions) ||
            !array_key_exists($newStatus, $validTransitions[$currentStatus])) {
            throw new Exception("ไม่อนุญาตให้เปลี่ยนสถานะจาก '{$currentStatus}' ไปเป็น '{$newStatus}' ตาม Flow ระบบค่ะนายท่าน", 422);
        }

        // เช็ค Role
        $requiredRoles = $validTransitions[$currentStatus][$newStatus];
        if (!in_array($userRole, $requiredRoles)) {
            throw new Exception("ไม่มีสิทธิ์ดำเนินการค่ะ!", 403);
        }

        $this->status = $newStatus;
        $this->save();
    }

    /**
     * 🌟 Helper: อัปเดตสถานะ booking container อัตโนมัติ
     * - ถ้า BR ทุกห้อง checked_out/no_show → booking → 'complete'
     *
     * 🌟 Fix scrutinize (29/06/26): ใช้ transitionStatus() แทน direct set เพื่อ enforce state machine
     * กันกรณี flow ผิดปกติ (เช่น booking ยังเป็น 'paid' แต่ BR จบแล้ว) — state machine จะ throw exception
     * ทำให้ bug ปรากฏชัดแทนที่จะถูก silent ignore
     */
    public function syncStatusFromRooms(): void
    {
        $rooms = $this->bookingRooms;
        if ($rooms->isEmpty()) return;

        $allFinished = $rooms->every(fn ($br) => in_array($br->status, ['checked_out', 'no_show']));
        if ($allFinished && $this->status !== 'complete') {
            // ใช้ state machine (system role) — confirmed → complete
            // ถ้า status ปัจจุบันไม่ใช่ confirmed จะ throw exception ให้ผู้เรียกจัดการ (เช่น paid → complete)
            $this->transitionStatus('complete', 'system');
        }
    }

    /**
     * ✅ #10 Fixed: Atomic counter confirmation number generator
     *
     * ใช้ booking_sequences table + SELECT FOR UPDATE เพื่อป้องกัน collision
     * Format: YYYYMM-XXXXX (เช่น 202606-00001)
     *
     * @return string Confirmation number ที่ unique การันตี
     * @throws Exception ถ้าสร้างไม่สำเร็จหลัง retry
     */
    public static function generateUniqueConfirmation(): string
    {
        $maxAttempts = 3;

        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                return DB::transaction(function () {
                    $key = Carbon::now()->format('Ym');

                    $seq = DB::table('booking_sequences')
                        ->where('key', $key)
                        ->lockForUpdate()
                        ->first();

                    if ($seq) {
                        $next = $seq->last_number + 1;
                        DB::table('booking_sequences')
                            ->where('key', $key)
                            ->update(['last_number' => $next]);
                    } else {
                        $next = 1;
                        DB::table('booking_sequences')->insert([
                            'key' => $key,
                            'last_number' => $next,
                        ]);
                    }

                    return $key . '-' . str_pad($next, 5, '0', STR_PAD_LEFT);
                });
            } catch (QueryException $e) {
                if ($i === $maxAttempts - 1) {
                    throw new Exception('Unable to generate unique confirmation number after ' . $maxAttempts . ' attempts');
                }
                usleep(100000); // รอ 100ms แล้ว retry
            }
        }

        throw new Exception('Unable to generate unique confirmation number');
    }
}