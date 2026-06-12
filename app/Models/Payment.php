<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'booking_id',
        'amount',
        'payment_method',
        'status',
        'reference_number',
        'received_by'
    ];

    /**
     * ✅ #30 Fixed: เปลี่ยน cast จาก decimal:2 เป็น integer
     * มาตรฐานเดียวกันกับ Booking.total_amount = integer (satang/cents)
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
}