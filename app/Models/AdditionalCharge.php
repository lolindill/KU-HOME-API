<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 💸 AdditionalCharge — ledger ค่าปรับ/ค่าเสียหาย/บริการเสริม (REQ-042/044 · srs-v2-gap-closure ticket 12/13)
 *
 *    💳 (15/10/26 — ticket 13) ยอดบวกยอดจริงที่ booking แล้ว (เดิมเป็น ledger รายงานล้วน):
 *    - total_amount คงค่าห้องล้วน (invariant Σ booking_rooms.amount == total_amount ไม่พัง)
 *    - Booking::charges_total = Σ additional_charges.price → outstanding/is_paid คิดจากยอดรวม
 *    - เก็บเงินหลังชำระ/เข้าพักผ่าน recordPayment (front desk) เท่านั้น — ไม่เปิดส่งสลิปเพิ่ม
 *    - บันทึกอิสระทุกเมื่อ ไม่ guard สถานะ (มติ 4a — staff คุมความถูกต้องเอง)
 *    - สิทธิ์ admin + staff · money = integer บาท (convention 2026-09-11)
 *
 *    booking_room_id nullable — ใส่คู่ booking_id = จดต่อห้อง · ใส่เดียว = รวมทั้ง booking
 *    charge_type: damage | rental | addon (addon = หมวด free-text REQ-044 แยกในรายงาน)
 */
class AdditionalCharge extends Model
{
    use HasUuids;

    public const TYPE_DAMAGE = 'damage';

    public const TYPE_RENTAL = 'rental';

    public const TYPE_ADDON = 'addon';

    public const CHARGE_TYPES = [self::TYPE_DAMAGE, self::TYPE_RENTAL, self::TYPE_ADDON];

    protected $fillable = [
        'booking_id',
        'booking_room_id',
        'transaction_date',
        'item_code',
        'item_name',
        'qty',
        'unit',
        'price',
        'charge_type',
        'recorded_by',
    ];

    protected $casts = [
        'transaction_date' => 'date:Y-m-d',
        'qty' => 'integer',
        'price' => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * 🛏️ ห้องที่ผูก (nullable — charge ระดับ booking ไม่มี)
     */
    public function bookingRoom(): BelongsTo
    {
        return $this->belongsTo(BookingRoom::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
