<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 🧹 Phase A (15/07/26) — Master stock ลอย (D3)
 *
 *    เก็บ stock รวมของโรงแจม (item_name, quantity, unit) ไม่ผูก task
 *    ใช้สำหรับนับลาย / ดูปริมาณคงเหลือ ทดแทน HousekeepingInventory เดิมที่ผูกกับ task
 */
class StockInventory extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'item_name',
        'quantity',
        'unit',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];
}
