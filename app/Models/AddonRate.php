<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class AddonRate extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'addon_rates';

    protected $fillable = [
        'code',
        'name_en',
        'name_th',
        'default_price',
        'is_active',
    ];

    protected $casts = [
        'default_price' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * ดึงราคา default ของ addon ตาม code (เช่น 'breakfast', 'extra_bed')
     * ถ้าไม่พบหรือ inactive จะคืน 0
     */
    public static function getPrice(string $code): int
    {
        $rate = self::where('code', $code)->where('is_active', true)->first();
        return $rate ? $rate->default_price : 0;
    }

    /**
     * ดึง rate หลายตัวพร้อมกัน (cache-friendly)
     * คืน associative array [code => price]
     */
    public static function getPrices(array $codes): array
    {
        return self::whereIn('code', $codes)
            ->where('is_active', true)
            ->pluck('default_price', 'code')
            ->toArray();
    }
}