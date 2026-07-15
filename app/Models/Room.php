<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasFactory, HasUuids;

    /**
     * ✅ #17 Fixed: เปลี่ยนจาก $guarded = [] เป็น $fillable
     *
     * 'status' อยู่ใน $fillable เพราะมี transitionStatusTo() state machine เป็น guard
     * 'status_updated_at' และ 'status_updated_by' จะถูกเซ็ตผ่าน transitionStatusTo() เท่านั้น
     */
    protected $fillable = [
        'room_type_id',
        'room_number',
        'status',
        'builtin_extra_beds',
        'status_updated_at',
        'status_updated_by',
        // 🏨 Phase 1: topology columns สำหรับ Room Allocation Algorithm
        'floor',
        'side',
        'pos',
        'bed_type',
    ];

    // 🌟 Fix L1 (03/07/26): missing casts — status_updated_at ใช้เป็น Carbon หลายจุด
    protected $casts = [
        'status_updated_at' => 'datetime',
        'builtin_extra_beds' => 'integer',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id', 'id');
    }

    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class, 'room_id');
    }

    // 🌟 Fix L2 (03/07/26): inverse relationship ที่หายไป (FK housekeeping_tasks.room_id)
    public function housekeepingTasks(): HasMany
    {
        return $this->hasMany(HousekeepingTask::class, 'room_id');
    }

    /**
     * Room Status State Machine (all lowercase)
     *
     * available → checkout_makeup, dirty, maintenance, reserved_closed
     * occupied → available, prep_checkin
     * checkout_makeup → occupied
     * dirty → available, checkout_makeup
     * prep_checkin → available, dirty
     * maintenance → * (any status)
     * reserved_closed → * (any status)
     */
    public function transitionStatusTo(string $newStatus, ?string $updatedByUserId = null)
    {
        $currentStatus = $this->status;
        $newStatus = strtolower($newStatus);

        // ถ้าสถานะเดิมอยู่แล้ว ไม่ต้องอัปเดตให้เปลืองแรงค่ะ
        if ($currentStatus === $newStatus) {
            return false;
        }

        // 🛡️ กฎการเปลี่ยนสถานะ (key = target status, value = allowed source statuses)
        //    🧹 Phase A (15/07/26): prep_checkin เพิ่ม 'checkout_makeup' เป็น source
        //       (DailyRoomMaintenance prep ห้อง checkout_makeup ที่แขกเข้าพรุ่งนี้ได้)
        $allowedTransitions = [
            'occupied' => ['available', 'prep_checkin'],
            'checkout_makeup' => ['occupied'],
            'available' => ['checkout_makeup', 'dirty', 'maintenance', 'reserved_closed', 'prep_checkin'],
            'dirty' => ['available', 'prep_checkin'],
            'prep_checkin' => ['available', 'dirty', 'occupied', 'checkout_makeup'],
            'maintenance' => ['*'],
            'reserved_closed' => ['*'],
        ];

        $canTransition = false;
        if (isset($allowedTransitions[$newStatus])) {
            $allowedFrom = $allowedTransitions[$newStatus];
            if (in_array('*', $allowedFrom) || in_array($currentStatus, $allowedFrom)) {
                $canTransition = true;
            }
        }

        // 🛑 เด้ง Error ถ้าพยายามเปลี่ยนสถานะข้ามขั้น
        if (! $canTransition) {
            throw new Exception("Invalid status transition from '{$currentStatus}' to '{$newStatus}'.", 422);
        }

        // ✨ อัปเดตข้อมูลลงฐานข้อมูล
        $this->status = $newStatus;
        $this->status_updated_at = now();

        if ($updatedByUserId) {
            $this->status_updated_by = $updatedByUserId;
        }

        $this->save();

        return true;
    }
}
