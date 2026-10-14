<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 📜 StockMovement — ประวัติการเคลื่อนไหวของสต๊อก (REQ-051)
 *
 *    บันทึกทุกการเพิ่ม/ลด quantity (delta, reason, housekeeping_task_id, user_id)
 *    เก็บประวัติย้อนหลัง 365 วัน (มี cleanup command app:cleanup-stock-movements)
 */
class StockMovement extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'stock_inventory_id',
        'user_id',
        'housekeeping_task_id',
        'delta',
        'quantity_before',
        'quantity_after',
        'reason',
        'notes',
    ];

    protected $casts = [
        'delta' => 'integer',
        'quantity_before' => 'integer',
        'quantity_after' => 'integer',
    ];

    public function stockInventory(): BelongsTo
    {
        return $this->belongsTo(StockInventory::class, 'stock_inventory_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function housekeepingTask(): BelongsTo
    {
        return $this->belongsTo(HousekeepingTask::class, 'housekeeping_task_id');
    }
}
