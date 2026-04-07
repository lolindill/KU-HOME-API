<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; // 🌟 ขาดไม่ได้เลยสำหรับ UUID ค่ะ

class Addon extends Model
{
    use HasFactory, HasUuids;

    // อนุญาตให้ Mass Assignment ผ่านฟิลด์เหล่านี้ได้
    protected $fillable = [
        'name_th',
        'name_en',
        'description',
        'price',
        'is_active'
    ];

    // แปลงชนิดข้อมูลให้พร้อมใช้เสมอ
    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    // 🌟 Relationship: บริการเสริมนี้ถูกเรียกใช้ใน Booking ไหนบ้าง
    public function bookingAddons()
    {
        return $this->hasMany(BookingAddon::class);
    }
}