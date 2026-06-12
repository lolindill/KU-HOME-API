<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Exception;

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
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id', 'id');
    }

    public function bookingRooms()
    {
        return $this->hasMany(BookingRoom::class, 'room_id');
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
        $allowedTransitions = [
            'occupied'        => ['available', 'prep_checkin'],
            'checkout_makeup' => ['occupied'],
            'available'       => ['checkout_makeup', 'dirty', 'maintenance', 'reserved_closed', 'prep_checkin'],
            'dirty'           => ['available', 'prep_checkin'], 
            'prep_checkin'    => ['available', 'dirty', 'occupied'],
            'maintenance'     => ['*'], 
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
        if (!$canTransition) {
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