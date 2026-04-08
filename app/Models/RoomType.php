<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class RoomType extends Model
{
    use HasFactory;

    protected $guarded = []; // อนุญาตให้ Mass Assignment

    // 👇 เติม 2 บรรทัดนี้เพื่อกำราบ Laravel ค่ะ!
    public $incrementing = false;
    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'extra_bed_enabled' => 'boolean',
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