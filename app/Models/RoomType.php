<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class RoomType extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = []; // อนุญาตให้ Mass Assignment

    // 👇 เติม 2 บรรทัดนี้เพื่อกำราบ Laravel ค่ะ!
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            // 🌟 Fix PostgreSQL strict boolean (03/07/26): PgBoolean cast
            'extra_bed_enabled' => \App\Casts\PgBoolean::class,
            // 🌟 Fix L1 (03/07/26): integer casts สำหรับคอลัมน์ตัวเลข
            // (rate_daily_general ใช้คูณในการคำนวณราคา — ต้อง cast ให้ตรงกันทั้งระบบ)
            'max_guests' => 'integer',
            'max_extra_beds' => 'integer',
            'extra_bed_price' => 'integer',
            'rate_daily_general' => 'integer',
        ];
    }
    public function rooms(): HasMany
    {
        // เชื่อมไปยัง Model Room โดยใช้ room_type_id เป็น Foreign Key ค่ะ
        return $this->hasMany(Room::class, 'room_type_id', 'id');
    }
    public function bookingRooms(): HasMany
    {
        return $this->hasMany(BookingRoom::class, 'room_type_id', 'id');
    }
    
}