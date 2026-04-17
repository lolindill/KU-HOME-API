<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; 
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Addon extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    // เปลี่ยนมาใช้ guarded ให้เป็นมาตรฐานเดียวกันค่ะ
    protected $guarded = [];

    protected $casts = [
        'extra_bed' => 'integer',
        'breakfast' => 'integer',
        'early_checkIn_price' => 'integer',
        'late_checkOut_price' => 'integer',
        'extra_bed_price' => 'integer',
        'breakfast_price' => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}