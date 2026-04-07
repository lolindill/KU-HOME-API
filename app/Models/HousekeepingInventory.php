<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class HousekeepingInventory extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'task_id',
        'item_name',
        'actual_quantity',
        'condition',
        'notes'
    ];

    // 🌟 Relationship: กลับไปหางานทำความสะอาด
    public function task()
    {
        return $this->belongsTo(HousekeepingTask::class, 'task_id');
    }
}