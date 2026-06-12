<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

#[Signature('app:cleanup-expired-drafts')]
#[Description('Cleanup expired draft bookings whose payment_deadline has passed')]
class CleanupExpiredDrafts extends Command
{
    public function handle()
    {
        $this->info('🧹 Starting cleanup of expired draft bookings...');
        $now = Carbon::now();

        $expiredDrafts = Booking::where('status', 'draft')
            ->where('payment_deadline', '<', $now)
            ->get();

        if ($expiredDrafts->isEmpty()) {
            $this->info('  ✓ No expired draft bookings found. All clean!');
            return Command::SUCCESS;
        }

        $cleaned = 0;
        foreach ($expiredDrafts as $booking) {
            try {
                $booking->transitionStatus('deleted', 'system');
                $cleaned++;
                $this->line("  ✓ Expired draft deleted: {$booking->confirmation} (deadline: {$booking->payment_deadline})");
            } catch (\Exception $e) {
                $this->warn("  ✗ Failed to delete draft {$booking->confirmation}: {$e->getMessage()}");
                Log::warning("Failed to cleanup expired draft booking", [
                    'booking_id' => $booking->id,
                    'confirmation' => $booking->confirmation,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $results = ['expired_drafts_cleaned' => $cleaned];
        Log::info('Expired draft cleanup completed', $results);
        $this->info("  ✓ Cleaned up {$cleaned} expired draft booking(s).");
        $this->info('✅ Cleanup completed successfully.');

        return Command::SUCCESS;
    }
}