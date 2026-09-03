<?php

namespace Tests\Feature;

use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoomTypeRatesListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🏷️ GET /api/v1/room-types
     * Every room type item must have rates object (in 2-dp decimal baht strings)
     * for daily, group, monthly, and extra_bed_price in baht string.
     */
    public function test_all_room_types_endpoint_returns_rates_object_and_extra_bed_price_in_baht_string(): void
    {
        // 1. Room type with full rates
        $rtFull = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe Suite',
            'name_th' => 'ดีลักซ์ สวีท',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
            'max_extra_beds' => 1,
            'extra_bed_price' => 50000, // 500.00 THB
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rtFull->id,
            'name_en' => 'Deluxe Daily',
            'default_price' => 150000, // 1,500.00 THB
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily_ku',
            'room_type_id' => $rtFull->id,
            'name_en' => 'Deluxe KU Daily',
            'default_price' => 120000, // 1,200.00 THB
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'group',
            'room_type_id' => $rtFull->id,
            'code' => 'min_5_rooms',
            'name_en' => 'Deluxe Group Min 5',
            'default_price' => 110000, // 1,100.00 THB
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'group',
            'room_type_id' => $rtFull->id,
            'code' => 'min_10_rooms',
            'name_en' => 'Deluxe Group Min 10',
            'default_price' => 95000, // 950.00 THB
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'month',
            'room_type_id' => $rtFull->id,
            'name_en' => 'Deluxe Monthly',
            'default_price' => 2000000, // 20,000.00 THB
            'is_active' => true,
        ]);

        // 2. Room type with partial rates (only daily general, missing group and monthly)
        $rtPartial = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Single',
            'name_th' => 'สแตนดาร์ด ซิงเกิล',
            'max_guests' => 1,
            'extra_bed_enabled' => false,
            'max_extra_beds' => 0,
            'extra_bed_price' => 0, // 0.00 THB
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rtPartial->id,
            'name_en' => 'Standard Daily',
            'default_price' => 80000, // 800.00 THB
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/room-types');
        $response->assertStatus(200);

        $roomTypes = $response->json('room_types');
        $this->assertIsArray($roomTypes);
        $this->assertCount(2, $roomTypes);

        $bahtRegex = '/^\d+\.\d{2}$/';

        foreach ($roomTypes as $item) {
            // Verify rates object structure
            $this->assertArrayHasKey('rates', $item);
            $this->assertIsArray($item['rates']);

            // daily
            $this->assertArrayHasKey('daily', $item['rates']);
            $this->assertMatchesRegularExpression($bahtRegex, $item['rates']['daily']['general']);
            $this->assertMatchesRegularExpression($bahtRegex, $item['rates']['daily']['ku_member']);

            // group
            $this->assertArrayHasKey('group', $item['rates']);
            $this->assertMatchesRegularExpression($bahtRegex, $item['rates']['group']['min_5_rooms']);
            $this->assertMatchesRegularExpression($bahtRegex, $item['rates']['group']['min_10_rooms']);

            // monthly
            $this->assertArrayHasKey('monthly', $item['rates']);
            $this->assertMatchesRegularExpression($bahtRegex, $item['rates']['monthly']);

            // extra_bed_price
            $this->assertArrayHasKey('extra_bed_price', $item);
            $this->assertMatchesRegularExpression($bahtRegex, $item['extra_bed_price']);

            // daily_rate must not be present
            $this->assertArrayNotHasKey('daily_rate', $item, 'Legacy daily_rate should not be present');

            // internal relationships must be hidden
            $this->assertArrayNotHasKey('rate_rows', $item);
            $this->assertArrayNotHasKey('rateRows', $item);
            $this->assertArrayNotHasKey('daily_rate_row', $item);
            $this->assertArrayNotHasKey('dailyRateRow', $item);
        }

        // Verify exact values for Deluxe Suite (full rates)
        $fullItem = collect($roomTypes)->firstWhere('id', (string) $rtFull->id);
        $this->assertNotNull($fullItem);
        $this->assertSame('500.00', $fullItem['extra_bed_price']);
        $this->assertSame([
            'daily' => [
                'general' => '1500.00',
                'ku_member' => '1200.00',
            ],
            'group' => [
                'min_5_rooms' => '1100.00',
                'min_10_rooms' => '950.00',
            ],
            'monthly' => '20000.00',
        ], $fullItem['rates']);

        // Verify fallback to '0.00' for Standard Single (partial rates)
        $partialItem = collect($roomTypes)->firstWhere('id', (string) $rtPartial->id);
        $this->assertNotNull($partialItem);
        $this->assertSame('0.00', $partialItem['extra_bed_price']);
        $this->assertSame([
            'daily' => [
                'general' => '800.00',
                'ku_member' => '0.00',
            ],
            'group' => [
                'min_5_rooms' => '0.00',
                'min_10_rooms' => '0.00',
            ],
            'monthly' => '0.00',
        ], $partialItem['rates']);
    }

    /**
     * 🔍 GET /api/v1/availability
     * Both top-level row and embedded room_type sub-object have identical rates objects
     * in baht strings, daily_rate is not present, and fallback to '0.00' when some rate rows are missing.
     */
    public function test_availability_endpoint_returns_identical_rates_in_top_level_and_embedded_room_type(): void
    {
        // 1. Room type with full rates
        $rtFull = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe Suite',
            'name_th' => 'ดีลักซ์ สวีท',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
            'max_extra_beds' => 1,
            'extra_bed_price' => 50000, // 500.00 THB
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rtFull->id,
            'name_en' => 'Deluxe Daily',
            'default_price' => 150000, // 1,500.00 THB
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily_ku',
            'room_type_id' => $rtFull->id,
            'name_en' => 'Deluxe KU Daily',
            'default_price' => 120000, // 1,200.00 THB
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'group',
            'room_type_id' => $rtFull->id,
            'code' => 'min_5_rooms',
            'name_en' => 'Deluxe Group Min 5',
            'default_price' => 110000, // 1,100.00 THB
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'group',
            'room_type_id' => $rtFull->id,
            'code' => 'min_10_rooms',
            'name_en' => 'Deluxe Group Min 10',
            'default_price' => 95000, // 950.00 THB
            'is_active' => true,
        ]);
        GlobalRate::create([
            'rate_type' => 'month',
            'room_type_id' => $rtFull->id,
            'name_en' => 'Deluxe Monthly',
            'default_price' => 2000000, // 20,000.00 THB
            'is_active' => true,
        ]);

        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $rtFull->id,
            'room_number' => '101',
            'status' => 'available',
        ]);

        // 2. Room type with partial rates (only daily general)
        $rtPartial = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Single',
            'name_th' => 'สแตนดาร์ด ซิงเกิล',
            'max_guests' => 1,
            'extra_bed_enabled' => false,
            'max_extra_beds' => 0,
            'extra_bed_price' => 0,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rtPartial->id,
            'name_en' => 'Standard Daily',
            'default_price' => 80000, // 800.00 THB
            'is_active' => true,
        ]);

        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $rtPartial->id,
            'room_number' => '102',
            'status' => 'available',
        ]);

        $checkIn = now()->addDays(1)->toDateString();
        $checkOut = now()->addDays(3)->toDateString();

        $response = $this->getJson("/api/v1/availability?check_in={$checkIn}&check_out={$checkOut}");
        $response->assertStatus(200);

        $roomTypes = $response->json('room_types');
        $this->assertIsArray($roomTypes);
        $this->assertGreaterThanOrEqual(2, count($roomTypes));

        foreach ($roomTypes as $item) {
            // Assert rates exists on both top-level and embedded room_type
            $this->assertArrayHasKey('rates', $item, 'Top-level row must have rates object');
            $this->assertArrayHasKey('room_type', $item, 'Top-level row must have embedded room_type');
            $this->assertArrayHasKey('rates', $item['room_type'], 'Embedded room_type must have rates object');

            // Assert top-level row and embedded room_type have identical rates objects
            $this->assertSame(
                $item['rates'],
                $item['room_type']['rates'],
                'Top-level rates and embedded room_type.rates must be identical'
            );

            // Assert daily_rate is not present anywhere
            $this->assertArrayNotHasKey('daily_rate', $item, 'daily_rate should not exist in top-level row');
            $this->assertArrayNotHasKey('daily_rate', $item['room_type'], 'daily_rate should not exist in embedded room_type');

            // Assert search_criteria and available_rooms are not corrupted
            $this->assertArrayHasKey('available_rooms', $item);
            $this->assertArrayHasKey('search_criteria', $item);
            $this->assertSame($checkIn, $item['search_criteria']['check_in']);
            $this->assertSame($checkOut, $item['search_criteria']['check_out']);
        }

        // Assert Deluxe Suite values (full rates)
        $fullItem = collect($roomTypes)->firstWhere('room_type_id', (string) $rtFull->id);
        $this->assertNotNull($fullItem);
        $this->assertSame([
            'daily' => [
                'general' => '1500.00',
                'ku_member' => '1200.00',
            ],
            'group' => [
                'min_5_rooms' => '1100.00',
                'min_10_rooms' => '950.00',
            ],
            'monthly' => '20000.00',
        ], $fullItem['rates']);
        $this->assertSame('500.00', $fullItem['room_type']['extra_bed_price']);

        // Assert Standard Single fallback to '0.00' for missing rate rows
        $partialItem = collect($roomTypes)->firstWhere('room_type_id', (string) $rtPartial->id);
        $this->assertNotNull($partialItem);
        $expectedPartialRates = [
            'daily' => [
                'general' => '800.00',
                'ku_member' => '0.00',
            ],
            'group' => [
                'min_5_rooms' => '0.00',
                'min_10_rooms' => '0.00',
            ],
            'monthly' => '0.00',
        ];
        $this->assertSame($expectedPartialRates, $partialItem['rates']);
        $this->assertSame($expectedPartialRates, $partialItem['room_type']['rates']);
        $this->assertSame('0.00', $partialItem['room_type']['extra_bed_price']);
    }

    /**
     * 🛡️ GET /api/v1/availability
     * Verify fallback to '0.00' when all rate rows are missing or inactive.
     * Asserts embed does not break even without rate rows.
     */
    public function test_availability_endpoint_falls_back_to_zero_when_rate_rows_are_missing_or_inactive(): void
    {
        $rtEmpty = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Empty Rates Room',
            'name_th' => 'ห้องไม่มีเรท',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
            'max_extra_beds' => 0,
            'extra_bed_price' => 0,
        ]);

        // Inactive rate row should be ignored
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rtEmpty->id,
            'name_en' => 'Inactive Daily',
            'default_price' => 99900,
            'is_active' => false,
        ]);

        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $rtEmpty->id,
            'room_number' => '999',
            'status' => 'available',
        ]);

        $response = $this->getJson('/api/v1/availability');
        $response->assertStatus(200);

        $roomTypes = $response->json('room_types');
        $item = collect($roomTypes)->firstWhere('room_type_id', (string) $rtEmpty->id);
        $this->assertNotNull($item, 'Room type without active rates should still appear in availability');

        $expectedZeroRates = [
            'daily' => [
                'general' => '0.00',
                'ku_member' => '0.00',
            ],
            'group' => [
                'min_5_rooms' => '0.00',
                'min_10_rooms' => '0.00',
            ],
            'monthly' => '0.00',
        ];

        $this->assertSame($expectedZeroRates, $item['rates']);
        $this->assertSame($expectedZeroRates, $item['room_type']['rates']);
        $this->assertSame('0.00', $item['room_type']['extra_bed_price']);
        $this->assertArrayNotHasKey('daily_rate', $item);
        $this->assertArrayNotHasKey('daily_rate', $item['room_type']);
    }
}
