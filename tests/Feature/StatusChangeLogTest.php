<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\RoomType;
use App\Models\StatusChangeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 📝 Audit Log Tests (04/08/26)
 *
 * ตรวจว่า transitionStatus() ของ Booking และ BookingRoom เขียน row ลง status_change_logs
 * และ endpoint /api/v1/bookings/{id}/status-logs ทำงานถูกต้อง
 */
class StatusChangeLogTest extends TestCase
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

    private function createBooking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        return Booking::create(array_merge([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 3000,
        ], $overrides));
    }

    private function createBookingRoom(Booking $booking, RoomType $roomType, string $brStatus = 'draft'): BookingRoom
    {
        return BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => null,
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'guests' => [['title' => 'mr', 'name' => 'Guest', 'nationality' => 'TH']],
            'status' => $brStatus,
        ]);
    }

    // ============================================
    // 📝 Booking container transitions → audit log
    // ============================================

    public function test_booking_transition_writes_audit_log(): void
    {
        // draft → paid (role user)
        $booking = $this->createBooking(['status' => 'draft']);
        $user = User::factory()->create(['role' => 'user']);
        Auth::login($user);

        $booking->transitionStatus('paid', 'user');

        $log = StatusChangeLog::where('entity_type', 'booking')
            ->where('entity_id', $booking->id)
            ->first();

        $this->assertNotNull($log, 'audit log row ควรถูกสร้างหลัง transition');
        $this->assertSame('draft', $log->from_status);
        $this->assertSame('paid', $log->to_status);
        $this->assertSame('user', $log->role);
        $this->assertSame((string) $user->id, (string) $log->causer_id);
    }

    public function test_booking_system_transition_logs_null_causer(): void
    {
        // confirmed → complete โดย system (เช่นจาก syncStatusFromRooms) — ไม่มี logged-in user
        $booking = $this->createBooking(['status' => 'confirmed']);
        Auth::logout(); // จำลอง system/queue context

        $booking->transitionStatus('complete', 'system');

        $log = StatusChangeLog::where('entity_type', 'booking')->first();
        $this->assertNotNull($log);
        $this->assertSame('system', $log->role);
        $this->assertNull($log->causer_id, 'causer_id ต้องเป็น null สำหรับ system transition');
    }

    public function test_booking_invalid_transition_does_not_write_log(): void
    {
        // ลอง transition ผิด flow (draft → complete) ต้อง throw และไม่เขียน log
        $booking = $this->createBooking(['status' => 'draft']);

        try {
            $booking->transitionStatus('complete', 'admin');
            $this->fail('ควร throw exception สำหรับ invalid transition');
        } catch (\Exception $e) {
            $this->assertSame(422, $e->getCode());
        }

        $this->assertDatabaseCount('status_change_logs', 0);
    }

    // ============================================
    // 📝 BookingRoom transitions → audit log
    // ============================================

    public function test_booking_room_transition_writes_audit_log(): void
    {
        $roomType = $this->createRoomType();
        $booking = $this->createBooking(['status' => 'confirmed']);
        $br = $this->createBookingRoom($booking, $roomType, 'confirmed');

        $admin = $this->createAdmin();
        Auth::login($admin);

        $br->transitionStatus('checked_in', 'admin');

        $log = StatusChangeLog::where('entity_type', 'booking_room')
            ->where('entity_id', $br->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('confirmed', $log->from_status);
        $this->assertSame('checked_in', $log->to_status);
        $this->assertSame('admin', $log->role);
    }

    public function test_booking_room_no_show_transition_writes_audit_log(): void
    {
        $roomType = $this->createRoomType();
        $booking = $this->createBooking(['status' => 'confirmed']);
        $br = $this->createBookingRoom($booking, $roomType, 'confirmed');

        $br->transitionStatus('no_show', 'admin');

        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'booking_room',
            'entity_id' => $br->id,
            'from_status' => 'confirmed',
            'to_status' => 'no_show',
        ]);
    }

    // ============================================
    // 🔗 syncStatusFromRooms → system audit log
    // ============================================

    public function test_sync_status_from_rooms_logs_system_transition(): void
    {
        // BR ทุกห้อง checked_out → booking auto → complete (role system)
        $roomType = $this->createRoomType();
        $booking = $this->createBooking(['status' => 'confirmed']);
        $this->createBookingRoom($booking, $roomType, 'checked_out');

        $booking->syncStatusFromRooms();

        $this->assertSame('complete', $booking->fresh()->status);

        // ต้องมี row log ที่ role=system, from=confirmed, to=complete
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'booking',
            'entity_id' => $booking->id,
            'from_status' => 'confirmed',
            'to_status' => 'complete',
            'role' => 'system',
        ]);
    }

    // ============================================
    // 🌐 Endpoint tests
    // ============================================

    public function test_admin_can_get_status_logs(): void
    {
        $roomType = $this->createRoomType();
        $booking = $this->createBooking(['status' => 'paid']);
        $br = $this->createBookingRoom($booking, $roomType, 'confirmed');

        $admin = $this->createAdmin();
        Auth::login($admin);

        $booking->transitionStatus('confirmed', 'admin');
        $br->transitionStatus('checked_in', 'admin');

        $response = $this->actingAsAdmin()
            ->getJson('/api/v1/bookings/'.$booking->id.'/status-logs');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('booking_id', $booking->id)
            ->assertJsonCount(2, 'logs'); // 1 booking + 1 booking_room
    }

    public function test_non_admin_cannot_get_status_logs(): void
    {
        $booking = $this->createBooking(['status' => 'draft']);

        // role:user → ต้องโดน CheckRole เตะ 403
        $response = $this->actingAsUser()
            ->getJson('/api/v1/bookings/'.$booking->id.'/status-logs');

        $response->assertStatus(403);
    }

    public function test_status_logs_404_for_missing_booking(): void
    {
        $fakeId = Str::uuid()->toString();

        $response = $this->actingAsAdmin()
            ->getJson('/api/v1/bookings/'.$fakeId.'/status-logs');

        $response->assertStatus(404)
            ->assertJsonPath('status', 'error');
    }

    // ============================================
    // 🔄 Transaction rollback safety
    // ============================================

    public function test_audit_log_rolls_back_with_transition_on_failure(): void
    {
        // ถ้า transition เกิด แล้วโค้ดที่ตามมาใน transaction throw → log ต้อง rollback ด้วย
        $booking = $this->createBooking(['status' => 'draft']);

        try {
            DB::transaction(function () use ($booking) {
                $booking->transitionStatus('paid', 'user'); // เขียน log row

                // จำลอง error ที่ตามมาใน transaction
                throw new \Exception('simulated downstream error');
            });
            $this->fail('ควร throw exception');
        } catch (\Exception $e) {
            $this->assertSame('simulated downstream error', $e->getMessage());
        }

        // log row ต้องถูก rollback ไปด้วย
        $this->assertDatabaseCount('status_change_logs', 0);
        $this->assertSame('draft', $booking->fresh()->status);
    }
}
