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
        'check_in',
        'check_out',
        'total_amount',
        'is_paid',
        'payment_deadline',
    ];

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'total_amount' => 'integer',
        'is_paid' => 'boolean',
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

    /**
     * Booking Status State Machine
     *
     * draft → paid (user, guest, admin), checked_in (admin), deleted (user, guest, admin)
     * paid → confirmed (admin), cancelled (admin)
     * confirmed → cancelled (admin), checked_in (admin), no_show (admin)
     * checked_in → checked_out (admin)
     *
     * Special: 'system' role for webhook (draft → paid only)
     */
    public function transitionStatus(string $newStatus, string $userRole)
    {
        $currentStatus = $this->status;

        $validTransitions = [
            'draft' => [
                'paid'       => ['user', 'guest', 'admin', 'system'],
                'checked_in' => ['admin'], // walk-in by admin
                'deleted'    => ['user', 'guest', 'admin', 'system'],
            ],
            'paid' => [
                'confirmed' => ['admin'],
                'cancelled' => ['admin'],
            ],
            'confirmed' => [
                'cancelled'  => ['admin'],
                'checked_in' => ['admin'],
                'no_show'    => ['admin'],
            ],
            'checked_in' => [
                'checked_out' => ['admin'],
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