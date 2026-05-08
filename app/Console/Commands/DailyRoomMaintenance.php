<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

#[Signature('app:daily-room-maintenance')]
#[Description('Daily tasks: auto-assign rooms for today check-ins & flag stale rooms as dirty')]
class DailyRoomMaintenance extends Command
{
    public function handle()
    {
        $this->info('🏨 Starting daily room maintenance...');
        $results = [];

        // =========================================================
        // 1. Auto-assign room numbers to bookings checking in today
        // =========================================================
        $today = Carbon::today();

        // โหลด bookingRooms.booking มาด้วยเพื่อป้องกัน N+1 Query ตอนเรียก $this->booking->check_in ใน Model ค่ะ
        $todayBookings = Booking::with(['bookingRooms.addon', 'bookingRooms.booking'])
            ->whereIn('status', ['paid', 'confirmed'])
            ->whereDate('check_in', $today)
            ->get();

        $autoAssigned = 0;

        // เอาตัวแปรที่ไม่ได้ใช้ออกจาก use() ให้หมดค่ะ
        DB::transaction(function () use ($todayBookings, &$autoAssigned) {
            foreach ($todayBookings as $booking) {
                $unassignedRooms = $booking->bookingRooms->whereNull('room_id');

                if ($unassignedRooms->isEmpty()) {
                    continue;
                }

                foreach ($unassignedRooms as $bookingRoom) {
                    $isSuccess = $bookingRoom->assignAvailableRoom();

                    if ($isSuccess) {
                        $autoAssigned++;
                        $this->line("  ✓ Auto-assigned room for booking: {$booking->confirmation}");
                    } else {
                        // 🌟 เปลี่ยน Exception เป็นแค่ Warning เพื่อให้ระบบรันต่อได้จนจบค่ะ!
                        $errorMsg = "  ✗ Alert: ไม่มีห้องว่างให้ระบุหมายเลขสำหรับ Booking: {$booking->confirmation} (Room Type: {$bookingRoom->room_type_id})";
                        $this->warn($errorMsg);
                        Log::warning($errorMsg);
                    }
                }
            }
        });

        $results['auto_assigned_rooms'] = $autoAssigned;
        $this->info("  ✓ Auto-assigned {$autoAssigned} room(s) to today's check-ins.");

        // =========================================================
        // 2. Check rooms with status 'available' or 'checkout_makeup'
        //    that haven't been updated in 2+ days → mark as 'dirty'
        // =========================================================
        $twoDaysAgo = Carbon::now()->subDays(2);

        $staleRooms = Room::whereIn('status', ['available', 'checkout_makeup'])
            ->where(function ($q) use ($twoDaysAgo) {
                $q->where('status_updated_at', '<', $twoDaysAgo)
                  ->orWhereNull('status_updated_at');
            })
            ->get();

        $flaggedDirty = 0;
        foreach ($staleRooms as $room) {
            $room->transitionStatusTo('dirty');
            $flaggedDirty++;
            $this->line("  ✓ Flagged room {$room->room_number} as dirty (status was '{$room->status}')");
        }

        $results['rooms_flagged_dirty'] = $flaggedDirty;
        $this->info("  ✓ Flagged {$flaggedDirty} stale room(s) as dirty.");

        // Log results
        Log::info('Daily room maintenance completed', $results);
        $this->info('✅ Daily room maintenance completed successfully.');

        return Command::SUCCESS;
    }
}