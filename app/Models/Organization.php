<?php

namespace App\Models;

use App\Casts\PgBoolean;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 🏛️ Organization — หน่วยงาน/องค์กรที่ admin ใช้จองแทน (wayfinder/organization-bookings)
 *
 * Stopgap ตาม standing decision ของ effort: ทุก design ต้องตอบได้ว่า
 * "ถ้าเทตารางนี้ทิ้งไปใช้ organization-data API แทน ระบบเดิมยังอ่านความหมายถูกไหม"
 * → `erp` เป็น key กลางที่ใช้ map ไปยัง API ตอนเปลี่ยน (ticket 01 resolution)
 */
class Organization extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'erp',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => PgBoolean::class, // 🔒 PostgreSQL strict boolean
    ];
}
