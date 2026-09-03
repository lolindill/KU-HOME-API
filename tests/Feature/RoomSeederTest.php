<?php

namespace Tests\Feature;

use App\Models\GlobalRate;
use App\Models\RoomType;
use Database\Seeders\RoomSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoomSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_seeder_seeds_real_rate_cards_in_satang_and_correct_extra_bed_prices(): void
    {
        $this->seed(RoomSeeder::class);

        $superior = RoomType::where('name_en', 'Superior')->firstOrFail();
        $deluxe = RoomType::where('name_en', 'Deluxe')->firstOrFail();
        $suite = RoomType::where('name_en', 'Suite')->firstOrFail();

        // ---------------------------------------------------------------------
        // 1. Assert extra_bed_price in satang on the database (0, 50000, 60000)
        // ---------------------------------------------------------------------
        $this->assertDatabaseHas('room_types', [
            'id' => $superior->id,
            'extra_bed_price' => 0,
        ]);
        $this->assertDatabaseHas('room_types', [
            'id' => $deluxe->id,
            'extra_bed_price' => 50000,
        ]);
        $this->assertDatabaseHas('room_types', [
            'id' => $suite->id,
            'extra_bed_price' => 60000,
        ]);

        $this->assertSame(0, (int) DB::table('room_types')->where('id', $superior->id)->value('extra_bed_price'));
        $this->assertSame(50000, (int) DB::table('room_types')->where('id', $deluxe->id)->value('extra_bed_price'));
        $this->assertSame(60000, (int) DB::table('room_types')->where('id', $suite->id)->value('extra_bed_price'));

        $this->assertSame(0, (int) $superior->getRawOriginal('extra_bed_price'));
        $this->assertSame(50000, (int) $deluxe->getRawOriginal('extra_bed_price'));
        $this->assertSame(60000, (int) $suite->getRawOriginal('extra_bed_price'));

        // Edge serialization check: accessor returns 2-dp baht string
        $this->assertSame('0.00', $superior->extra_bed_price);
        $this->assertSame('500.00', $deluxe->extra_bed_price);
        $this->assertSame('600.00', $suite->extra_bed_price);

        // ---------------------------------------------------------------------
        // 2. Assert 5 rate rows for Superior in satang via GlobalRate::getRoomRate
        // ---------------------------------------------------------------------
        $this->assertSame(100000, GlobalRate::getRoomRate($superior, 'daily'));
        $this->assertSame(80000, GlobalRate::getRoomRate($superior, 'daily_ku'));
        $this->assertSame(75000, GlobalRate::getRoomRate($superior, 'group', 'min_5_rooms'));
        $this->assertSame(75000, GlobalRate::getRoomRate($superior, 'group', 'min_10_rooms'));
        $this->assertSame(1500000, GlobalRate::getRoomRate($superior, 'month'));

        // ---------------------------------------------------------------------
        // 3. Assert 5 rate rows for Deluxe in satang via GlobalRate::getRoomRate
        // ---------------------------------------------------------------------
        $this->assertSame(120000, GlobalRate::getRoomRate($deluxe, 'daily'));
        $this->assertSame(100000, GlobalRate::getRoomRate($deluxe, 'daily_ku'));
        $this->assertSame(90000, GlobalRate::getRoomRate($deluxe, 'group', 'min_5_rooms'));
        $this->assertSame(75000, GlobalRate::getRoomRate($deluxe, 'group', 'min_10_rooms'));
        $this->assertSame(1800000, GlobalRate::getRoomRate($deluxe, 'month'));

        // ---------------------------------------------------------------------
        // 4. Assert 5 rate rows for Suite in satang via GlobalRate::getRoomRate
        // ---------------------------------------------------------------------
        $this->assertSame(180000, GlobalRate::getRoomRate($suite, 'daily'));
        $this->assertSame(150000, GlobalRate::getRoomRate($suite, 'daily_ku'));
        $this->assertSame(135000, GlobalRate::getRoomRate($suite, 'group', 'min_5_rooms'));
        $this->assertSame(135000, GlobalRate::getRoomRate($suite, 'group', 'min_10_rooms'));
        $this->assertSame(2700000, GlobalRate::getRoomRate($suite, 'month'));

        // ---------------------------------------------------------------------
        // 5. Assert code column: populated for group rates, null for others
        // ---------------------------------------------------------------------
        foreach ([$superior, $deluxe, $suite] as $rt) {
            $rates = GlobalRate::where('room_type_id', $rt->id)->get();
            $this->assertCount(5, $rates);

            $daily = $rates->firstWhere('rate_type', 'daily');
            $this->assertNotNull($daily);
            $this->assertNull($daily->code);

            $dailyKu = $rates->firstWhere('rate_type', 'daily_ku');
            $this->assertNotNull($dailyKu);
            $this->assertNull($dailyKu->code);

            $groupMin5 = $rates->first(fn ($r) => $r->rate_type === 'group' && $r->code === 'min_5_rooms');
            $this->assertNotNull($groupMin5);
            $this->assertSame('min_5_rooms', $groupMin5->code);

            $groupMin10 = $rates->first(fn ($r) => $r->rate_type === 'group' && $r->code === 'min_10_rooms');
            $this->assertNotNull($groupMin10);
            $this->assertSame('min_10_rooms', $groupMin10->code);

            $month = $rates->firstWhere('rate_type', 'month');
            $this->assertNotNull($month);
            $this->assertNull($month->code);
        }

        // Total rate rows across all 3 room types = 15
        $this->assertSame(15, GlobalRate::whereNotNull('room_type_id')->count());
    }
}
