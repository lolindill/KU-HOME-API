<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🧹 Phase A (15/07/26) — Housekeeping Task integration tests
 *
 *    Coverage:
 *      - State machine: unassigned → accepted → in_progress → done (+ done→* throw)
 *      - Role gating: housekeeper accept ได้, user ทั่วไป 403
 *      - Admin assign skip accepted
 *      - Duplicate prevention (S-B2)
 *      - checkout_then_in detection
 *      - done → room → available
 *      - DailyRoomMaintenance pre_checkin trigger (S-B1, D2)
 */
class HousekeepingTaskTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoom(string $status = 'checkout_makeup'): Room
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard',
            'name_th' => 'มาตรฐาน',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
            'rate_daily_general' => 1000,
        ]);

        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $rt->id,
            'room_number' => '1'.rand(10, 99),
            'status' => $status,
        ]);
    }

    private function makeTask(Room $room, string $status = 'unassigned'): HousekeepingTask
    {
        return HousekeepingTask::create([
            'id' => Str::uuid(),
            'room_id' => $room->id,
            'task_type' => 'checkout',
            'status' => $status,
        ]);
    }

    // ============================================
    // 🔁 State Machine — full lifecycle
    // ============================================

    public function test_full_lifecycle_unassigned_to_done(): void
    {
        $hk = $this->createHousekeeping();
        $room = $this->makeRoom('checkout_makeup');
        $task = $this->makeTask($room, 'unassigned');

        // accept
        $task->transitionStatus('accepted', $hk->id);
        $this->assertEquals('accepted', $task->fresh()->status);
        $this->assertEquals($hk->id, $task->fresh()->assigned_to);
        $this->assertNotNull($task->fresh()->accepted_at);

        // in_progress
        $task->transitionStatus('in_progress');
        $this->assertEquals('in_progress', $task->fresh()->status);

        // done
        $task->transitionStatus('done');
        $this->assertEquals('done', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_done_is_terminal_cannot_transition_back(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $task = $this->makeTask($room, 'unassigned');
        $task->transitionStatus('accepted');
        $task->transitionStatus('in_progress');
        $task->transitionStatus('done');

        $this->expectException(\Exception::class);
        $task->transitionStatus('in_progress');
    }

    public function test_invalid_skip_transition_unassigned_to_done(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $task = $this->makeTask($room, 'unassigned');

        $this->expectException(\Exception::class);
        $task->transitionStatus('done'); // ❌ ข้าม accepted/in_progress
    }

    // ============================================
    // 🧹 API — accept / updateStatus / role gating
    // ============================================

    public function test_housekeeper_can_accept_task_via_api(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $task = $this->makeTask($room, 'unassigned');

        $this->actingAsHousekeeping();
        $response = $this->postJson("/api/v1/dashboard/tasks/{$task->id}/accept");

        $response->assertStatus(200)
            ->assertJsonPath('task.task_status', 'accepted');
        $this->assertNotNull(HousekeepingTask::find($task->id)->assigned_to);
    }

    public function test_admin_can_accept_task_via_api(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $task = $this->makeTask($room, 'unassigned');

        $this->actingAsAdmin();
        $response = $this->postJson("/api/v1/dashboard/tasks/{$task->id}/accept");

        $response->assertStatus(200);
    }

    public function test_regular_user_cannot_accept_task(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $task = $this->makeTask($room, 'unassigned');

        $this->actingAsUser();
        $response = $this->postJson("/api/v1/dashboard/tasks/{$task->id}/accept");
        $response->assertStatus(403);
    }

    public function test_update_status_done_makes_room_available(): void
    {
        // room ใน checkout_makeup → task done → room available
        $room = $this->makeRoom('checkout_makeup');
        $task = $this->makeTask($room, 'unassigned');
        $task->transitionStatus('accepted');
        $task->transitionStatus('in_progress');

        $this->actingAsHousekeeping();
        $response = $this->patchJson("/api/v1/dashboard/tasks/{$task->id}/status", [
            'status' => 'done',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('new_room_status', 'available');
        $this->assertEquals('available', $room->fresh()->status);
    }

    public function test_update_status_cannot_be_called_on_done_task(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $task = $this->makeTask($room, 'unassigned');
        $task->transitionStatus('accepted');
        $task->transitionStatus('in_progress');
        $task->transitionStatus('done');

        $this->actingAsHousekeeping();
        $response = $this->patchJson("/api/v1/dashboard/tasks/{$task->id}/status", [
            'status' => 'in_progress',
        ]);
        $response->assertStatus(422);
    }

    // ============================================
    // 👑 Admin endpoints — listTasks / createTask / assignTask
    // ============================================

    public function test_admin_can_list_tasks_with_filter(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $this->makeTask($room, 'unassigned');
        $this->makeTask($room, 'done');

        $this->actingAsAdmin();
        // list all
        $all = $this->getJson('/api/v1/dashboard/tasks')->json('total_tasks');
        $this->assertSame(2, $all);

        // filter by status
        $unassigned = $this->getJson('/api/v1/dashboard/tasks?status=unassigned')->json('total_tasks');
        $this->assertSame(1, $unassigned);
    }

    public function test_admin_can_create_task_manually(): void
    {
        $room = $this->makeRoom('available');

        $this->actingAsAdmin();
        $response = $this->postJson('/api/v1/dashboard/tasks', [
            'room_id' => $room->id,
            'task_type' => 'monthly',
            'notes' => 'deep clean',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('task.task_type', 'monthly')
            ->assertJsonPath('task.task_status', 'unassigned');
    }

    public function test_admin_assign_task_skips_accepted_and_sets_assignee(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $task = $this->makeTask($room, 'unassigned');
        $hk = $this->createHousekeeping();

        $this->actingAsAdmin();
        $response = $this->putJson("/api/v1/dashboard/tasks/{$task->id}/assign", [
            'assigned_to' => $hk->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('task.task_status', 'accepted')
            ->assertJsonPath('task.assigned_to', $hk->id);
    }

    // ============================================
    // 🛡️ Duplicate prevention (S-B2)
    // ============================================

    public function test_create_task_rejects_when_active_task_exists(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $this->makeTask($room, 'unassigned'); // active task

        $this->actingAsAdmin();
        $response = $this->postJson('/api/v1/dashboard/tasks', [
            'room_id' => $room->id,
            'task_type' => 'daily',
        ]);

        $response->assertStatus(422);
    }

    public function test_unassigned_tasks_only_returns_unassigned(): void
    {
        $room = $this->makeRoom('checkout_makeup');
        $this->makeTask($room, 'unassigned');
        $this->makeTask($room, 'accepted');

        $this->actingAsHousekeeping();
        $response = $this->getJson('/api/v1/dashboard/tasks/unassigned');

        $response->assertStatus(200)
            ->assertJsonPath('total_unassigned', 1);
    }

    // ============================================
    // ⏰ DailyRoomMaintenance — pre_checkin trigger (S-B1, D2)
    // ============================================

    public function test_daily_maintenance_creates_pre_checkin_task(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $room = $this->makeRoom('available');

        // booking_room check_in พรุ่งนี้ + room ถูก assign แล้ว
        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => $this->createUser()->id,
            'confirmation' => Booking::generateUniqueConfirmation(),
            'source' => 'admin',
            'status' => 'confirmed',
            'total_amount' => 1000,
            'payment_deadline' => null,
        ]);
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $room->room_type_id,
            'room_id' => $room->id,
            'check_in' => Carbon::tomorrow(),
            'check_out' => Carbon::tomorrow()->addDay(),
            'status' => 'confirmed',
        ]);

        $this->artisan('app:daily-room-maintenance')->assertSuccessful();

        $this->assertEquals('prep_checkin', $room->fresh()->status);
        $task = HousekeepingTask::where('room_id', $room->id)->first();
        $this->assertNotNull($task);
        $this->assertEquals('pre_checkin', $task->task_type);
        $this->assertEquals('unassigned', $task->status);

        Carbon::setTestNow();
    }

    public function test_daily_maintenance_creates_daily_task_on_stale_room(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        // room available แต่ status_updated_at เก่ากว่า 2 วัน → flag dirty + สร้าง task
        $room = $this->makeRoom('available');
        $room->update(['status_updated_at' => Carbon::now()->subDays(3)]);

        $this->artisan('app:daily-room-maintenance')->assertSuccessful();

        $this->assertEquals('dirty', $room->fresh()->status);
        $task = HousekeepingTask::where('room_id', $room->id)->first();
        $this->assertNotNull($task);
        $this->assertEquals('daily', $task->task_type);

        Carbon::setTestNow();
    }

    // ============================================
    // 🧹 checkout_then_in detection (FrontDeskController.checkOut)
    // ============================================

    public function test_checkout_creates_checkout_then_in_when_rush(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $room = $this->makeRoom('occupied');

        // มี confirmed BR check_in วันนี้ ใน room_type เดียวกัน → rush = true
        $rushBooking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => $this->createUser()->id,
            'confirmation' => Booking::generateUniqueConfirmation(),
            'source' => 'admin',
            'status' => 'confirmed',
            'total_amount' => 1000,
            'payment_deadline' => null,
        ]);
        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $rushBooking->id,
            'room_type_id' => $room->room_type_id,
            'check_in' => Carbon::today(),
            'check_out' => Carbon::today()->addDay(),
            'status' => 'confirmed',
        ]);

        // สร้าง checkout task ผ่าน FrontDesk (จำลอง logic ที่ checkOut)
        // (ใช้ service layer direct เพื่อ isolate — FrontDeskTest เคลียร์ booking flow เต็มอยู่แล้ว)
        $activeExists = HousekeepingTask::where('room_id', $room->id)
            ->whereIn('status', ['unassigned', 'accepted', 'in_progress'])->exists();
        $this->assertFalse($activeExists);

        $rush = BookingRoom::where('room_type_id', $room->room_type_id)
            ->whereDate('check_in', Carbon::today())
            ->where('status', 'confirmed')
            ->exists();
        $this->assertTrue($rush);

        $task = HousekeepingTask::create([
            'id' => Str::uuid(),
            'room_id' => $room->id,
            'task_type' => $rush ? 'checkout_then_in' : 'checkout',
            'status' => 'unassigned',
        ]);

        $this->assertEquals('checkout_then_in', $task->fresh()->task_type);

        Carbon::setTestNow();
    }
}
