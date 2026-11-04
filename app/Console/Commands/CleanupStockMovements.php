<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:cleanup-stock-movements {--dry-run : นับอย่างเดียว ไม่ลบจริง}')]
#[Description('Apply retention policy on stock movement logs (REQ-051 — keep 1 year / 365 days by default)')]
class CleanupStockMovements extends Command
{
    public function handle(): int
    {
        $this->info('📦 Starting stock movement log cleanup...');

        // 📌 REQ-051: ประวัติการเคลื่อนไหวสต๊อกเก็บย้อนหลัง 1 ปี (365 วัน)
        $retentionDays = max(1, (int) config('stock.movement_retention_days', 365));
        $cutoff = Carbon::now()->subDays($retentionDays);

        $query = StockMovement::where('created_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->line("  ⏸️ Dry run — would delete {$count} stock movement(s) older than {$cutoff->toDateString()}");

            return self::SUCCESS;
        }

        $deleted = (clone $query)->delete();

        $this->info("  ✓ Deleted {$deleted} stock movement(s) older than {$retentionDays} days (cutoff {$cutoff->toDateString()})");

        if ($deleted > 0) {
            Log::info('app:cleanup-stock-movements deleted stock movements', [
                'deleted' => $deleted,
                'retention_days' => $retentionDays,
                'cutoff' => $cutoff->toDateString(),
            ]);
        }

        return self::SUCCESS;
    }
}
