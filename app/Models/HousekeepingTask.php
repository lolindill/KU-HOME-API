<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 🧹 Housekeeping Task (Phase A refactor — 15/07/26)
 *
 *    Task types:   pre_checkin | checkout | checkout_then_in | daily | monthly | group
 *    Status flow:  unassigned → accepted → in_progress → done (locked)
 *
 *    - status ก่อนหน้า 'pending' ถูก migrate ไป 'unassigned' แล้ว
 *    - assigned_to ตั้งค่าตอน accept (housekeeper) หรือ assign (admin)
 *    - done เป็น terminal state — transitionStatus() จะ throw ถ้าพยายามเปลี่ยนต่อ
 */
class HousekeepingTask extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    // 🌟 Fix (15/07/26): เปลี่ยนจาก $guarded = [] → $fillable (mass-assignment protection + match convention)
    protected $fillable = [
        'room_id',
        'assigned_to',
        'task_type',
        'status',
        'notes',
        'completed_at',
        'accepted_at',
        'scheduled_for',
    ];

    // 🌟 Fix L1 (03/07/26): missing casts — completed_at / accepted_at ใช้เป็น Carbon
    protected $casts = [
        'completed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'scheduled_for' => 'date',
    ];

    // 🧹 Drop (15/07/26): ปิด updated_at auto-manage — column ถูก drop แล้ว
    public const UPDATED_AT = null;

    // 🌟 Fix L2 (03/07/26): inverse relationships ที่หายไป
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(HousekeepingPhoto::class, 'task_id');
    }

    // 🧹 Phase A (15/07/26): ลบ inventories() relation — HousekeepingInventory ถูกลบแล้ว (Fix S-B3)

    /**
     * Task status state machine (Phase A refactor)
     *
     *   unassigned → accepted   (housekeeper accept หรือ admin assign)
     *   accepted   → in_progress
     *   in_progress → done      (terminal — lock)
     *   done       → *          ❌ throw (เขียนใหม่ทำงานจริง แทน dead-code guard เดิม Fix L3)
     *
     * คืน true ถ้า transition สำเร็จ, throw Exception (422) ถ้า invalid
     */
    public function transitionStatus(string $newStatus, ?string $userId = null): bool
    {
        $newStatus = strtolower($newStatus);
        $currentStatus = $this->status ?? 'unassigned';

        if ($currentStatus === $newStatus) {
            return false;
        }

        $allowedTransitions = [
            // key = target, value = allowed sources
            'accepted' => ['unassigned'],
            'in_progress' => ['accepted', 'unassigned'], // unassigned→in_progress ใช้ตอน admin assign skip accepted แล้วเริ่มทำเลย
            'done' => ['accepted', 'in_progress'],
        ];

        if (! isset($allowedTransitions[$newStatus])
            || ! in_array($currentStatus, $allowedTransitions[$newStatus], true)) {
            throw new Exception(
                "Invalid task status transition from '{$currentStatus}' to '{$newStatus}'.",
                422
            );
        }

        $this->status = $newStatus;

        // ตั้ง assigned_to + accepted_at ตอนเข้าสถานะ accepted
        if ($newStatus === 'accepted') {
            if ($userId) {
                $this->assigned_to = $userId;
            }
            $this->accepted_at = now();
        }

        // ตั้ง completed_at ตอน done
        if ($newStatus === 'done') {
            $this->completed_at = now();
        }

        $this->save();

        return true;
    }
}
