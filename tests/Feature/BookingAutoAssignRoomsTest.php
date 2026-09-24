<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🧪 BookingAutoAssignRoomsTest — Feature test suite สำหรับ endpoint:
 *    PUT /api/v1/bookings/{bookingId}/assign-rooms
 *
 * 🏨 ครอบคลุม:
 *    - Auth & Role protection (401 unauthenticated, 403 non-admin)
 *    - Validation & Gate checks (404 unknown id, 422 draft/pending status, 422 no rooms)
 *    - Idempotent recall (200 status=info เมื่อห้องทั้งหมดมี room_id แล้ว)
 *    - Happy path cluster allocation (Deluxe + Suite, distinct rooms)
 *    - Allocator constraints (bed_preference king_size, overlap exclusion)
 *    - Partial assignment & Rollback on failure
 */
final class BookingAutoAssignRoomsTest extends TestCase
{
    use RefreshDatabase;

    private array $typeIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTopology();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 1) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    /**
     * 🏨 Seed topology 100 ห้อง (Superior, Deluxe, Suite) ชั้น 5-9 เหมือน RoomSeeder
     * ชั้น 8 มี bed_type = 'king_size' ส่วนชั้นอื่นเป็น 'twin'
     */
    private function seedTopology(): void
    {
        $this->typeIds = [
            'Superior' => Str::uuid()->toString(),
            'Deluxe' => Str::uuid()->toString(),
            'Suite' => Str::uuid()->toString(),
        ];

        foreach ([
            ['Superior', 'Superior', 'ห้องซูพีเรียร์', 2, false, 0, 0, 1000],
            ['Deluxe',   'Deluxe',   'ห้องดีลักซ์',     2, true,  1, 500, 1800],
            ['Suite',    'Suite',    'ห้องสวีท',        4, true,  2, 600, 3500],
        ] as [$key, $en, $th, $max, $extraEnabled, $maxExtra, $extraPrice, $rate]) {
            RoomType::create([
                'id' => $this->typeIds[$key],
                'name_en' => $en,
                'name_th' => $th,
                'max_guests' => $max,
                'extra_bed_enabled' => $extraEnabled,
                'max_extra_beds' => $maxExtra,
                'extra_bed_price' => $extraPrice,
            ]);

            GlobalRate::create([
                'rate_type' => 'daily',
                'room_type_id' => $this->typeIds[$key],
                'code' => null,
                'name_en' => $en.' Daily',
                'default_price' => $rate,
                'is_active' => true,
            ]);
        }

        $floors = [5, 6, 7, 8, 9];
        foreach ($floors as $floor) {
            $bedType = $floor === 8 ? 'king_size' : 'twin';
            $v1 = [
                ['07', 'Suite',  'V1', 1, 0],
                ['08', 'Deluxe', 'V1', 2, 0],
                ['09', 'Deluxe', 'V1', 3, 2], // X09
                ['10', 'Deluxe', 'V1', 4, 0],
                ['11', 'Deluxe', 'V1', 5, 0],
                ['12', 'Deluxe', 'V1', 6, 0],
                ['13', 'Deluxe', 'V1', 7, 0],
                ['14', 'Deluxe', 'V1', 8, 0],
                ['15', 'Deluxe', 'V1', 9, 0],
                ['16', 'Deluxe', 'V1', 10, 0],
                ['17', 'Deluxe', 'V1', 11, 0],
                ['18', 'Suite',  'V1', 12, 0],
            ];
            $v2a = [
                ['06', 'Superior', 'V2A', 1, 0],
                ['05', 'Superior', 'V2A', 2, 0],
                ['04', 'Superior', 'V2A', 3, 0],
                ['03', 'Superior', 'V2A', 4, 0],
                ['02', 'Superior', 'V2A', 5, 0],
                ['01', 'Superior', 'V2A', 6, 0],
            ];
            $v2b = [
                ['20', 'Superior', 'V2B', 1, 0],
                ['19', 'Superior', 'V2B', 2, 0],
            ];
            foreach (array_merge($v1, $v2a, $v2b) as $r) {
                Room::create([
                    'id' => Str::uuid()->toString(),
                    'room_type_id' => $this->typeIds[$r[1]],
                    'room_number' => "{$floor}{$r[0]}",
                    'status' => 'available',
                    'builtin_extra_beds' => $r[4],
                    'floor' => $floor,
                    'side' => $r[2],
                    'pos' => $r[3],
                    'bed_type' => $bedType,
                ]);
            }
        }
    }

    /**
     * 🏨 Helper สร้าง Booking พร้อมค่าเริ่มต้น
     */
    private function createBooking(array $overrides = []): Booking
    {
        $user = User::factory()->create();

        return Booking::create(array_merge([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'paid',
            'is_paid' => true,
            'total_amount' => 3000,
        ], $overrides));
    }

    /**
     * 🏨 Helper สร้าง BookingRoom พร้อม Addon record
     */
    private function createBookingRoom(
        Booking $booking,
        string $typeName,
        ?Room $room = null,
        string $checkIn = '2026-11-10',
        string $checkOut = '2026-11-12',
        string $status = 'confirmed',
        ?string $bedPreference = null,
        int $extraBeds = 0
    ): BookingRoom {
        $br = BookingRoom::create([
            'id' => Str::uuid()->toString(),
            'booking_id' => $booking->id,
            'room_type_id' => $this->typeIds[$typeName],
            'room_id' => $room?->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => [
                ['title' => 'mr', 'name' => 'Somchai Jaidee', 'nationality' => 'TH'],
            ],
            'status' => $status,
            'bed_preference' => $bedPreference,
            'room_amount' => 1500,
            'discount_amount' => 0,
            'amount' => 1500,
        ]);

        Addon::create([
            'id' => Str::uuid()->toString(),
            'booking_room_id' => $br->id,
            'extra_bed' => $extraBeds,
        ]);

        return $br;
    }

    // =========================================================================
    // 🛡️ Authentication & Authorization Tests
    // =========================================================================

    /**
     * ✅ 1. 401 unauthenticated เมื่อไม่มี token
     */
    public function test_unauthenticated_request_returns_401(): void
    {
        $bookingId = Str::uuid()->toString();

        $response = $this->putJson("/api/v1/bookings/{$bookingId}/assign-rooms");

        $response->assertStatus(401);
    }

    /**
     * ✅ 2. 403 non-admin authenticated user ถูกปฏิเสธโดย middleware role:admin
     */
    public function test_non_admin_authenticated_user_returns_403(): void
    {
        $this->actingAsUser();
        $booking = $this->createBooking();

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Forbidden: พื้นที่หวงห้ามค่ะ! สิทธิ์ของนายท่านไม่เพียงพอ 🥺',
            ]);
    }

    /**
     * ✅ 3. 403 non-admin booking owner ถูกปฏิเสธโดย middleware role:admin
     *       (แม้ controller จะมี check `$booking->user_id !== $user->id` แต่ middleware ดักไว้ก่อนเสมอ)
     */
    public function test_non_admin_booking_owner_is_also_forbidden_by_route_role_admin_middleware(): void
    {
        $user = $this->createUser();
        $this->actingAs($user, 'sanctum');
        $booking = $this->createBooking(['user_id' => $user->id]);

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(403)
            ->assertJson([
                'error' => 'Forbidden: พื้นที่หวงห้ามค่ะ! สิทธิ์ของนายท่านไม่เพียงพอ 🥺',
            ]);
    }

    // =========================================================================
    // 🔍 Validation & Status Gate Tests
    // =========================================================================

    /**
     * ✅ 4. 404 เมื่อไม่พบบุ๊กกิ้งตาม UUID ที่ส่งมา
     */
    public function test_unknown_booking_id_returns_404(): void
    {
        $this->actingAsAdmin();
        $unknownId = Str::uuid()->toString();

        $response = $this->putJson("/api/v1/bookings/{$unknownId}/assign-rooms");

        $response->assertStatus(404)
            ->assertJson([
                'status' => 'error',
                'message' => 'ไม่พบข้อมูลการจองนี้ในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ',
            ]);
    }

    /**
     * ✅ 5. 422 เมื่อบุ๊กกิ้งอยู่ในสถานะ 'draft'
     */
    public function test_booking_in_draft_status_returns_422(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'draft', 'is_paid' => false]);
        $this->createBookingRoom($booking, 'Deluxe');

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
        $this->assertStringContainsString("ยังระบุเลขห้องไม่ได้ค่ะนายท่าน! สถานะปัจจุบันคือ 'draft'", $response->json('message'));
    }

    /**
     * ✅ 6. 422 เมื่อบุ๊กกิ้งอยู่ในสถานะ 'pending'
     */
    public function test_booking_in_pending_status_returns_422(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'pending', 'is_paid' => false]);
        $this->createBookingRoom($booking, 'Deluxe');

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
        $this->assertStringContainsString("ยังระบุเลขห้องไม่ได้ค่ะนายท่าน! สถานะปัจจุบันคือ 'pending'", $response->json('message'));
    }

    /**
     * ✅ 7. 422 เมื่อบุ๊กกิ้งไม่มี booking_rooms เลย
     */
    public function test_booking_with_no_rooms_returns_422(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'paid']);

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'เอ๊ะ! บุ๊กกิ้งนี้ยังไม่มีการจองห้องพักเข้ามาเลยนะคะนายท่าน! 💦',
            ]);
    }

    /**
     * ✅ 8. 200 status=info idempotent recall เมื่อทุกห้องมี room_id อยู่แล้ว
     */
    public function test_idempotent_recall_returns_200_info_when_all_rooms_already_assigned(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'paid']);

        $room1 = Room::where('room_type_id', $this->typeIds['Deluxe'])->firstOrFail();
        $room2 = Room::where('room_type_id', $this->typeIds['Suite'])->firstOrFail();

        $br1 = $this->createBookingRoom($booking, 'Deluxe', room: $room1);
        $br2 = $this->createBookingRoom($booking, 'Suite', room: $room2);

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'info',
                'message' => 'ห้องพักทั้งหมดในบุ๊กกิ้งนี้ถูกระบุเลขห้องเรียบร้อยแล้วค่ะนายท่าน! ✨',
            ]);

        $this->assertSame((string) $room1->id, (string) $br1->fresh()->room_id);
        $this->assertSame((string) $room2->id, (string) $br2->fresh()->room_id);
    }

    // =========================================================================
    // 🏨 Room Allocation Happy Path & Logic Tests
    // =========================================================================

    /**
     * ✅ 9. 200 success happy path: บุ๊กกิ้ง 'paid' พร้อม 2 unassigned BRs (1 Deluxe + 1 Suite)
     */
    public function test_happy_path_paid_booking_with_deluxe_and_suite_assigns_rooms(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);

        $brDeluxe = $this->createBookingRoom(
            $booking,
            'Deluxe',
            room: null,
            checkIn: '2026-11-10',
            checkOut: '2026-11-12',
            status: 'confirmed'
        );
        $brSuite = $this->createBookingRoom(
            $booking,
            'Suite',
            room: null,
            checkIn: '2026-11-10',
            checkOut: '2026-11-12',
            status: 'confirmed'
        );

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'allocation' => [
                    'algo' => 'Hybrid+ (C+D+A+FF)',
                ],
            ]);

        $json = $response->json();
        $this->assertStringContainsString('2 ห้อง', $json['message']);
        $this->assertIsString($json['allocation']['winner']);
        $this->assertIsNumeric($json['allocation']['cost']);

        $responseBookingRooms = $json['booking']['booking_rooms'];
        $this->assertCount(2, $responseBookingRooms);
        foreach ($responseBookingRooms as $rbr) {
            $this->assertNotNull($rbr['room_id']);
        }

        // DB assertions: booking status ยังคงเป็น 'paid', BR status ยังคงเป็น 'confirmed'
        $this->assertSame('paid', $booking->fresh()->status);

        $freshDeluxe = $brDeluxe->fresh();
        $freshSuite = $brSuite->fresh();

        $this->assertNotNull($freshDeluxe->room_id);
        $this->assertSame('confirmed', $freshDeluxe->status);

        $this->assertNotNull($freshSuite->room_id);
        $this->assertSame('confirmed', $freshSuite->status);

        // ตรวจสอบ room_type ของห้องที่ถูก assign
        $assignedDeluxeRoom = Room::findOrFail($freshDeluxe->room_id);
        $assignedSuiteRoom = Room::findOrFail($freshSuite->room_id);

        $this->assertSame((string) $this->typeIds['Deluxe'], (string) $assignedDeluxeRoom->room_type_id);
        $this->assertSame((string) $this->typeIds['Suite'], (string) $assignedSuiteRoom->room_type_id);
    }

    /**
     * ✅ 10. Distinct rooms: บุ๊กกิ้งที่มี 2 BRs ประเภทเดียวกัน ต้องได้ห้องที่ไม่ซ้ำกัน
     */
    public function test_distinct_rooms_assigned_for_same_room_type(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);

        $br1 = $this->createBookingRoom($booking, 'Deluxe', checkIn: '2026-11-10', checkOut: '2026-11-12');
        $br2 = $this->createBookingRoom($booking, 'Deluxe', checkIn: '2026-11-10', checkOut: '2026-11-12');

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(200)->assertJson(['status' => 'success']);

        $room1Id = (string) $br1->fresh()->room_id;
        $room2Id = (string) $br2->fresh()->room_id;

        $this->assertNotEmpty($room1Id);
        $this->assertNotEmpty($room2Id);
        $this->assertNotSame($room1Id, $room2Id);
    }

    /**
     * ✅ 11. bed_preference hard constraint: BR ที่ระบุ bed_preference='king_size' ต้องได้ห้องเตียงเดี่ยวใหญ่ (ชั้น 8)
     */
    public function test_bed_preference_hard_constraint_picks_king_size_room(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);

        $br = $this->createBookingRoom(
            $booking,
            'Deluxe',
            checkIn: '2026-11-10',
            checkOut: '2026-11-12',
            bedPreference: 'king_size'
        );

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(200)->assertJson(['status' => 'success']);

        $assignedRoom = Room::findOrFail($br->fresh()->room_id);
        $this->assertSame('king_size', $assignedRoom->bed_type);
        $this->assertSame(8, $assignedRoom->floor);
    }

    /**
     * ✅ 12. Allocator failure → 422: เมื่อ pool ของประเภทห้องที่ต้องการว่างเปล่า (เช่น maintenance ทั้งหมด)
     *        จะตอบกลับ 422 พร้อม rollback (room_id ยังเป็น null ใน DB)
     */
    public function test_allocator_failure_returns_422_and_rolls_back_changes(): void
    {
        $this->actingAsAdmin();

        // 🗓️ (24/09/26) room-state-periods: ปิดห้องด้วย maintenance period เปิดปลาย (แทน
        //    สถานะ maintenance ที่ถูกถอด) — Suite ทั้งหมดออกจาก pool ของ allocator
        Room::where('room_type_id', $this->typeIds['Suite'])->get()->each(
            fn (Room $room) => RoomStatePeriod::create([
                'room_id' => $room->id,
                'kind' => 'maintenance',
                'start_date' => '2026-01-01',
                'end_date' => null,
            ])
        );

        $booking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);
        $br = $this->createBookingRoom($booking, 'Suite');

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
        $this->assertStringContainsString('ไม่สามารถจัดห้องเป็น cluster ได้', $response->json('message'));
        $this->assertStringContainsString((string) $this->typeIds['Suite'], $response->json('message'));

        // ตรวจสอบ rollback: room_id ต้องคงเป็น null
        $this->assertNull($br->fresh()->room_id);
    }

    /**
     * ✅ 13. Overlap exclusion: ห้องที่ถูกจองแล้วในช่วงเวลาทับซ้อน จะไม่ถูกเลือก
     *        เหลือห้องว่างเพียง 1 ห้อง → ระบบต้องเลือกห้องที่ว่างเท่านั้น ไม่เลือกห้องที่ชน
     */
    public function test_overlap_exclusion_avoids_already_reserved_room(): void
    {
        $this->actingAsAdmin();

        // Suite มี 10 ห้องใน topology (ชั้น 5-9 ละ 2 ห้อง: 07, 18)
        // เก็บห้อง 507 (Room A) และ 518 (Room B) ไว้ นอกนั้นปิดด้วย maintenance period
        // 🗓️ (24/09/26) room-state-periods: แทนสถานะ maintenance ที่ถูกถอด
        $roomA = Room::where('room_type_id', $this->typeIds['Suite'])->where('room_number', '507')->firstOrFail();
        $roomB = Room::where('room_type_id', $this->typeIds['Suite'])->where('room_number', '518')->firstOrFail();

        Room::where('room_type_id', $this->typeIds['Suite'])
            ->whereNotIn('id', [$roomA->id, $roomB->id])
            ->get()
            ->each(fn (Room $room) => RoomStatePeriod::create([
                'room_id' => $room->id,
                'kind' => 'maintenance',
                'start_date' => '2026-01-01',
                'end_date' => null,
            ]));

        // บุ๊กกิ้งอื่นจอง Room A ไว้แล้วในช่วงวันที่ 2026-11-10 ถึง 2026-11-14
        $otherBooking = $this->createBooking(['status' => 'confirmed', 'is_paid' => true]);
        $this->createBookingRoom(
            $otherBooking,
            'Suite',
            room: $roomA,
            checkIn: '2026-11-10',
            checkOut: '2026-11-14',
            status: 'confirmed'
        );

        // Target booking ต้องการ Suite 1 ห้องในช่วง 2026-11-11 ถึง 2026-11-13 (overlap)
        $targetBooking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);
        $targetBr = $this->createBookingRoom(
            $targetBooking,
            'Suite',
            room: null,
            checkIn: '2026-11-11',
            checkOut: '2026-11-13',
            status: 'confirmed'
        );

        $response = $this->putJson("/api/v1/bookings/{$targetBooking->id}/assign-rooms");

        $response->assertStatus(200)->assertJson(['status' => 'success']);

        $assignedRoomId = (string) $targetBr->fresh()->room_id;
        $this->assertSame((string) $roomB->id, $assignedRoomId);
        $this->assertNotSame((string) $roomA->id, $assignedRoomId);
    }

    /**
     * ✅ 14. Partial assignment: บุ๊กกิ้งมี 2 ห้อง โดยห้องหนึ่งมี room_id แล้ว
     *        ระบบต้อง assign เฉพาะห้องที่ยังไม่มี room_id และไม่เปลี่ยนห้องที่ระบุไว้ก่อนหน้า
     */
    public function test_partial_assignment_only_assigns_unassigned_rooms(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'paid', 'is_paid' => true]);

        $preAssignedRoom = Room::where('room_type_id', $this->typeIds['Deluxe'])
            ->where('room_number', '508')
            ->firstOrFail();

        $br1 = $this->createBookingRoom(
            $booking,
            'Deluxe',
            room: $preAssignedRoom,
            checkIn: '2026-11-10',
            checkOut: '2026-11-12'
        );
        $br2 = $this->createBookingRoom(
            $booking,
            'Deluxe',
            room: null,
            checkIn: '2026-11-10',
            checkOut: '2026-11-12'
        );

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(200)->assertJson(['status' => 'success']);
        $this->assertStringContainsString('1 ห้อง', $response->json('message'));

        $this->assertSame((string) $preAssignedRoom->id, (string) $br1->fresh()->room_id);

        $assignedRoomId = (string) $br2->fresh()->room_id;
        $this->assertNotEmpty($assignedRoomId);
        $this->assertNotSame((string) $preAssignedRoom->id, $assignedRoomId);
    }

    /**
     * ✅ 15. บุ๊กกิ้งสถานะ 'confirmed' สามารถ assign ห้องได้เช่นเดียวกับ 'paid'
     */
    public function test_booking_in_confirmed_status_is_allowed(): void
    {
        $this->actingAsAdmin();
        $booking = $this->createBooking(['status' => 'confirmed', 'is_paid' => true]);
        $br = $this->createBookingRoom($booking, 'Superior');

        $response = $this->putJson("/api/v1/bookings/{$booking->id}/assign-rooms");

        $response->assertStatus(200)->assertJson(['status' => 'success']);
        $this->assertNotNull($br->fresh()->room_id);
        $this->assertSame('confirmed', $booking->fresh()->status);
    }
}
