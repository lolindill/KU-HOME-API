<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 💸 AdditionalCharge — ledger ค่าเสียหาย / ค่ายืมอุปกรณ์ (excel-reports spec §2.6, ticket 11)
 *
 *    ⚠️ LEDGER รายงานล้วน — ยอดไม่เข้า booking:
 *    - invariant Σ booking_rooms.amount == total_amount + ชั้น A/B ของแมป booking-payment-types ไม่ถูกแตะ
 *    - ไม่ไหลเข้า payments (ledger นั้นนิยาม "เงินของ booking") — เก็บเงินสดเคาน์เตอร์แยกจาก booking
 *    - บันทึกอิสระทุกเมื่อ ไม่ผูก flow checked_out · สิทธิ์ admin + staff (default spec §9)
 *
 *    money = integer บาท (convention 2026-09-11) · charge_type: damage | rental
 */
class AdditionalCharge extends Model
{
    use HasUuids;

    public const TYPE_DAMAGE = 'damage';

    public const TYPE_RENTAL = 'rental';

    public const CHARGE_TYPES = [self::TYPE_DAMAGE, self::TYPE_RENTAL];

    protected $fillable = [
        'booking_id',
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
