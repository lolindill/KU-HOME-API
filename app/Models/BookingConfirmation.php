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
        // 💳 (25/09/26) ยอดที่ user แจ้งต่อครั้งส่งสลิป (integer บาท) — สลิปใหม่ required ≥ 1 (ticket 04 ชั้น A)
        'amount',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'transfer_time' => 'datetime',
        'reviewed_at' => 'datetime',
        'amount' => 'integer',
    ];

    /**
     * 💳 (25/09/26) ticket 05 หัวข้อ 6 — admin dashboard เห็นคู่ expected vs claimed ตั้งแต่หน้า list
     * expected มาจาก booking ของ row (eager load แล้วใน pending list — ไม่เพิ่ม query)
     */
    protected $appends = ['expected_amount'];

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
     * 💳 (25/09/26) ยอดที่ระบบคาดว่าต้องชำระ ณ ตอนนี้ (สูตรรวมของ Booking::expected_amount)
     * booking อาจไม่ถูก load (เช่น fresh() บางจุด) — lazy load ครั้งเดียวพอ
     */
    public function getExpectedAmountAttribute(): ?int
    {
        return $this->booking?->expected_amount;
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
