<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 📦 StockInventory — คลังสินค้า/วัสดุสิ้นเปลือง (REQ-051/052)
 *
 *    - Master stock คลังโรงแรม
 *    - Derived status: 'ok' / 'low' (<= reorder_point) / 'out' (<= 0)
 *    - SoftDeletes: คงข้อมูล master ไว้อ้างอิงใน stock_movements / reports ย้อนหลัง 1 ปี
 */
class StockInventory extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'item_code',
        'item_name',
        'category',
        'quantity',
        'unit',
        'reorder_point',
        'max_stock',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reorder_point' => 'integer',
        'max_stock' => 'integer',
    ];

    protected $appends = [
        'status',
    ];

    /**
     * 🏷️ สถานะ derived ตาม REQ-052:
     *    - 'out' เมื่อ quantity <= 0
     *    - 'low' เมื่อ quantity <= reorder_point
     *    - 'ok' เมื่อปกติ
     *    (backend ส่ง enum status อย่างเดียว — แถบสีให้ frontend render)
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->quantity <= 0) {
                return 'out';
            }

            if ($this->reorder_point !== null && $this->quantity <= $this->reorder_point) {
                return 'low';
            }

            return 'ok';
        });
    }

    /**
     * 📜 ประวัติการเคลื่อนไหวสต๊อก (audit trail 1 ปี)
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'stock_inventory_id');
    }

    /**
     * 🔄 บันทึกการเคลื่อนไหวสต๊อกและปรับปรุงยอดคงเหลือ
     */
    public function recordMovement(
        int $delta,
        string $reason,
        ?string $housekeepingTaskId = null,
        ?string $userId = null,
        ?string $notes = null
    ): StockMovement {
        $before = (int) $this->quantity;
        $after = $before + $delta;

        $movement = $this->movements()->create([
            'delta' => $delta,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reason' => $reason,
            'housekeeping_task_id' => $housekeepingTaskId,
            'user_id' => $userId,
            'notes' => $notes,
        ]);

        $this->quantity = $after;
        $this->save();

        return $movement;
    }
}
