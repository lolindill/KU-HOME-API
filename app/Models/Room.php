<?php

namespace App\Models;

use App\Casts\PgBoolean;
use Exception;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

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
        // 🏨 (24/09/26) ticket 90: ห้องสำรอง = pool membership (property ถาวร) ไม่ใช่ lifecycle state
        'is_reserved',
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
        'is_reserved' => PgBoolean::class,
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
     * 🏨 (24/09/26) ticket 90: สถานะ `reserved_closed` ถูกถอดออก — "ห้องสำรอง" กลายเป็น
     *    pool membership ผ่าน column `is_reserved` (property ถาวร ไม่ผูก lifecycle)
     *
     * available → checkout_makeup, dirty, maintenance, prep_checkin
     * occupied → available, prep_checkin
     * checkout_makeup → occupied
     * dirty → available, checkout_makeup
     * prep_checkin → available, dirty
     * maintenance → * (any status)
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
        //    🏨 (24/09/26): ถอด reserved_closed ออกทั้ง target และ source — ดู ticket 90
        $allowedTransitions = [
            'occupied' => ['available', 'prep_checkin'],
            'checkout_makeup' => ['occupied'],
            'available' => ['checkout_makeup', 'dirty', 'maintenance', 'prep_checkin'],
            'dirty' => ['available', 'prep_checkin'],
            'prep_checkin' => ['available', 'dirty', 'occupied', 'checkout_makeup'],
            'maintenance' => ['*'],
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

        // 📝 Audit log (24/09/26, REQ-039): เก็บประวัติการเปลี่ยนสถานะห้อง 1 ปีย้อนหลัง
        //    เขียนที่นี่เพราะเป็น chokepoint เดียว — อย่า bypass ด้วย ->status = ตรงๆ
        //    (สถานะ reserved/maintenance เป็น period ของ room_state_periods — ประวัติชั้นเอง
        //    ไม่ได้ log ผ่านจุดนี้ · retention ตัดโดย app:cleanup-status-logs)
        $causerId = $updatedByUserId ?? Auth::id();
        StatusChangeLog::create([
            'entity_type' => 'room',
            'entity_id' => $this->id,
            'from_status' => $currentStatus,
            'to_status' => $newStatus,
            'role' => $causerId ? (User::find($causerId)?->role ?? 'system') : 'system',
            'causer_id' => $causerId,
            'note' => null,
        ]);

        return true;
    }
}
