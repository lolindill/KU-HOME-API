<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('app:cleanup-expired-drafts')]
#[Description('Hard delete expired draft bookings whose payment_deadline has passed (cascade BR + Addon + Payment)')]
class CleanupExpiredDrafts extends Command
{
    public function handle()
    {
        $this->info('🧹 Starting cleanup of expired draft bookings...');
        $now = Carbon::now();

        // 💳 (25/09/26, booking-payment-types ticket 03 + contract ticket 05 หัวข้อ 10):
        // - payment_type = 'deferred' → ยกเว้นตลอด (รอ admin confirm หรือลบเอง — org รอเอกสารได้ไม่จำกัดเวลา)
        // - draft ที่มี payments row → ห้ามลบเงียบ ๆ (เงินเข้าจริงแล้ว เช่น เก็บมัดจำสดหน้าเคาน์เตอร์
        //   ระหว่างยัง draft — hard-delete จะทำ ledger หาย)
        $expiredDrafts = Booking::where('status', 'draft')
            ->where('payment_deadline', '<', $now)
            ->where('payment_type', '!=', 'deferred')
            ->whereDoesntHave('payments')
            ->get();

        if ($expiredDrafts->isEmpty()) {
            $this->info('  ✓ No expired draft bookings found. All clean!');

            return Command::SUCCESS;
        }

        $cleaned = 0;
        foreach ($expiredDrafts as $booking) {
            try {
                // 🌟 Refactor (29/06/26): Hard delete cascade — ไม่มี 'cancelled' state แล้ว
                // Final plan: Delete all (BR + Addon + Payment ลบทิ้งหมด)
                DB::transaction(function () use ($booking) {
                    foreach ($booking->bookingRooms as $br) {
                        // ลบ Addon ที่ผูกกับ BR นี้
                        $br->addon()?->delete();
                        // ลบ BR เอง
                        $br->delete();
                    }
                    // ลบ Payment ที่ผูกกับ Booking นี้ (ถ้ามี)
                    $booking->payments()->delete();
                    // 🖼️ (19/08/26) ลบรูปสลิปก่อน booking หายไป — morph ไม่ cascade เอง
                    //    (defense-in-depth: draft แทบไม่มี confirmation แต่กันเหนียวเหมือน destroyBooking)
                    $booking->confirmations->each(fn ($confirmation) => $confirmation->slipImage?->delete());
                    // ลบ Booking container เป็นอันดับสุดท้าย
                    $booking->delete();
                });

                $cleaned++;
                $this->line("  ✓ Expired draft deleted: {$booking->confirmation} (deadline: {$booking->payment_deadline})");
            } catch (\Exception $e) {
                $this->warn("  ✗ Failed to delete draft {$booking->confirmation}: {$e->getMessage()}");
                Log::warning('Failed to cleanup expired draft booking', [
                    'booking_id' => $booking->id,
                    'confirmation' => $booking->confirmation,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $results = ['expired_drafts_deleted' => $cleaned];
        Log::info('Expired draft cleanup completed', $results);
        $this->info("  ✓ Hard deleted {$cleaned} expired draft booking(s).");
        $this->info('✅ Cleanup completed successfully.');

        return Command::SUCCESS;
    }
}
