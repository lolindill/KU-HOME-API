<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Exception; // 🌟 อย่าลืม use Exception นะคะ

class Booking extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = []; 

    protected $casts = [
        'check_in' => 'date',
        'check_out' => 'date',
        'is_ku_member' => 'boolean',
        'total_amount' => 'integer',
        'is_paid' => 'boolean',
        'payment_deadline' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addon(): HasOne
    {
        return $this->hasOne(Addon::class);
    }
    
    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class);
    }


    // 🌟 ฟังก์ชันใหม่สำหรับจัดการ State Rules โดยเฉพาะค่ะ
    public function transitionStatus(string $newStatus, string $userRole)
    {
        $currentStatus = $this->status;

        // กฎการเปลี่ยนสถานะและ Role ที่อนุญาต
        $validTransitions = [
            'draft' => [
                'paid'      => ['user', 'guest'],
                'deleted'   => ['user', 'guest'],
            ],
            'paid' => [
                'confirmed' => ['admin'], // 🌟 หนูแก้คำผิดจาก comfirmed และทำเป็น Array ให้แล้วค่ะ
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

        // 1. เช็คว่ามีสถานะนี้ในระบบไหม และ Flow ถูกต้องหรือเปล่า
        if (!array_key_exists($currentStatus, $validTransitions) || !array_key_exists($newStatus, $validTransitions[$currentStatus])) {
            throw new Exception("ไม่อนุญาตให้เปลี่ยนสถานะจาก '{$currentStatus}' ไปเป็น '{$newStatus}' ตาม Flow ระบบค่ะนายท่าน", 422);
        }

        // 2. เช็ค Role ว่ามีสิทธิ์ทำรายการใน Flow นี้ไหม
        $requiredRoles = $validTransitions[$currentStatus][$newStatus];
        if (!in_array($userRole, $requiredRoles)) {
            throw new Exception("ไม่มีสิทธิ์ดำเนินการค่ะ!", 403);
        }

        // 3. ถ้าผ่านทุกด่าน ก็เปลี่ยนสถานะเลยค่ะ!
        $this->status = $newStatus;
        $this->save();
    }
}