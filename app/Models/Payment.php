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

    protected $casts = [
        'amount' => 'decimal:2',
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