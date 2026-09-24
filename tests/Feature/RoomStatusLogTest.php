<?php

namespace Tests\Feature;

use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StatusChangeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 📝 Room Status History Log Tests (24/09/26, REQ-039 — wayfinder room-state-periods ticket 03)
 *
 * ตรวจว่า transitionStatusTo() ของ Room เขียน row ลง status_change_logs (entity_type='room')
 * endpoint /api/v1/rooms/{id}/status-logs (admin only) อ่านย้อนหลังได้
 * และ app:cleanup-status-logs ตัดเฉพาะ log ของห้องที่เกิน retention (log ของ booking ไม่ถูกแตะ)
 */
class RoomStatusLogTest extends TestCase
{
    use RefreshDatabase;

    // ============================================
    // 🛠️ Helpers
    // ============================================

    private function createRoomType(): RoomType
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Double',
            'name_th' => 'สแตนดาร์ด ดับเบิล',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'code' => null,
            'name_en' => 'Standard Double Daily',
            'default_price' => 1500,
            'is_active' => true,
        ]);

        return $rt;
    }

    private function createRoom(string $status = 'available'): Room
    {
        $roomType = $this->createRoomType();

        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '10'.rand(1, 99),
            'status' => $status,
        ]);
    }

    private function createLog(string $roomId, string $entityType, string $createdAt): StatusChangeLog
    {
        $log = StatusChangeLog::create([
            'entity_type' => $entityType,
            'entity_id' => $roomId,
            'from_status' => 'available',
            'to_status' => 'dirty',
            'role' => 'system',
            'causer_id' => null,
            'note' => null,
        ]);
        $log->created_at = Carbon::parse($createdAt);
        $log->save();

        return $log;
    }

    // ============================================
    // 📝 transitionStatusTo → audit log
    // ============================================

    public function test_room_transition_writes_audit_log(): void
    {
        $room = $this->createRoom('available');
        $actor = User::factory()->create(['role' => 'staff']);

        $room->transitionStatusTo('dirty', $actor->id);

        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room',
            'entity_id' => $room->id,
            'from_status' => 'available',
            'to_status' => 'dirty',
            'role' => 'staff',
            'causer_id' => $actor->id,
        ]);
    }

    public function test_same_status_no_op_writes_no_log(): void
    {
        $room = $this->createRoom('available');
        $actor = User::factory()->create(['role' => 'admin']);

        $this->assertFalse($room->transitionStatusTo('available', $actor->id));
        $this->assertDatabaseCount('status_change_logs', 0);
    }

    public function test_invalid_transition_writes_no_log(): void
    {
        $room = $this->createRoom('dirty');

        try {
            // dirty → occupied ผิด flow (dirty → available/checkout_makeup เท่านั้น)
            $room->transitionStatusTo('occupied');
            $this->fail('ควร throw exception สำหรับ invalid transition');
        } catch (\Exception $e) {
            $this->assertSame(422, $e->getCode());
        }

        $this->assertDatabaseCount('status_change_logs', 0);
    }

    public function test_system_transition_logs_system_role_with_null_causer(): void
    {
        $room = $this->createRoom('dirty');

        // cron/queue — ไม่มี user id (เช่น DailyRoomMaintenance)
        $room->transitionStatusTo('available');

        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room',
            'entity_id' => $room->id,
            'from_status' => 'dirty',
            'to_status' => 'available',
            'role' => 'system',
            'causer_id' => null,
        ]);
    }

    // ============================================
    // 🌐 GET /rooms/{id}/status-logs (admin only)
    // ============================================

    public function test_admin_can_read_room_status_logs(): void
    {
        $room = $this->createRoom('available');
        $actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($actor, 'sanctum');
        $room->transitionStatusTo('dirty', $actor->id);
        $room->transitionStatusTo('available', $actor->id);

        $response = $this->getJson("/api/v1/rooms/{$room->id}/status-logs");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonCount(2, 'logs');

        $logs = $response->json('logs');
        $this->assertSame('available', $logs[0]['from_status']);
        $this->assertSame('dirty', $logs[0]['to_status']);
        $this->assertSame('dirty', $logs[1]['from_status']);
        $this->assertSame('available', $logs[1]['to_status']);
    }

    public function test_non_admin_cannot_read_room_status_logs(): void
    {
        $room = $this->createRoom();

        $this->actingAsUser()
            ->getJson("/api/v1/rooms/{$room->id}/status-logs")
            ->assertStatus(403);
    }

    public function test_unauthenticated_cannot_read_room_status_logs(): void
    {
        $room = $this->createRoom();

        $this->getJson("/api/v1/rooms/{$room->id}/status-logs")
            ->assertStatus(401);
    }

    public function test_status_logs_returns_404_for_missing_room(): void
    {
        $this->actingAsAdmin()
            ->getJson('/api/v1/rooms/'.Str::uuid().'/status-logs')
            ->assertStatus(404);
    }

    // ============================================
    // 🧹 app:cleanup-status-logs — retention 1 ปี (REQ-039)
    // ============================================

    public function test_cleanup_deletes_only_old_room_logs(): void
    {
        $room = $this->createRoom();

        $oldRoomLog = $this->createLog($room->id, 'room', Carbon::now()->subDays(400)->toDateString());
        $recentRoomLog = $this->createLog($room->id, 'room', Carbon::now()->subDays(30)->toDateString());
        // log ของ booking เก่าแค่ไหนก็ห้ามลบ (audit การเงิน/การจอง)
        $oldBookingLog = $this->createLog($room->id, 'booking', Carbon::now()->subDays(400)->toDateString());

        $this->artisan('app:cleanup-status-logs')->assertSuccessful();

        $this->assertDatabaseMissing('status_change_logs', ['id' => $oldRoomLog->id]);
        $this->assertDatabaseHas('status_change_logs', ['id' => $recentRoomLog->id]);
        $this->assertDatabaseHas('status_change_logs', ['id' => $oldBookingLog->id]);
    }

    public function test_cleanup_respects_retention_config(): void
    {
        $room = $this->createRoom();
        // log อายุ 100 วัน — รอดจาก default 365 แต่ตกเกณฑ์ถ้าตั้ง retention = 90
        $log = $this->createLog($room->id, 'room', Carbon::now()->subDays(100)->toDateString());

        config(['room_status_log.retention_days' => 90]);
        $this->artisan('app:cleanup-status-logs')->assertSuccessful();

        $this->assertDatabaseMissing('status_change_logs', ['id' => $log->id]);
    }

    public function test_cleanup_dry_run_deletes_nothing(): void
    {
        $room = $this->createRoom();
        $log = $this->createLog($room->id, 'room', Carbon::now()->subDays(400)->toDateString());

        $this->artisan('app:cleanup-status-logs', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('status_change_logs', ['id' => $log->id]);
    }
}
