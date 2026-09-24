<?php

namespace App\Console\Commands;

use App\Models\StatusChangeLog;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:cleanup-status-logs {--dry-run : นับอย่างเดียว ไม่ลบจริง}')]
#[Description('Apply retention policy on room status history logs (REQ-039 — keep 1 year by default, booking logs are never touched)')]
class CleanupStatusLogs extends Command
{
    public function handle()
    {
        $this->info('📝 Starting room status log cleanup...');

        // 📌 REQ-039: ประวัติสถานะห้องเก็บ 1 ปีย้อนหลัง — ตัดจริงที่ retention
        //    (env ROOM_STATUS_LOG_RETENTION_DAYS, default 365 วัน)
        //    🎯 ลดเฉพาะ entity_type='room' — log ของ booking/booking_room เป็นหลักฐาน
        //    ทางการเงิน/การจอง ห้ามแตะตามธรรมเนียม audit ของโปรเจกต์
        $retentionDays = max(1, (int) config('room_status_log.retention_days', 365));
        $cutoff = Carbon::now()->subDays($retentionDays);

        $query = StatusChangeLog::where('entity_type', 'room')
            ->where('created_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->line("  ⏸️ Dry run — would delete {$count} room status log(s) older than {$cutoff->toDateString()}");

            return self::SUCCESS;
        }

        $deleted = (clone $query)->delete();

        $this->info("  ✓ Deleted {$deleted} room status log(s) older than {$retentionDays} days (cutoff {$cutoff->toDateString()})");

        if ($deleted > 0) {
            Log::info('app:cleanup-status-logs deleted room status logs', [
                'deleted' => $deleted,
                'retention_days' => $retentionDays,
                'cutoff' => $cutoff->toDateString(),
            ]);
        }

        return self::SUCCESS;
    }
}
