<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class HousekeepingPhoto extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'task_id',
        'photo_path'
    ];

    // 🌟 Relationship: กลับไปหางานทำความสะอาด
    public function task()
    {
        return $this->belongsTo(HousekeepingTask::class, 'task_id');
    }
}