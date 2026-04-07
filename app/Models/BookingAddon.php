<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class BookingAddon extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'booking_id',
        'addon_id',
        'quantity',
        'price',
        'subtotal'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'quantity' => 'integer',
    ];

    // 🌟 Relationship: กลับไปหาข้อมูลการจองหลัก (Booking)
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    // 🌟 Relationship: กลับไปหาข้อมูล Master ของบริการเสริม (Addon)
    public function addon()
    {
        return $this->belongsTo(Addon::class);
    }
}