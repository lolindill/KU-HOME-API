<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
class RoomType extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = []; // อนุญาตให้ Mass Assignment

    // 👇 เติม 2 บรรทัดนี้เพื่อกำราบ Laravel ค่ะ!
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * 🌟 Add (24/07/26): เพิ่ม daily_rate เป็น virtual field (integer)
     * ดึงค่าจาก global_rates (rate_type='daily') ผ่าน dailyRateRow relationship
     * ทำให้ frontend ได้ราคาห้องรายวันกลับไปใน room type object โดยตรง
     * ไม่ต้องเรียก /global-rates แยกอีกที
     *
     * หมายเหตุ: ใช้ชื่อ relationship ว่า dailyRateRow (ไม่ใช่ dailyRate)
     * เพื่อหลีกก relationship-snake-case daily_rate ชนกับ accessor daily_rate
     * ที่เรา append เป็น integer
     */
    protected $appends = ['daily_rate'];

    /**
     * 🌟 ซ่อน dailyRateRow ออกจาก JSON response — เราเอาแค่ default_price
     * ที่ expose ผ่าน daily_rate accessor
     */
    protected $hidden = ['dailyRateRow'];

    protected function casts(): array
    {
        return [
            // 🌟 Fix PostgreSQL strict boolean (03/07/26): PgBoolean cast
            'extra_bed_enabled' => \App\Casts\PgBoolean::class,
            // 🌟 Fix L1 (03/07/26): integer casts สำหรับคอลัมน์ตัวเลข
            'max_guests' => 'integer',
            'max_extra_beds' => 'integer',
            'extra_bed_price' => 'integer',
            // 🌟 Refactor (22/07/26): rate_daily_general ย้ายไป global_rates แล้ว
        ];
    }

    /**
     * 🌟 Add (24/07/26): Room daily rate row ผูกกับ global_rates (rate_type='daily')
     * ใช้ HasOne เพื่อให้ eager-load ได้ (with('dailyRateRow')) กัน N+1
     */
    public function dailyRateRow(): HasOne
    {
        return $this->hasOne(GlobalRate::class, 'room_type_id', 'id')
            ->where('rate_type', 'daily')
            ->whereRaw('is_active = TRUE');
    }

    /**
     * 🌟 Add (24/07/26): Virtual attribute daily_rate (integer)
     * คืน default_price ของ daily rate row ถ้าไม่พบจะเป็น 0
     */
    public function getDailyRateAttribute(): int
    {
        // ถ้า relationship ยังไม่ถูก load จะใช้ global helper getRoomRate()
        // (เผื่อกรณี serialize นอก controller เช่น queue/observer)
        if ($this->relationLoaded('dailyRateRow')) {
            return $this->dailyRateRow?->default_price ?? 0;
        }

        return GlobalRate::getRoomRate($this, 'daily');
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