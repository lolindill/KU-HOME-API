<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingConfirmation;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🏷️ (2026-11-09): ทดสอบ derived attribute `booking_type` ระดับ Booking container
 *    - 'monthly' = มี booking_room ใดห้องหนึ่ง nights >= block_min_nights (20)
 *    - 'group'   = ไม่มีห้องเขต monthly แต่จำนวน booking_rooms >= group_min_rooms (5)
 *    - 'daily'   = ที่เหลือ
 *    - priority: monthly > group > daily
 *    - eager loading ปิด N+1: pending confirmations, verify, additional charges
 */
class BookingTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $user;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->user = User::factory()->create(['role' => 'user']);

        $this->roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe',
            'name_th' => 'ดีลักซ์',
            'max_guests' => 3,
            'extra_bed_enabled' => false,
        ]);

        $rates = [
            ['rate_type' => 'daily', 'code' => 'daily', 'price' => 1200],
            ['rate_type' => 'group', 'code' => 'min_5_rooms', 'price' => 900],
            ['rate_type' => 'group', 'code' => 'min_10_rooms', 'price' => 750],
            ['rate_type' => 'month', 'code' => 'month', 'price' => 18000],
        ];
        foreach ($rates as $rate) {
            GlobalRate::create([
                'rate_type' => $rate['rate_type'],
                'room_type_id' => $this->roomType->id,
                'code' => $rate['code'],
                'name_en' => 'Deluxe '.$rate['code'],
                'default_price' => $rate['price'],
                'is_active' => true,
            ]);
        }

        // สร้าง 15 ห้องกายภาพ เพื่อรองรับ availability check
        for ($i = 0; $i < 15; $i++) {
            Room::create([
                'room_type_id' => $this->roomType->id,
                'room_number' => (string) (100 + $i),
                'status' => 'available',
            ]);
        }
    }

    private function payload(array $roomsNights, bool $asAdmin = true, ?User $targetUser = null): array
    {
        $checkIn = $asAdmin
            ? Carbon::today()->toDateString()
            : Carbon::today()->addDays(5)->toDateString();

        $bookingRooms = [];
        foreach ($roomsNights as $nights) {
            $bookingRooms[] = [
                'room_type_id' => $this->roomType->id,
                'check_in' => $checkIn,
                'check_out' => Carbon::parse($checkIn)->addDays($nights)->toDateString(),
                'guests' => [
                    ['title' => 'Mr.', 'first_name' => 'Somchai', 'last_name' => 'Jaidee', 'phone' => '0812345678'],
                ],
            ];
        }

        return array_filter([
            'source' => $asAdmin ? 'admin' : 'online',
            'user' => $targetUser?->id,
            'booking_rooms' => $bookingRooms,
        ], fn ($v) => $v !== null);
    }

    public function test_booking_type_daily_for_single_room_under_20_nights(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([3], asAdmin: false));

        $response->assertStatus(201);
        $bookingId = $response->json('booking_id');

        // GET /bookings/{id}
        $showRes = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$bookingId}");

        $showRes->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'daily')
            ->assertJsonPath('booking.booking_rooms.0.stay_type', 'daily');

        // GET /bookings
        $listRes = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/bookings');

        $listRes->assertStatus(200)
            ->assertJsonPath('bookings.0.booking_type', 'daily');
    }

    public function test_booking_type_monthly_for_room_20_nights_or_more(): void
    {
        // 20 คืน (block_min_nights)
        $res20 = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([20], asAdmin: true));

        $res20->assertStatus(201);
        $booking20Id = $res20->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking20Id}")
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'monthly')
            ->assertJsonPath('booking.booking_rooms.0.stay_type', 'block');

        // 30 คืน (monthly_min_nights)
        $res30 = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([30], asAdmin: true));

        $res30->assertStatus(201);
        $booking30Id = $res30->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking30Id}")
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'monthly')
            ->assertJsonPath('booking.booking_rooms.0.stay_type', 'monthly');
    }

    public function test_booking_type_group_for_5_rooms_short_stay(): void
    {
        // จอง 5 ห้องสั้น (ห้องละ 2 คืน)
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([2, 2, 2, 2, 2], asAdmin: true));

        $response->assertStatus(201);
        $bookingId = $response->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$bookingId}")
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'group')
            ->assertJsonPath('booking.booking_rooms.0.stay_type', 'daily');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertStatus(200)
            ->assertJsonPath('bookings.0.booking_type', 'group');
    }

    public function test_boundary_nights_19_is_daily_20_is_monthly(): void
    {
        // 19 คืน → daily (non-admin จองได้)
        $res19 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([19], asAdmin: false));

        $res19->assertStatus(201);
        $booking19Id = $res19->json('booking_id');

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking19Id}")
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'daily')
            ->assertJsonPath('booking.booking_rooms.0.stay_type', 'daily');

        // 20 คืน → monthly (admin)
        $res20 = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([20], asAdmin: true));

        $res20->assertStatus(201);
        $booking20Id = $res20->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking20Id}")
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'monthly')
            ->assertJsonPath('booking.booking_rooms.0.stay_type', 'block');
    }

    public function test_boundary_rooms_4_is_daily_5_is_group(): void
    {
        // 4 ห้องสั้น → daily
        $res4 = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([2, 2, 2, 2], asAdmin: true));

        $res4->assertStatus(201);
        $booking4Id = $res4->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking4Id}")
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'daily');

        // 5 ห้องสั้น → group
        $res5 = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([2, 2, 2, 2, 2], asAdmin: true));

        $res5->assertStatus(201);
        $booking5Id = $res5->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking5Id}")
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'group');
    }

    public function test_mixed_rooms_priority_monthly_over_group(): void
    {
        // 5 ห้อง: 4 ห้องสั้น (2 คืน) + 1 ห้อง 20 คืน → 'monthly' (priority monthly > group)
        $res5 = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([2, 2, 2, 2, 20], asAdmin: true));

        $res5->assertStatus(201);
        $booking5Id = $res5->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking5Id}")
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'monthly');

        // 2 ห้อง: 1 ห้องสั้น (2 คืน) + 1 ห้อง 25 คืน → 'monthly'
        $res2 = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([2, 25], asAdmin: true));

        $res2->assertStatus(201);
        $booking2Id = $res2->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking2Id}")
            ->assertStatus(200)
            ->assertJsonPath('booking.booking_type', 'monthly');
    }

    public function test_nested_booking_type_in_pending_confirmations_and_verify(): void
    {
        // สร้าง booking 1 ห้อง 3 คืน
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([3], asAdmin: false));

        $res->assertStatus(201);
        $bookingId = $res->json('booking_id');

        // ส่งหลักฐานการชำระเงิน
        $confirmRes = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingId}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'amount' => 3600,
            ]);

        $confirmRes->assertStatus(201);
        $confirmationId = BookingConfirmation::where('booking_id', $bookingId)->first()->id;

        // 1. GET /booking-confirmations/pending
        $pendingRes = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/booking-confirmations/pending');

        $pendingRes->assertStatus(200)
            ->assertJsonPath('confirmations.data.0.booking.booking_type', 'daily');

        // 2. PUT /booking-confirmations/{id}/verify
        $verifyRes = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/booking-confirmations/{$confirmationId}/verify", [
                'review_note' => 'OK',
            ]);

        $verifyRes->assertStatus(200)
            ->assertJsonPath('confirmation.booking.booking_type', 'daily');
    }

    public function test_stay_type_regression_on_booking_rooms(): void
    {
        // ตรวจสอบว่า stay_type ของแต่ละห้องยังคงถูกต้องตามกฎเดิม ไม่กระทบกระเทือน
        // 1 ห้อง 1 คืน → daily
        $b1 = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([1], asAdmin: false))->json('booking_id');

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$b1}")
            ->assertJsonPath('booking.booking_type', 'daily')
            ->assertJsonPath('booking.booking_rooms.0.stay_type', 'daily');

        // 1 ห้อง 20 คืน → block (booking_type = monthly)
        $b20 = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([20], asAdmin: true))->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$b20}")
            ->assertJsonPath('booking.booking_type', 'monthly')
            ->assertJsonPath('booking.booking_rooms.0.stay_type', 'block');

        // 1 ห้อง 30 คืน → monthly (booking_type = monthly)
        $b30 = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->payload([30], asAdmin: true))->json('booking_id');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$b30}")
            ->assertJsonPath('booking.booking_type', 'monthly')
            ->assertJsonPath('booking.booking_rooms.0.stay_type', 'monthly');
    }
}
