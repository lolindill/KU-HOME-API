<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Receipt extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = []; // ปลดล็อกให้เซฟได้ทุกฟิลด์ค่ะ

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}