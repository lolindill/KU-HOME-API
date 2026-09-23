<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'booking_id',
        'amount',
        'status',
        'reference_number',
        'received_by',
    ];

    /**
     * ✅ #30 Fixed: เปลี่ยน cast จาก decimal:2 เป็น integer
     * มาตรฐานเดียวกันกับ Booking.total_amount = integer บาทล้วน (11/09/26: satang → baht)
     */
    protected $casts = [
        'amount' => 'integer',
    ];

    // 🌟 Relationship: กลับไปหาใบจอง
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    // 🌟 Relationship: พนักงานที่รับเงิน
    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // 🌟 Fix L2 (03/07/26): inverse relationship ที่หายไป — Receipt เคยมี belongsTo(Payment) ฝ่ายเดียว
    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }
}
