<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🌟 Refactor (22/07/26): GlobalRate (renamed from AddonRate)
 *
 * ตารางเดียวเก็บหลายประเภท rate:
 *   - rate_type = 'addon'   → global lookup (breakfast, extra_bed, ...) ใช้ code เป็น key
 *   - rate_type = 'daily'   → room rate รายวัน ผูกกับ room_type_id
 *   - rate_type = 'group'   → room rate สำหรับ group booking ผูกกับ room_type_id
 *   - rate_type = 'month'   → room rate รายเดือน ผูกกับ room_type_id
 *
 * room rate rows จะมี code = NULL และ room_type_id = <id>
 * addon rate rows จะมี code = <slug> และ room_type_id = NULL
 */
class GlobalRate extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'global_rates';

    protected $fillable = [
        'rate_type',
        'room_type_id',
        'code',
        'name_en',
        'name_th',
        'default_price',
        'is_active',
    ];

    protected $casts = [
        'default_price' => 'integer',
        // 🌟 Fix PostgreSQL strict boolean (03/07/26): PgBoolean cast
        'is_active' => \App\Casts\PgBoolean::class,
    ];

    /**
     * Room rate rows ผูกกับ RoomType (addon rows จะเป็น NULL)
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id', 'id');
    }

    // ----------------------------------------------------------------------
    // Addon rate lookups (rate_type = 'addon')
    // ----------------------------------------------------------------------

    /**
     * ดึงราคา default ของ addon ตาม code (เช่น 'breakfast', 'extra_bed')
     * ถ้าไม่พบหรือ inactive จะคืน 0
     *
     * Filter เฉพาะ rate_type='addon' เพื่อกัน query ไปโดน room rate rows
     */
    public static function getPrice(string $code): int
    {
        // 🌟 Fix PostgreSQL (03/07/26): where ใช้ DB::raw('TRUE') แทน PHP true
        $rate = self::where('code', $code)
            ->where('rate_type', 'addon')
            ->whereRaw('is_active = TRUE')
            ->first();

        return $rate ? $rate->default_price : 0;
    }

    /**
     * ดึง addon rate หลายตัวพร้อมกัน (cache-friendly)
     * คืน associative array [code => price]
     */
    public static function getPrices(array $codes): array
    {
        return self::whereIn('code', $codes)
            ->where('rate_type', 'addon')
            ->whereRaw('is_active = TRUE')
            ->pluck('default_price', 'code')
            ->toArray();
    }

    // ----------------------------------------------------------------------
    // Room rate lookups (rate_type = 'daily' | 'group' | 'month')
    // ----------------------------------------------------------------------

    /**
     * ดึง room rate ตาม RoomType + rate_type
     * ถ้าไม่พบหรือ inactive จะคืน 0
     *
     * @param RoomType|string  $roomType  Model instance หรือ room_type_id
     * @param string           $rateType  'daily' | 'group' | 'month'
     */
    public static function getRoomRate($roomType, string $rateType = 'daily'): int
    {
        $roomTypeId = $roomType instanceof RoomType ? $roomType->id : $roomType;

        $rate = self::where('room_type_id', $roomTypeId)
            ->where('rate_type', $rateType)
            ->whereRaw('is_active = TRUE')
            ->first();

        return $rate ? $rate->default_price : 0;
    }
}
