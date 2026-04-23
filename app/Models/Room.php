<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Exception; // 🌟 เพิ่มบรรทัดนี้เข้ามาด้วยนะคะ

class Room extends Model
{
    use HasFactory;

    protected $guarded = [];
    public $incrementing = false;
    protected $keyType = 'string';

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id', 'id');
    }

    // 🌟 ฟังก์ชันใหม่สำหรับจัดการ State Rules ของห้องพักค่ะ
    public function transitionStatusTo(string $newStatus, ?string $updatedByUserId = null)
    {
        $currentStatus = $this->status;

        // ถ้าสถานะเดิมอยู่แล้ว ไม่ต้องอัปเดตให้เปลืองแรงค่ะ
        if ($currentStatus === $newStatus) {
            return false; 
        }

        // 🛡️ กฎการเปลี่ยนสถานะของนายท่าน
        $allowedTransitions = [
            'Occupied'        => ['available', 'prep_checkIn'],
            'checkout_makeup' => ['Occupied'],
            'available'       => ['checkout_makeup', 'dirty', 'maintenance', 'reserved_closed'],
            'maintenance'     => ['*'], 
            'reserved_closed' => ['*'],
            'dirty'           => ['available', 'checkout_makeup'], 
            'prep_checkIn'    => ['available', 'dirty'] 
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

        return true; // ส่งค่ากลับว่ามีการเปลี่ยนสถานะสำเร็จค่ะ
    }
}