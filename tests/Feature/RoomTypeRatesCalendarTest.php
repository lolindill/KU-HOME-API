<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoomTypeRatesCalendarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper to create a RoomType with associated active GlobalRate rows.
     */
    private function createRoomTypeWithRates(
        string $nameEn = 'Deluxe Room',
        string $nameTh = 'ห้องดีลักซ์',
        array $prices = [],
        bool $includeGroupRates = true
    ): RoomType {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => $nameEn,
            'name_th' => $nameTh,
            'max_guests' => 2,
            'extra_bed_enabled' => true,
            'max_extra_beds' => 1,
            'extra_bed_price' => 500, // 500 THB
        ]);

        $defaultPrices = [
            'daily' => 1200,        // 1200 THB
            'daily_ku' => 1000,     // 1000 THB
            'min_5_rooms' => 900,   // 900 THB
            'min_10_rooms' => 750,  // 750 THB
            'month' => 18000,       // 18000 THB
        ];
        $prices = array_merge($defaultPrices, $prices);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'name_en' => "{$nameEn} Daily General",
            'default_price' => $prices['daily'],
            'is_active' => true,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily_ku',
            'room_type_id' => $rt->id,
            'name_en' => "{$nameEn} Daily KU",
            'default_price' => $prices['daily_ku'],
            'is_active' => true,
        ]);

        if ($includeGroupRates) {
            GlobalRate::create([
                'rate_type' => 'group',
                'room_type_id' => $rt->id,
                'code' => 'min_5_rooms',
                'name_en' => "{$nameEn} Group Min 5",
                'default_price' => $prices['min_5_rooms'],
                'is_active' => true,
            ]);

            GlobalRate::create([
                'rate_type' => 'group',
                'room_type_id' => $rt->id,
                'code' => 'min_10_rooms',
                'name_en' => "{$nameEn} Group Min 10",
                'default_price' => $prices['min_10_rooms'],
                'is_active' => true,
            ]);
        }

        GlobalRate::create([
            'rate_type' => 'month',
            'room_type_id' => $rt->id,
            'name_en' => "{$nameEn} Monthly",
            'default_price' => $prices['month'],
            'is_active' => true,
        ]);

        return $rt;
    }

    /**
     * Helper to create a room for a room type.
     */
    private function createRoom(RoomType $roomType, string $status = 'available'): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => (string) rand(100, 999),
            'status' => $status,
        ]);
    }

    // =========================================================================
    // 📅 1. GET /api/v1/availability-per-day
    // =========================================================================

    public function test_availability_per_day_includes_rates_object_in_2dp_baht_strings(): void
    {
        $rt = $this->createRoomTypeWithRates('Superior', 'ห้องซูพีเรียร์');
        $this->createRoom($rt);

        $startDate = Carbon::today()->toDateString();
        $endDate = Carbon::today()->addDays(2)->toDateString();

        $response = $this->getJson("/api/v1/availability-per-day?start_date={$startDate}&end_date={$endDate}");
        $response->assertStatus(200);

        $roomTypes = $response->json('room_types');
        $this->assertNotEmpty($roomTypes);

        $row = collect($roomTypes)->firstWhere('room_type_id', (string) $rt->id);
        $this->assertNotNull($row);

        // Assert rates structure and 2-dp baht strings
        $this->assertArrayHasKey('rates', $row);
        $this->assertSame([
            'daily' => [
                'general' => 1200,
                'ku_member' => 1000,
            ],
            'group' => [
                'min_5_rooms' => 900,
                'min_10_rooms' => 750,
            ],
            'monthly' => 18000,
        ], $row['rates']);

        // Assert existing structure is preserved: date keys exist
        $this->assertArrayHasKey($startDate, $row);
        $this->assertArrayHasKey($endDate, $row);
        $this->assertSame('Superior', $row['name_en']);
        $this->assertSame('ห้องซูพีเรียร์', $row['name_th']);
    }

    // =========================================================================
    // 📅 2. GET /api/v1/availability-ranges
    // =========================================================================

    public function test_availability_ranges_includes_rates_object_in_2dp_baht_strings(): void
    {
        $rt = $this->createRoomTypeWithRates('Deluxe', 'ห้องดีลักซ์');
        $this->createRoom($rt);

        $startDate = Carbon::today()->toDateString();
        $endDate = Carbon::today()->addDays(7)->toDateString();

        $response = $this->getJson("/api/v1/availability-ranges?start_date={$startDate}&end_date={$endDate}");
        $response->assertStatus(200);

        $roomTypes = $response->json('room_types');
        $this->assertNotEmpty($roomTypes);

        $row = collect($roomTypes)->firstWhere('room_type_id', (string) $rt->id);
        $this->assertNotNull($row);

        $this->assertArrayHasKey('rates', $row);
        $this->assertSame([
            'daily' => [
                'general' => 1200,
                'ku_member' => 1000,
            ],
            'group' => [
                'min_5_rooms' => 900,
                'min_10_rooms' => 750,
            ],
            'monthly' => 18000,
        ], $row['rates']);

        // Assert existing structure is preserved: intervals array exists
        $this->assertArrayHasKey('intervals', $row);
        $this->assertIsArray($row['intervals']);
        $this->assertSame('Deluxe', $row['name_en']);
        $this->assertSame('ห้องดีลักซ์', $row['name_th']);
    }

    // =========================================================================
    // 📅 3. GET /api/v1/unavailable-dates
    // =========================================================================

    public function test_unavailable_dates_includes_rates_object_in_2dp_baht_strings(): void
    {
        $rt = $this->createRoomTypeWithRates('Suite', 'ห้องสวีท');
        $this->createRoom($rt);

        $startDate = Carbon::today()->toDateString();
        $endDate = Carbon::today()->addDays(5)->toDateString();

        $response = $this->getJson("/api/v1/unavailable-dates?start_date={$startDate}&end_date={$endDate}");
        $response->assertStatus(200);

        $roomTypes = $response->json('room_types');
        $this->assertNotEmpty($roomTypes);

        $row = collect($roomTypes)->firstWhere('room_type_id', (string) $rt->id);
        $this->assertNotNull($row);

        $this->assertArrayHasKey('rates', $row);
        $this->assertSame([
            'daily' => [
                'general' => 1200,
                'ku_member' => 1000,
            ],
            'group' => [
                'min_5_rooms' => 900,
                'min_10_rooms' => 750,
            ],
            'monthly' => 18000,
        ], $row['rates']);

        // Assert existing structure is preserved: unavailable_dates array exists
        $this->assertArrayHasKey('unavailable_dates', $row);
        $this->assertIsArray($row['unavailable_dates']);
        $this->assertSame('Suite', $row['name_en']);
        $this->assertSame('ห้องสวีท', $row['name_th']);
    }

    // =========================================================================
    // 📅 4. GET /api/v1/unavailable-ranges
    // =========================================================================

    public function test_unavailable_ranges_includes_rates_object_in_2dp_baht_strings_when_no_bookings(): void
    {
        $rt = $this->createRoomTypeWithRates('Standard Twin', 'สแตนดาร์ด ทวิน');
        $this->createRoom($rt);

        // When no active bookings, unavailableRanges returns start=null, end=null, intervals=[]
        $response = $this->getJson('/api/v1/unavailable-ranges');
        $response->assertStatus(200);

        $roomTypes = $response->json('room_types');
        $this->assertNotEmpty($roomTypes);

        $row = collect($roomTypes)->firstWhere('room_type_id', (string) $rt->id);
        $this->assertNotNull($row);

        $this->assertArrayHasKey('rates', $row);
        $this->assertSame([
            'daily' => [
                'general' => 1200,
                'ku_member' => 1000,
            ],
            'group' => [
                'min_5_rooms' => 900,
                'min_10_rooms' => 750,
            ],
            'monthly' => 18000,
        ], $row['rates']);

        $this->assertArrayHasKey('intervals', $row);
        $this->assertSame([], $row['intervals']);
    }

    public function test_unavailable_ranges_includes_rates_object_in_2dp_baht_strings_with_active_bookings(): void
    {
        $rt = $this->createRoomTypeWithRates('Executive Suite', 'เอ็กเซ็กคิวทีฟ สวีท');
        $room = $this->createRoom($rt);

        $user = User::factory()->create();
        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'confirmation_number' => 'KU-TEST-001',
            'status' => 'confirmed',
            'total_amount' => 1200,
        ]);

        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $rt->id,
            'room_id' => $room->id,
            'check_in' => Carbon::today()->addDays(3)->toDateString(),
            'check_out' => Carbon::today()->addDays(5)->toDateString(),
            'status' => 'confirmed',
            'room_amount' => 2400,
            'total_amount' => 2400,
        ]);

        $response = $this->getJson('/api/v1/unavailable-ranges');
        $response->assertStatus(200);

        $roomTypes = $response->json('room_types');
        $this->assertNotEmpty($roomTypes);

        $row = collect($roomTypes)->firstWhere('room_type_id', (string) $rt->id);
        $this->assertNotNull($row);

        $this->assertArrayHasKey('rates', $row);
        $this->assertSame([
            'daily' => [
                'general' => 1200,
                'ku_member' => 1000,
            ],
            'group' => [
                'min_5_rooms' => 900,
                'min_10_rooms' => 750,
            ],
            'monthly' => 18000,
        ], $row['rates']);

        // Assert interval generated for sold-out room
        $this->assertCount(1, $row['intervals']);
        $this->assertSame(Carbon::today()->addDays(3)->toDateString(), $row['intervals'][0]['start']);
        $this->assertSame(Carbon::today()->addDays(4)->toDateString(), $row['intervals'][0]['end']);
    }

    // =========================================================================
    // 🛡️ 5. Zero fallback for missing or inactive rates
    // =========================================================================

    public function test_rates_fall_back_to_zero_strings_when_no_rate_rows_exist(): void
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Empty Rate Room',
            'name_th' => 'ห้องไม่มีราคา',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        $this->createRoom($rt);

        $startDate = Carbon::today()->toDateString();
        $endDate = Carbon::today()->addDays(1)->toDateString();

        $response = $this->getJson("/api/v1/availability-per-day?start_date={$startDate}&end_date={$endDate}");
        $response->assertStatus(200);

        $row = collect($response->json('room_types'))->firstWhere('room_type_id', (string) $rt->id);
        $this->assertNotNull($row);

        $expectedZero = [
            'daily' => [
                'general' => 0,
                'ku_member' => 0,
            ],
            'group' => [
                'min_5_rooms' => 0,
                'min_10_rooms' => 0,
            ],
            'monthly' => 0,
        ];

        $this->assertSame($expectedZero, $row['rates']);

        // Assert zero fallback on availability-ranges
        $resRanges = $this->getJson("/api/v1/availability-ranges?start_date={$startDate}&end_date={$endDate}");
        $resRanges->assertStatus(200);
        $rowRanges = collect($resRanges->json('room_types'))->firstWhere('room_type_id', (string) $rt->id);
        $this->assertNotNull($rowRanges);
        $this->assertSame($expectedZero, $rowRanges['rates']);

        // Assert zero fallback on unavailable-dates
        $resDates = $this->getJson("/api/v1/unavailable-dates?start_date={$startDate}&end_date={$endDate}");
        $resDates->assertStatus(200);
        $rowDates = collect($resDates->json('room_types'))->firstWhere('room_type_id', (string) $rt->id);
        $this->assertNotNull($rowDates);
        $this->assertSame($expectedZero, $rowDates['rates']);

        // Assert zero fallback on unavailable-ranges
        $resUnavailRanges = $this->getJson('/api/v1/unavailable-ranges');
        $resUnavailRanges->assertStatus(200);
        $rowUnavailRanges = collect($resUnavailRanges->json('room_types'))->firstWhere('room_type_id', (string) $rt->id);
        $this->assertNotNull($rowUnavailRanges);
        $this->assertSame($expectedZero, $rowUnavailRanges['rates']);
    }

    // =========================================================================
    // ⚡ 6. Eager loading and N+1 prevention
    // =========================================================================

    public function test_rate_rows_are_eager_loaded_and_query_count_does_not_scale_with_room_types(): void
    {
        // Step 1: Create 1 room type with rates and room
        $rt1 = $this->createRoomTypeWithRates('Type 1', 'แบบ 1');
        $this->createRoom($rt1);

        $startDate = Carbon::today()->toDateString();
        $endDate = Carbon::today()->addDays(2)->toDateString();

        // Baseline query count per endpoint for 1 room type
        $endpoints = [
            "/api/v1/availability-per-day?start_date={$startDate}&end_date={$endDate}",
            "/api/v1/availability-ranges?start_date={$startDate}&end_date={$endDate}",
            "/api/v1/unavailable-dates?start_date={$startDate}&end_date={$endDate}",
            '/api/v1/unavailable-ranges',
        ];

        $baselineCounts = [];
        foreach ($endpoints as $endpoint) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($endpoint);
            $baselineCounts[$endpoint] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        // Step 2: Add 5 more room types with rates and rooms (total 6 room types)
        for ($i = 2; $i <= 6; $i++) {
            $rt = $this->createRoomTypeWithRates("Type {$i}", "แบบ {$i}");
            $this->createRoom($rt);
        }

        // Measure queries with 6 room types across all 4 calendar endpoints
        foreach ($endpoints as $endpoint) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $res = $this->getJson($endpoint);
            $res->assertStatus(200);

            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            // Total queries should be constant and not scale with the 6 room types
            $this->assertSame(
                $baselineCounts[$endpoint],
                count($queries),
                "Query count for {$endpoint} scaled with room types! Expected {$baselineCounts[$endpoint]}, got ".count($queries)
            );

            // Specifically verify that global_rates query loaded all room types via single `where ... in` query
            $globalRateQueries = array_filter($queries, function ($q) {
                return str_contains(strtolower($q['query']), 'global_rates');
            });
            $this->assertCount(
                1,
                $globalRateQueries,
                "Expected exactly 1 eager-load query for global_rates in {$endpoint}, but found ".count($globalRateQueries)
            );
        }
    }
}
