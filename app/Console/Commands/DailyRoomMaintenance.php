<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Services\RoomAllocator\BookingPriority;
use App\Services\RoomAllocator\Dto\BookingRequestDto;
use App\Services\RoomAllocator\RoomAllocator;
use App\Services\RoomAllocator\Weights;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Signature('app:daily-room-maintenance')]
#[Description('Daily: auto-assign rooms, flag stale rooms dirty (+create task), prep_checkin tomorrow arrivals (+create task)')]
class DailyRoomMaintenance extends Command
{
    public function handle()
    {
        $this->info('🏨 Starting daily room maintenance...');
        $results = [];

        // =========================================================
        // 1. Auto-assign room numbers to bookings checking in today
        //    🏨 Phase 5: เปลี่ยนจาก first-available greedy → RoomAllocator (Hybrid+ v3 cluster)
        //       + เรียง bookings ตาม BookingPriority ก่อน (Suite → X09 → Twin → Most rooms → Checkout)
        //       เพื่อให้ booking ที่จัดยากได้สิทธิ์เลือกห้องก่อน (เหมือนที่นั่งเครื่องบิน)
        // =========================================================
        $today = Carbon::today();

        // 🌟 Refactor (25/06/26): check_in ย้ายไป BR-level แล้ว — filter ผ่าน whereHas('bookingRooms', ...)
        $todayBookings = Booking::with(['bookingRooms.addon', 'bookingRooms.booking', 'bookingRooms.roomType'])
            ->whereIn('status', ['paid', 'confirmed'])
            ->whereHas('bookingRooms', function ($br) use ($today) {
                $br->whereDate('check_in', $today);
            })
            ->get();

        $autoAssigned = 0;
        $allocator = app(RoomAllocator::class);
        $priority = app(BookingPriority::class);
        $weights = Weights::fromConfig();

        // เรียง bookings ตาม priority (Suite → X09-free → Twin → Most-rooms → Least-checkout)
        // เพื่อให้ booking ที่จัดยากที่สุดได้เลือกห้องก่อน → booking ถัดไปเห็นห้อง occupied ผ่าน DB อัตโนมัติ
        $sorted = $todayBookings->sort(function ($a, $b) use ($priority, $weights) {
            $brsA = $a->bookingRooms->map(fn ($br) => BookingRequestDto::fromModel($br))->all();
            $brsB = $b->bookingRooms->map(fn ($br) => BookingRequestDto::fromModel($br))->all();
            $traitsA = $priority::traits($a, $brsA, $weights);
            $traitsB = $priority::traits($b, $brsB, $weights);

            return $priority::compare($traitsA, $traitsB);
        })->values();

        DB::transaction(function () use ($sorted, $allocator, &$autoAssigned) {
            foreach ($sorted as $booking) {
                $unassignedRooms = $booking->bookingRooms->whereNull('room_id');

                if ($unassignedRooms->isEmpty()) {
                    continue;
                }

                $result = $allocator->allocate($unassignedRooms);

                if ($result->ok) {
                    foreach ($result->assignments as $brId => $roomId) {
                        BookingRoom::where('id', $brId)->update(['room_id' => $roomId]);
                        $autoAssigned++;
                    }
                    $this->line("  ✓ Cluster-assigned {$unassignedRooms->count()} room(s) for booking {$booking->confirmation} (algo: {$result->winner}, cost: ".round($result->cost, 1).')');
                } else {
                    // 🌟 เปลี่ยน Exception เป็นแค่ Warning เพื่อให้ระบบรันต่อได้จนจบค่ะ!
                    $failedTypes = $unassignedRooms->map(fn ($br) => $br->room_type_id)->unique()->implode(', ');
                    $errorMsg = "  ✗ Alert: จัดห้อง cluster ไม่ได้สำหรับ Booking: {$booking->confirmation} (Room Type: {$failedTypes}, algo: {$result->winner})";
                    $this->warn($errorMsg);
                    Log::warning($errorMsg);
                }
            }
        });

        $results['auto_assigned_rooms'] = $autoAssigned;
        $this->info("  ✓ Auto-assigned {$autoAssigned} room(s) to today's check-ins.");

        // =========================================================
        // 2. 🧹 Phase A (15/07/26): pre_checkin trigger — Fix S-B1, D2
        //    หา booking_room ที่ check_in พรุ่งนี้ + room ถูก assign แล้ว
        //    → set room → prep_checkin + สร้าง task 'pre_checkin'
        //    (ก่อนหน้านี้ prep_checkin เป็น dead state ไม่มี code ไหน set — ตอนนี้มีชีวิตแล้ว!)
        //
        //    ⚠️ ทำก่อน Phase 3 (stale→dirty) เพราะ prep_checkin เป็น priority สูงกว่า
        //       ถ้าทำหลัง ห้อง available ที่ stale จะถูก mark dirty ก่อน → ไม่สามารถ prep ได้ทันที
        // =========================================================
        $tomorrow = Carbon::tomorrow();

        $tomorrowBrs = BookingRoom::whereDate('check_in', $tomorrow)
            ->whereNotNull('room_id')
            ->whereIn('status', ['confirmed', 'draft'])
            ->with('room')
            ->get();

        $prepCheckinRooms = 0;
        $preCheckinTasks = 0;
        $preppedRoomIds = [];
        foreach ($tomorrowBrs as $br) {
            $room = $br->room;
            if (! $room) {
                continue;
            }

            // เฉพาะห้องที่ available/dirty/checkout_makeup จึงจะ prep ได้ (occupied ยังไม่ว่าง)
            if (! in_array($room->status, ['available', 'dirty', 'checkout_makeup'])) {
                continue;
            }

            try {
                $room->transitionStatusTo('prep_checkin');
                $prepCheckinRooms++;
                $preppedRoomIds[] = $room->id;

                // สร้าง task 'pre_checkin' ถ้ายังไม่มี active task
                $activeExists = HousekeepingTask::where('room_id', $room->id)
                    ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])
                    ->exists();
                if (! $activeExists) {
                    HousekeepingTask::create([
                        'id' => Str::uuid(),
                        'room_id' => $room->id,
                        'task_type' => 'pre_checkin',
                        'status' => 'unassigned',
                        'scheduled_for' => $tomorrow,
                        'notes' => 'Auto: prep for tomorrow check-in',
                    ]);
                    $preCheckinTasks++;
                }
                $this->line("  ✓ Set room {$room->room_number} → prep_checkin (tomorrow arrival)");
            } catch (\Exception $e) {
                // transition ไม่ผ่าน (เช่น status ปัจจุบันไม่ allow) → log + ข้าม
                $this->warn("  ✗ Cannot prep_checkin room {$room->room_number} (status: {$room->status}): {$e->getMessage()}");
            }
        }

        $results['rooms_set_prep_checkin'] = $prepCheckinRooms;
        $results['pre_checkin_tasks_created'] = $preCheckinTasks;
        $this->info("  ✓ Prep'd {$prepCheckinRooms} room(s) for tomorrow check-ins, created {$preCheckinTasks} pre_checkin task(s).");

        // =========================================================
        // 3. Stale rooms (available/checkout_makeup > 2 days) → dirty + สร้าง task
        //    🧹 Phase A (15/07/26): ตอนนี้ mark dirty พร้อมสร้าง task 'daily'
        //       ก่อนหน้านี้ mark dirty แต่ไม่สร้าง task → ห้อง dirty ไม่ขึ้น dashboard (gap ปิดแล้ว)
        //    ⚠️ ข้ามห้องที่ถูก prep_checkin ใน Phase 2 แล้ว
        // =========================================================
        $twoDaysAgo = Carbon::now()->subDays(2);

        $staleQuery = Room::whereIn('status', ['available', 'checkout_makeup'])
            ->where(function ($q) use ($twoDaysAgo) {
                $q->where('status_updated_at', '<', $twoDaysAgo)
                    ->orWhereNull('status_updated_at');
            });
        if (! empty($preppedRoomIds)) {
            $staleQuery->whereNotIn('id', $preppedRoomIds);
        }
        $staleRooms = $staleQuery->get();

        $flaggedDirty = 0;
        $dirtyTasksCreated = 0;
        foreach ($staleRooms as $room) {
            $originalStatus = $room->status;
            $room->transitionStatusTo('dirty');
            $flaggedDirty++;

            // 🧹 สร้าง task 'daily' ถ้ายังไม่มี active task ในห้องนี้
            $activeExists = HousekeepingTask::where('room_id', $room->id)
                ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])
                ->exists();
            if (! $activeExists) {
                HousekeepingTask::create([
                    'id' => Str::uuid(),
                    'room_id' => $room->id,
                    'task_type' => 'daily',
                    'status' => 'unassigned',
                    'notes' => "Auto: stale room flagged dirty (was '{$originalStatus}')",
                ]);
                $dirtyTasksCreated++;
            }
            $this->line("  ✓ Flagged room {$room->room_number} as dirty (was '{$originalStatus}')");
        }

        $results['rooms_flagged_dirty'] = $flaggedDirty;
        $results['daily_tasks_created'] = $dirtyTasksCreated;
        $this->info("  ✓ Flagged {$flaggedDirty} stale room(s) as dirty, created {$dirtyTasksCreated} daily task(s).");

        // Log results
        Log::info('Daily room maintenance completed', $results);
        $this->info('✅ Daily room maintenance completed successfully.');

        return Command::SUCCESS;
    }
}
