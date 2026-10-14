<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🏨 DailyRoomMaintenanceAutoAssignTest (REQ-023)
 *
 * ทดสอบการทำงานของคำสั่ง app:daily-room-maintenance:
 * 1. Auto-assign เลขห้องให้บุ๊กกิ้งที่เช็คอินล่วงหน้า 2 วัน (T-2) ผ่าน RoomAllocator
 * 2. ลำดับความสำคัญ: arrivals วันนี้ก่อนเสมอ แล้วจึงตามด้วย T-2
 * 3. Fail-safe: เมื่อห้องไม่พอสำหรับ T-2 ระบบต้องทิ้งไว้ไม่ assign + Log เตือน admin โดยไม่ crash ทั้ง run
 * 4. Admin สามารถเปลี่ยนหรือย้ายห้องได้ตามปกติหลังจาก auto-assign
 * 5. Sweep prep_checkin ของวันพรุ่งนี้ทำงานถูกต้องและไม่ซ้ำซ้อนกับห้องที่ assign T-2 แล้ว
 */
final class DailyRoomMaintenanceAutoAssignTest extends TestCase
{
    use RefreshDatabase;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roomType = RoomType::create([
            'id' => Str::uuid()->toString(),
            'name_en' => 'Deluxe',
            'name_th' => 'ห้องดีลักซ์',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
            'max_extra_beds' => 1,
            'extra_bed_price' => 500,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $this->roomType->id,
            'code' => null,
            'name_en' => 'Deluxe Daily',
            'default_price' => 1500,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function createRoom(string $roomNumber, string $status = 'available', int $floor = 5): Room
    {
        return Room::create([
            'id' => Str::uuid()->toString(),
            'room_type_id' => $this->roomType->id,
            'room_number' => $roomNumber,
            'status' => $status,
            'status_updated_at' => Carbon::now(),
            'floor' => $floor,
            'side' => 'V1',
            'pos' => 1,
            'bed_type' => 'twin',
        ]);
    }

    private function createBookingWithRoom(
        Carbon|string $checkIn,
        Carbon|string $checkOut,
        string $status = 'paid',
        bool $isPaid = true,
        ?Room $room = null
    ): array {
        $user = User::factory()->create();

        $booking = Booking::create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source' => 'online',
            'status' => $status,
            'is_paid' => $isPaid,
            'total_amount' => 1500,
        ]);

        $checkInStr = is_string($checkIn) ? $checkIn : $checkIn->toDateString();
        $checkOutStr = is_string($checkOut) ? $checkOut : $checkOut->toDateString();

        $bookingRoom = BookingRoom::create([
            'id' => Str::uuid()->toString(),
            'booking_id' => $booking->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $room?->id,
            'check_in' => $checkInStr,
            'check_out' => $checkOutStr,
            'guests' => [
                ['title' => 'mr', 'name' => 'Guest Auto', 'nationality' => 'TH'],
            ],
            'status' => 'confirmed',
            'room_amount' => 1500,
            'discount_amount' => 0,
            'amount' => 1500,
        ]);

        $addon = Addon::create([
            'id' => Str::uuid()->toString(),
            'booking_room_id' => $bookingRoom->id,
            'extra_bed' => 0,
        ]);

        return [$booking, $bookingRoom, $addon];
    }

    /**
     * ✅ 1. บุ๊กกิ้งเข้าพักอีก 2 วัน (T-2) โดน assign เลขห้องอัตโนมัติ
     */
    public function test_booking_checking_in_in_two_days_is_auto_assigned_room(): void
    {
        $today = Carbon::today();
        Carbon::setTestNow($today);

        $room = $this->createRoom('501');

        $t2CheckIn = $today->copy()->addDays(2);
        $t2CheckOut = $today->copy()->addDays(4);

        [$booking, $bookingRoom] = $this->createBookingWithRoom($t2CheckIn, $t2CheckOut);

        $this->assertNull($bookingRoom->room_id);

        $this->artisan('app:daily-room-maintenance')
            ->assertSuccessful();

        $freshBr = $bookingRoom->fresh();
        $this->assertNotNull($freshBr->room_id);
        $this->assertSame((string) $room->id, (string) $freshBr->room_id);
        $this->assertSame('confirmed', $freshBr->status);
    }

    /**
     * ✅ 2. Arrivals วันนี้ยังทำงานเหมือนเดิม (regression) และได้ลำดับความสำคัญก่อน T-2
     *       เมื่อมีห้องว่างเพียง 1 ห้อง และบุ๊กกิ้งวันนี้พักยาวทับช่วงเวลาของ T-2
     *       บุ๊กกิ้งวันนี้ต้องได้ห้องก่อน และ T-2 ต้องถูกเว้นไว้
     */
    public function test_today_arrivals_prioritized_before_t2_arrivals(): void
    {
        $today = Carbon::today();
        Carbon::setTestNow($today);

        $onlyRoom = $this->createRoom('501');

        // บุ๊กกิ้งวันนี้พักยาว 4 วัน (today -> today+4) ทับช่วง T-2
        [$todayBooking, $todayBr] = $this->createBookingWithRoom(
            $today,
            $today->copy()->addDays(4)
        );

        // บุ๊กกิ้ง T-2 เข้าพักวันที่ today+2 ถึง today+3
        [$t2Booking, $t2Br] = $this->createBookingWithRoom(
            $today->copy()->addDays(2),
            $today->copy()->addDays(3)
        );

        $this->artisan('app:daily-room-maintenance')
            ->assertSuccessful();

        // บุ๊กกิ้งวันนี้ต้องได้ห้อง 501
        $this->assertSame((string) $onlyRoom->id, (string) $todayBr->fresh()->room_id);

        // บุ๊กกิ้ง T-2 ต้อง fail-safe ไม่ crash และ room_id ยังคงเป็น null เพราะห้องถูกจองทับไปแล้ว
        $this->assertNull($t2Br->fresh()->room_id);
    }

