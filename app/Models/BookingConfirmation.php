<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class BookingConfirmation extends Model
{
    use HasFactory, HasUuids;

    /**
     * 🌟 Refactor (24/07/26): Payment confirmation table (replaces payments/receipts)
     *
     * 1:N with bookings — เก็บ history ทุกครั้งที่ user ส่งหลักฐานการชำระ (แม้ reject)
     * - state machine ของตัวเอง: pending → verified | rejected (ทั้งคู่ terminal)
     * - ถ้า reject → user สร้าง row ใหม่ (ไม่ก็อกกลับ) เพื่อรักษา audit trail
     *
     * 🖼️ Refactor (19/08/26): สลิปเก็บใน images table ผ่าน morph (slipImage)
     * — เลิกเก็บ path string ใน column slip_image แล้ว
     */
    protected $fillable = [
        'booking_id',
        'transfer_time',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'transfer_time' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * 🖼️ (19/08/26) รูปสลิป — morphOne ไป images table (ตัวเชื่อมเดียวหลังเลิก column slip_image)
     */
    public function slipImage(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    /**
     * 🚦 State machine (1:N): pending → verified | rejected
     *   pending → verified (admin) — booking จะ paid → confirmed (เรียกใน controller)
     *   pending → rejected (admin) — booking ค้าง paid; user สร้าง row ใหม่ถ้าจะลองอีก
     *   verified/rejected = terminal (ไม่ย้อนกลับ — จะแก้ทำ row ใหม่แทน)
     */
    public function transitionStatus(string $newStatus, string $userRole): void
    {
        $validTransitions = [
            'pending' => [
                'verified' => ['admin'],
                'rejected' => ['admin'],
            ],
            // verified + rejected = terminal (no outbound transitions)
        ];

        if (! isset($validTransitions[$this->status][$newStatus])) {
            throw new Exception(
                "ไม่อนุญาตให้เปลี่ยนสถานะ confirmation จาก '{$this->status}' ไปเป็น '{$newStatus}' ค่ะนายท่าน",
                422
            );
        }

        $requiredRoles = $validTransitions[$this->status][$newStatus];
        if (! in_array($userRole, $requiredRoles)) {
            throw new Exception('ไม่มีสิทธิ์ดำเนินการค่ะ!', 403);
        }

        $this->status = $newStatus;
        $this->save();
    }
}
