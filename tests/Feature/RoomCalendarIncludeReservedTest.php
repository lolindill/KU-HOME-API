<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🏨 include_reserved บน calendar 4 endpoints — per-day + ranges + flat lists (24/09/26)
 *
 *    (wayfinder/reserved-room-pool ticket 02 — re-base บน period model)
 *    "ห้องสำรอง" = ห้องติด reserved period (room_state_periods kind reserved) —
 *    admin ส่ง flag → occupied matrix ตัดเฉพาะ maintenance period (reserved period
 *    ไม่บล็อกวัน — ห้องสำรองกลับเข้า pool รายวัน) · maintenance absolute (กฎเหล็ก) ·
 *    non-admin/anonymous ส่ง flag → เมยายีเงียบ ๆ (byte-identical) · ไม่ส่ง → byte-identical
 */
class RoomCalendarIncludeReservedTest extends TestCase
{
    use RefreshDatabase;

    // counter แทน rand() — กันเลขห้องชนกันเอง (precedent FrontDeskTest)
    private static int $roomSeq = 0;

    private function createRoomType(): RoomType
    {
        return RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe Suite',
            'name_th' => 'ห้องทดสอบ',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
    }

    private function createRoom(RoomType $roomType): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '6'.(++self::$roomSeq),
            'status' => 'available',
            'bed_type' => 'twin',
        ]);
    }

    private function createReservedPeriod(Room $room, string $startDate, ?string $endDate = null): RoomStatePeriod
    {
        return RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'reserved',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    private function createMaintenancePeriod(Room $room, string $startDate, ?string $endDate = null): RoomStatePeriod
    {
        return RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    // ก้อน BR ที่ holdingSlot นับ (confirmed) — ผสม booking + period ใน matrix เดียว
    private function createBookedRoom(Room $room, string $checkIn, string $checkOut): BookingRoom
    {
        $booking = Booking::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'online',
            'status' => 'confirmed',
            'total_amount' => 3000,
        ]);

        return BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $room->room_type_id,
            'room_id' => $room->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => [['title' => 'mr', 'name' => 'Calendar Guest', 'nationality' => 'TH']],
            'status' => 'confirmed',
        ]);
    }

    private function fetchPerDayRow(string $uri, RoomType $roomType, array $params = []): array
    {
        $response = $this->getJson($uri.'?'.http_build_query($params));
        $response->assertStatus(200);

        $found = collect($response->json('room_types'))
            ->firstWhere('room_type_id', $roomType->id);

        $this->assertNotNull($found, "Created room type should appear in {$uri}");

        return $found;
    }

    private function calendarWindow(): array
    {
        // หน้าต่างสแกน [วันนี้+1, วันนี้+10] — period วางกลาง ๆ [วันนี้+5, วันนี้+8)
        return [
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ];
    }

    // ============================================
    // 🏨 Admin flag — per-day endpoint
    // ============================================

    public function test_admin_flag_per_day_counts_reserved_room_as_available(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $reservedRoom = $this->createRoom($roomType);
        $this->createReservedPeriod(
            $reservedRoom,
            now()->addDays(5)->toDateString(),
            now()->addDays(8)->toDateString()
        );

        $window = $this->calendarWindow();
        $inside = now()->addDays(6)->toDateString();   // วันใน period
        $outside = now()->addDays(9)->toDateString();  // วันหลัง period ปิด

        // ไม่ส่ง flag → ห้องติด reserved period หายจากเลขรายวัน
        $plain = $this->fetchPerDayRow('/api/v1/availability-per-day', $roomType, $window);
        $this->assertEquals(1, $plain[$inside]);
        $this->assertEquals(2, $plain[$outside]);

        // admin ส่ง flag → ห้องสำรองกลับเข้า pool เฉพาะวันที่ period ครอบไม่ถึง/หลุดแล้ว
        $flagged = $this->fetchPerDayRow('/api/v1/availability-per-day', $roomType, $window + ['include_reserved' => 'true']);
        $this->assertEquals(2, $flagged[$inside]);
        $this->assertEquals(2, $flagged[$outside]);
    }

    public function test_admin_flag_per_day_maintenance_period_still_blocks(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);
        $maintRoom = $this->createRoom($roomType);
        $this->createMaintenancePeriod(
            $maintRoom,
            now()->addDays(5)->toDateString(),
            now()->addDays(8)->toDateString()
        );

        $window = $this->calendarWindow();
        $inside = now()->addDays(6)->toDateString();

        // กฎเหล็ก absolute — ห้องติด maintenance period ไม่กลับมาแม้ admin ส่ง flag
        $plain = $this->fetchPerDayRow('/api/v1/availability-per-day', $roomType, $window);
        $this->assertEquals(1, $plain[$inside]);

        $flagged = $this->fetchPerDayRow('/api/v1/availability-per-day', $roomType, $window + ['include_reserved' => 'true']);
        $this->assertEquals(1, $flagged[$inside]);
    }

    public function test_admin_flag_per_day_records_search_criteria(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        // ไม่ส่ง flag → ไม่มี key search_criteria บน response กลาง
        $plain = $this->getJson('/api/v1/availability-per-day?'.http_build_query($this->calendarWindow()));
        $plain->assertStatus(200);
        $this->assertArrayNotHasKey('search_criteria', $plain->json());

        // ส่ง flag → response บันทึกว่าเลขมาจาก pool ที่รวมห้องสำรอง
        $flagged = $this->getJson('/api/v1/availability-per-day?'.http_build_query(
            $this->calendarWindow() + ['include_reserved' => 'true']
        ));
        $flagged->assertStatus(200);
        $this->assertTrue($flagged->json('search_criteria.include_reserved'));
    }

    // ============================================
    // 🏨 Admin flag — ranges endpoint (sold-out intervals)
    // ============================================

    public function test_admin_flag_ranges_sold_out_interval_disappears_with_reserved_room(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $reservedRoom = $this->createRoom($roomType);
        $this->createReservedPeriod($reservedRoom, now()->addDays(5)->toDateString(), now()->addDays(8)->toDateString());

        $window = $this->calendarWindow();

        // ไม่ส่ง flag → วันที่ period ครอบ sold-out (type มีห้องเดียว) → interval [D5, D7]
        $plain = $this->fetchPerDayRow('/api/v1/availability-ranges', $roomType, $window);
        $this->assertSame([['start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString()]], $plain['intervals']);

        // admin ส่ง flag → reserved period ไม่บล็อกวัน → ไม่มี interval เหลือ
        $flagged = $this->fetchPerDayRow('/api/v1/availability-ranges', $roomType, $window + ['include_reserved' => 'true']);
        $this->assertSame([], $flagged['intervals']);
    }

    public function test_admin_flag_ranges_intervals_shrink_only_by_reserved_not_by_booking(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $bookedRoom = $this->createRoom($roomType);
        $this->createBookedRoom(
            $bookedRoom,
            now()->addDays(1)->toDateString(),
            now()->addDays(10)->toDateString()
        );
        $reservedRoom = $this->createRoom($roomType);
        $this->createReservedPeriod(
            $reservedRoom,
            now()->addDays(5)->toDateString(),
            now()->addDays(8)->toDateString()
        );

        $window = $this->calendarWindow();

        // ไม่ส่ง flag → วัน D5–D7 เต็ม 2/2 (booking + reserved period) → sold-out interval เดียว
        $plain = $this->fetchPerDayRow('/api/v1/availability-ranges', $roomType, $window);
        $this->assertSame([['start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString()]], $plain['intervals']);

        // admin ส่ง flag → เหลือแค่ booking (1/2) — ไม่มีวัน sold-out แต่ booking ไม่หายไปไหน
        $flagged = $this->fetchPerDayRow('/api/v1/availability-ranges', $roomType, $window + ['include_reserved' => 'true']);
        $this->assertSame([], $flagged['intervals']);

        // หลักฐานว่า booking ยังถูกนับ: per-day วันในช่วง booking = 1 (2 ห้อง − 1 ที่ถูกจอง)
        $perDay = $this->fetchPerDayRow('/api/v1/availability-per-day', $roomType, $window + ['include_reserved' => 'true']);
        $this->assertEquals(1, $perDay[now()->addDays(6)->toDateString()]);
    }

    public function test_admin_flag_ranges_maintenance_interval_survives_flag(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $maintRoom = $this->createRoom($roomType);
        $this->createMaintenancePeriod($maintRoom, now()->addDays(5)->toDateString(), now()->addDays(8)->toDateString());

        // กฎเหล็ก absolute — interval จาก maintenance period อยู่ครบทั้งไม่ส่งและส่ง flag
        $plain = $this->fetchPerDayRow('/api/v1/availability-ranges', $roomType, $this->calendarWindow());
        $this->assertCount(1, $plain['intervals']);

        $flagged = $this->fetchPerDayRow('/api/v1/availability-ranges', $roomType, $this->calendarWindow() + ['include_reserved' => 'true']);
        $this->assertCount(1, $flagged['intervals']);
        $this->assertSame(
            ['start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString()],
            $flagged['intervals'][0]
        );
    }

    // ============================================
    // 🏨 Admin flag — flat list + automatic scan endpoints
    // ============================================

    public function test_admin_flag_unavailable_dates_drops_reserved_blocked_days(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $reservedRoom = $this->createRoom($roomType);
        $this->createReservedPeriod($reservedRoom, now()->addDays(5)->toDateString(), null);

        $window = $this->calendarWindow();

        // ไม่ส่ง flag → วัน D5..D10 จองไม่ได้ (period เปิดปลาย)
        $plain = $this->fetchPerDayRow('/api/v1/unavailable-dates', $roomType, $window);
        $this->assertSame(now()->addDays(5)->toDateString(), $plain['unavailable_dates'][0]);
        $this->assertCount(6, $plain['unavailable_dates']);

        // admin ส่ง flag → ไม่มีวันจองไม่ได้เลย
        $flagged = $this->fetchPerDayRow('/api/v1/unavailable-dates', $roomType, $window + ['include_reserved' => 'true']);
        $this->assertSame([], $flagged['unavailable_dates']);
    }

    public function test_admin_flag_unavailable_ranges_auto_scan_follows_same_semantics(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $reservedRoom = $this->createRoom($roomType);
        $this->createReservedPeriod($reservedRoom, now()->addDays(5)->toDateString(), null);

        // ไม่ส่ง flag → auto-scan (เริ่ม today+3) เจอ interval จาก reserved period เปิดปลาย
        $plain = $this->getJson('/api/v1/unavailable-ranges');
        $plain->assertStatus(200);
        $plainRow = collect($plain->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $this->assertNotEmpty($plainRow['intervals']);
        $this->assertArrayNotHasKey('search_criteria', $plain->json());

        // admin ส่ง flag → interval หาย + response บันทึก flag
        $flagged = $this->getJson('/api/v1/unavailable-ranges?include_reserved=true');
        $flagged->assertStatus(200);
        $flaggedRow = collect($flagged->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $this->assertSame([], $flaggedRow['intervals']);
        $this->assertTrue($flagged->json('search_criteria.include_reserved'));
    }

    // ============================================
    // 🔇 Silent-ignore + regression (byte-identical)
    // ============================================

    public function test_non_admin_flag_is_silently_ignored_on_all_calendar_endpoints(): void
    {
        $this->actingAsUser();
        $roomType = $this->createRoomType();
        $reservedRoom = $this->createRoom($roomType);
        $this->createRoom($roomType);
        $this->createReservedPeriod($reservedRoom, now()->addDays(5)->toDateString(), null);

        $window = $this->calendarWindow();

        // user role ส่ง flag → payload เท่าไม่ส่งทุกไบต์ ทั้ง 4 endpoints (ห้าม 403 / ห้ามขยาย pool)
        $pairs = [
            ['/api/v1/availability-per-day', $window],
            ['/api/v1/availability-ranges', $window],
            ['/api/v1/unavailable-dates', $window],
            ['/api/v1/unavailable-ranges', []],
        ];

        foreach ($pairs as [$uri, $baseParams]) {
            $plain = $this->getJson($uri.($baseParams ? '?'.http_build_query($baseParams) : ''));
            $flagged = $this->getJson($uri.($baseParams ? '?'.http_build_query($baseParams + ['include_reserved' => 'true']) : '?include_reserved=true'));

            $plain->assertStatus(200);
            $flagged->assertStatus(200);
            $this->assertSame(
                $plain->getContent(),
                $flagged->getContent(),
                "Non-admin flag must be byte-identical to no-flag on {$uri}"
            );
        }
    }

    public function test_anonymous_flag_is_silently_ignored_on_calendar(): void
    {
        $roomType = $this->createRoomType();
        $reservedRoom = $this->createRoom($roomType);
        $this->createReservedPeriod($reservedRoom, now()->addDays(5)->toDateString(), null);

        $window = $this->calendarWindow();

        $plain = $this->getJson('/api/v1/availability-per-day?'.http_build_query($window));
        $flagged = $this->getJson('/api/v1/availability-per-day?'.http_build_query($window + ['include_reserved' => 'true']));

        $plain->assertStatus(200);
        $flagged->assertStatus(200);
        $this->assertSame($plain->getContent(), $flagged->getContent());
    }

    public function test_no_flag_calendar_responses_stay_byte_identical_regression(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $reservedRoom = $this->createRoom($roomType);
        $this->createReservedPeriod($reservedRoom, now()->addDays(5)->toDateString(), now()->addDays(8)->toDateString());

        $window = $this->calendarWindow();
        $inside = now()->addDays(6)->toDateString();

        // regression — admin ไม่ส่ง flag: ทุก endpoint คืนรูปแบบเดิม (period ทุก kind บล็อก, ไม่มี key ใหม่)
        // type มีห้องเดียว + reserved period [D5, D8) → วัน D5–D7 sold-out (เลข per-day = 0)
        $blockedDays = [
            now()->addDays(5)->toDateString(),
            now()->addDays(6)->toDateString(),
            now()->addDays(7)->toDateString(),
        ];

        $perDay = $this->fetchPerDayRow('/api/v1/availability-per-day', $roomType, $window);
        $this->assertEquals(0, $perDay[$inside]);

        $ranges = $this->fetchPerDayRow('/api/v1/availability-ranges', $roomType, $window);
        $this->assertCount(1, $ranges['intervals']);

        $dates = $this->fetchPerDayRow('/api/v1/unavailable-dates', $roomType, $window);
        $this->assertSame($blockedDays, $dates['unavailable_dates']);

        foreach ([
            '/api/v1/availability-per-day?'.http_build_query($window),
            '/api/v1/availability-ranges?'.http_build_query($window),
            '/api/v1/unavailable-dates?'.http_build_query($window),
            '/api/v1/unavailable-ranges',
        ] as $uri) {
            $response = $this->getJson($uri);
            $response->assertStatus(200);
            $this->assertArrayNotHasKey('search_criteria', $response->json(), "No search_criteria without flag on {$uri}");
        }
    }
}