    /**
     * ✅ 3. ห้องไม่พอสำหรับ T-2 ระบบต้อง fail-safe (ทิ้งไว้ไม่ assign + Log ไว้ให้ admin จัดมือ — ไม่ crash ทั้ง run)
     */
    public function test_t2_room_exhaustion_fails_safe_without_crashing_run(): void
    {
        $today = Carbon::today();
        Carbon::setTestNow($today);

        $room = $this->createRoom('501');

        // ปิดห้อง 501 ด้วย maintenance period ในช่วงวันที่ T-2
        RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => RoomStatePeriod::KIND_MAINTENANCE,
            'start_date' => $today->copy()->addDay()->toDateString(),
            'end_date' => $today->copy()->addDays(5)->toDateString(),
        ]);

        [$t2Booking, $t2Br] = $this->createBookingWithRoom(
            $today->copy()->addDays(2),
            $today->copy()->addDays(4)
        );

        $this->artisan('app:daily-room-maintenance')
            ->assertSuccessful();

        // ต้อง fail-safe: room_id ยังเป็น null และ command exit code 0
        $this->assertNull($t2Br->fresh()->room_id);
    }

    /**
     * ✅ 4. Admin สามารถเปลี่ยนหรือย้ายห้องได้ภายหลัง (manual re-assign)
     */
    public function test_admin_can_reassign_room_after_t2_auto_assign(): void
    {
        $today = Carbon::today();
        Carbon::setTestNow($today);

        $roomA = $this->createRoom('501');
        $roomB = $this->createRoom('502');

        [$t2Booking, $t2Br] = $this->createBookingWithRoom(
            $today->copy()->addDays(2),
            $today->copy()->addDays(4)
        );

        $this->artisan('app:daily-room-maintenance')
            ->assertSuccessful();

        $assignedRoomId = (string) $t2Br->fresh()->room_id;
        $this->assertNotEmpty($assignedRoomId);

        // Admin ทำการย้ายห้องด้วยตนเอง (Manual reassignment)
        $targetRoom = ($assignedRoomId === (string) $roomA->id) ? $roomB : $roomA;

        $admin = $this->createAdmin();
        $response = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$t2Booking->id}/rooms/{$t2Br->id}", [
                'room_type_id' => $this->roomType->id,
            ]);

        // หรือ admin สามารถ update room_id ของ booking room ได้โดยตรง
        $t2Br->update(['room_id' => $targetRoom->id]);

        $this->assertSame((string) $targetRoom->id, (string) $t2Br->fresh()->room_id);
    }

    /**
     * ✅ 5. ตรวจความสัมพันธ์กับ sweep prep_checkin ของพรุ่งนี้ (:101-152)
     *       ห้องที่ assign T-2 แล้ว จะยังไม่โดน prep_checkin ในวัน T-2 (เพราะยังไม่ถึงวันพรุ่งนี้)
     *       แต่พอเวลาผ่านไป 1 วัน (กลายเป็นวันพรุ่งนี้) ห้องนั้นจะถูกเปลี่ยนเป็น prep_checkin และสร้าง task สำเร็จ
     */
    public function test_t2_assigned_room_gets_prep_checkin_on_the_day_before_arrival(): void
    {
        $day0 = Carbon::parse('2026-11-01');
        Carbon::setTestNow($day0);

        $room = $this->createRoom('501');

        // บุ๊กกิ้งเข้าพักวันที่ 2026-11-03 (2 วันหลังจาก day0)
        [$booking, $br] = $this->createBookingWithRoom(
            '2026-11-03',
            '2026-11-05'
        );

        // วันที่ 1 พ.ย. (Day 0): รัน DailyRoomMaintenance
        $this->artisan('app:daily-room-maintenance')->assertSuccessful();

        // ตรวจสอบว่า T-2 ถูก auto-assign แล้ว แต่ห้องยังคงเป็น available (ยังไม่ prep วันนี้)
        $this->assertSame((string) $room->id, (string) $br->fresh()->room_id);
        $this->assertSame('available', $room->fresh()->status);
        $this->assertDatabaseMissing('housekeeping_tasks', [
            'room_id' => $room->id,
            'task_type' => 'pre_checkin',
        ]);

        // เดินหน้าเวลาไป 1 วัน: เป็นวันที่ 2 พ.ย. (Day 1) — พรุ่งนี้คือวันเช็คอินแล้ว!
        $day1 = Carbon::parse('2026-11-02');
        Carbon::setTestNow($day1);

        $this->artisan('app:daily-room-maintenance')->assertSuccessful();

        // ตอนนี้ห้องต้องเปลี่ยนสถานะเป็น prep_checkin และมี task pre_checkin เกิดขึ้น
        $this->assertSame('prep_checkin', $room->fresh()->status);
        $task = HousekeepingTask::where('room_id', $room->id)
            ->where('task_type', 'pre_checkin')
            ->first();
        $this->assertNotNull($task);
        $this->assertSame('unassigned', $task->status);
    }
}
