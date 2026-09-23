<?php

namespace App\Models;

use App\Casts\PgBoolean;
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

    /**
     * 🌟 Update (11/09/26): rates object เป็น integer บาทล้วน (non-decimal)
     * รวม daily (general, ku_member), group (min_5_rooms, min_10_rooms), monthly
     * 💰 มาตรฐานเงินใหม่: storage = wire = integer บาท ไม่มีการแปลงที่ขอบ API แล้ว
     */
    protected $appends = ['rates'];

    /**
     * 🌟 ซ่อน relationship rows ออกจาก JSON response
     */
    protected $hidden = ['rateRows'];

    protected function casts(): array
    {
        return [
            // 🌟 Fix PostgreSQL strict boolean (03/07/26): PgBoolean cast
            'extra_bed_enabled' => PgBoolean::class,
            // 🌟 Fix L1 (03/07/26): integer casts สำหรับคอลัมน์ตัวเลข
            'max_guests' => 'integer',
            'max_extra_beds' => 'integer',
            // 💰 (11/09/26): integer บาทล้วน — ไม่มี accessor/mutator แปลงทศนิยมแล้ว
            'extra_bed_price' => 'integer',
        ];
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
     * 🌟 Canonical rates object (integer บาทล้วน — non-decimal)
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
                'general' => (int) $dailyGeneral,
                'ku_member' => (int) $dailyKu,
            ],
            'group' => [
                'min_5_rooms' => (int) $groupMin5,
                'min_10_rooms' => (int) $groupMin10,
            ],
            'monthly' => (int) $monthly,
        ];
    }

    /**
     * 🌟 Local Scope: Eager-load rateRows และนับจำนวนห้องที่ขายได้ (status ไม่ใช่ maintenance/reserved_closed)
     */
    public function scopeWithSellableRoomsAndRates($query)
    {
        return $query->withCount(['rooms as total_rooms_count' => function ($q) {
            $q->whereNotIn('status', ['maintenance', 'reserved_closed']);
        }])->with('rateRows');
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
