<?php

namespace App\Models;

use App\Casts\PgBoolean;
use App\Support\Money;
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
     * 🌟 Update (03/09/26): เปลี่ยนจาก daily_rate (integer) เป็น rates object (baht string)
     * รวม daily (general, ku_member), group (min_5_rooms, min_10_rooms), monthly
     */
    protected $appends = ['rates'];

    /**
     * 🌟 ซ่อน relationship rows ออกจาก JSON response
     */
    protected $hidden = ['dailyRateRow', 'rateRows'];

    protected function casts(): array
    {
        return [
            // 🌟 Fix PostgreSQL strict boolean (03/07/26): PgBoolean cast
            'extra_bed_enabled' => PgBoolean::class,
            // 🌟 Fix L1 (03/07/26): integer casts สำหรับคอลัมน์ตัวเลข
            'max_guests' => 'integer',
            'max_extra_beds' => 'integer',
            // 🌟 (03/09/26): extra_bed_price ใช้ accessor แปลงเป็น baht string ที่ขอบ API
        ];
    }

    /**
     * 🌟 Add (03/09/26): extra_bed_price accessor คืนเป็น baht string (เช่น "500.00")
     * เพื่อให้สอดคล้องกับ rates object ที่ขอบ API (storage ยังคงเป็น integer satang)
     */
    public function getExtraBedPriceAttribute($value): string
    {
        return Money::satangToBaht((int) $value);
    }

    public function setExtraBedPriceAttribute($value): void
    {
        $this->attributes['extra_bed_price'] = (int) $value;
    }

    /**
     * 🌟 Add (03/09/26): โหลด rate rows ทั้งหมดที่ active สำหรับ room type นี้
     */
    public function rateRows(): HasMany
    {
        return $this->hasMany(GlobalRate::class, 'room_type_id', 'id')
            ->whereRaw('is_active = TRUE');
    }

    /**
     * 🌟 Add (03/09/26): Canonical rates object (baht string 2 ตำแหน่ง)
     */
    public function getRatesAttribute(): array
    {
        $rows = $this->relationLoaded('rateRows')
            ? $this->rateRows
            : $this->rateRows()->get();

        $dailyGeneral = $rows->first(fn ($r) => $r->rate_type === 'daily' && $r->is_active)?->default_price ?? 0;
        $dailyKu = $rows->first(fn ($r) => $r->rate_type === 'daily_ku' && $r->is_active)?->default_price ?? 0;
        $groupMin5 = $rows->first(fn ($r) => $r->rate_type === 'group' && $r->code === 'min_5_rooms' && $r->is_active)?->default_price ?? 0;
        $groupMin10 = $rows->first(fn ($r) => $r->rate_type === 'group' && $r->code === 'min_10_rooms' && $r->is_active)?->default_price ?? 0;
        $monthly = $rows->first(fn ($r) => $r->rate_type === 'month' && $r->is_active)?->default_price ?? 0;

        return [
            'daily' => [
                'general' => Money::satangToBaht($dailyGeneral),
                'ku_member' => Money::satangToBaht($dailyKu),
            ],
            'group' => [
                'min_5_rooms' => Money::satangToBaht($groupMin5),
                'min_10_rooms' => Money::satangToBaht($groupMin10),
            ],
            'monthly' => Money::satangToBaht($monthly),
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
     * คืน default_price ของ daily rate row ถ้าไม่พบจะเป็น 0 (เก็บไว้เป็น fallback helper)
     */
    public function getDailyRateAttribute(): int
    {
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
