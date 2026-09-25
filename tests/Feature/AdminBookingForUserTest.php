<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🏛️ Admin booking 2 โหมด — wayfinder/organization-bookings ticket 02/05
 *
 * โหมด A: admin ส่ง `user` UUID → booking user_id = target (draft-dedup/cap/ราคา ใช้ target)
 * โหมด B: admin ไม่ส่ง `user` → user_id = null เฮดเปล่า (ไม่มี dedup, ราคา daily, ไม่มี cap)
 * ไม่เพิ่ม `created_by` — trace จาก source='admin' + causer_id ของ transition
 */
class AdminBookingForUserTest extends TestCase
{
    use RefreshDatabase;

    private static int $roomSeq = 0;

    private function createRoomTypeWithRates(int $dailyBaht = 1200, ?int $dailyKuBaht = 1000): RoomType
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe Room',
            'name_th' => 'ห้องดีลักซ์',
            'max_guests' => 2,
            'extra_bed_enabled' => true,
        ]);

        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $rt->id,
            'code' => null,
            'name_en' => 'Deluxe Daily General',
            'default_price' => $dailyBaht,
            'is_active' => true,
        ]);

        if ($dailyKuBaht !== null) {
            GlobalRate::create([
                'rate_type' => 'daily_ku',
                'room_type_id' => $rt->id,
                'code' => null,
                'name_en' => 'Deluxe Daily KU Member',
                'default_price' => $dailyKuBaht,
                'is_active' => true,
            ]);
        }

        GlobalRate::create([
            'rate_type' => 'addon',
            'room_type_id' => null,
            'code' => 'extra_bed',
            'name_en' => 'Extra Bed',
            'default_price' => 500,
            'is_active' => true,
        ]);

        return $rt;
    }

    private function createRoom(RoomType $roomType): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '20'.(++self::$roomSeq),
            'status' => 'available',
        ]);
    }

    public function test_admin_booking_for_user_links_target_user_not_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(7)->toDateString(); // 2 nights

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'user' => $target->id,
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson(['status' => 'success'])
            ->assertJsonPath('user_id', $target->id);

        $booking = Booking::findOrFail($response->json('booking_id'));
        $this->assertSame($target->id, $booking->user_id);
        $this->assertSame('admin', $booking->source);

        // 🏛️ ownership — target user เห็นบิลใน GET /bookings ของตัวเอง
        $this->actingAs($target, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertStatus(200)
            ->assertJsonPath('bookings.0.id', $booking->id);

        // admin เห็นทุกบิล (role admin ไม่ filter user_id) — พร้อม relation user = target
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bookings')
            ->assertStatus(200)
            ->assertJsonPath('bookings.0.id', $booking->id)
            ->assertJsonPath('bookings.0.user.id', $target->id);
    }

    public function test_admin_booking_for_ku_member_uses_target_daily_ku_rate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'ku_member']);

        $roomType = $this->createRoomTypeWithRates(1200, 1000);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(7)->toDateString(); // 2 nights

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'user' => $target->id,
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $response->assertStatus(201);

        $booking = Booking::with('bookingRooms')->findOrFail($response->json('booking_id'));
        $room = $booking->bookingRooms->first();

        // 🏛️ ราคาตาม role ของ target (daily_ku 1,000) ไม่ใช่ admin — 2 nights × 1,000 = 2,000
        $this->assertSame(2000, $room->room_amount);
        $this->assertSame(2000, $booking->total_amount);
    }

    public function test_admin_booking_without_user_creates_empty_head(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString(); // 1 night

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user_id', null);

        $booking = Booking::with('bookingRooms')->findOrFail($response->json('booking_id'));
        $this->assertNull($booking->user_id);

        // โหมด B — ราคา daily เสมอ (ไม่มี role ให้ดู): 1 night × 1,200 = 1,200
        $this->assertSame(1200, $booking->total_amount);

        // ownership เหลือแต่ admin — non-admin ดูบิลอื่นไม่ได้ (403/404 ตามโค้ดเดิม)
        $other = User::factory()->create(['role' => 'user']);
        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}")
            ->assertStatus(403);
    }

    public function test_mode_b_has_no_draft_dedup_group_can_book_multiple(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $payload = [
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ];

        // org/group จองซ้อนหลายบิลได้ — บิลที่สองไม่โดน draft-dedup
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', $payload)->assertStatus(201);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', $payload)->assertStatus(201);

        $this->assertSame(2, Booking::whereNull('user_id')->where('source', 'admin')->count());
    }

    public function test_mode_a_draft_dedup_counts_target_user_not_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        // target มี draft ค้างอยู่ (สร้างเองผ่าน API)
        $this->actingAs($target, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ])->assertStatus(201);

        // admin จองแทน target → 422 เพราะ draft ของ target ยังค้าง
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'user' => $target->id,
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_mode_a_admin_own_draft_does_not_block_booking_for_target(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        // admin มี draft ของตัวเอง (โหมด B)
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ])->assertStatus(201);

        // 🏛️ draft ของ admin ไม่ขวางการจองแทน (dedup นับที่ target)
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'user' => $target->id,
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ])->assertStatus(201);
    }

    public function test_mode_a_room_cap_uses_target_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $roomType = $this->createRoomTypeWithRates();

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $rooms = [];
        for ($i = 0; $i < 5; $i++) {
            $rooms[] = [
                'room_type_id' => $roomType->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
            ];
        }

        // 🏛️ cap ใช้ role ของ target (non-admin = 4 ห้อง) — 5 ห้อง → 422
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'user' => $target->id,
            'booking_rooms' => $rooms,
        ])->assertStatus(422);
    }

    public function test_mode_b_admin_is_not_room_capped(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $roomType = $this->createRoomTypeWithRates();
        for ($i = 0; $i < 5; $i++) {
            $this->createRoom($roomType);
        }

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $rooms = [];
        for ($i = 0; $i < 5; $i++) {
            $rooms[] = [
                'room_type_id' => $roomType->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
            ];
        }

        // โหมด B — admin ไม่โดน room cap (5 ห้องผ่าน)
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'booking_rooms' => $rooms,
        ]);
        $response->assertStatus(201);
        $this->assertCount(5, Booking::findOrFail($response->json('booking_id'))->bookingRooms);
    }

    public function test_non_admin_cannot_send_user_field(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $target = User::factory()->create(['role' => 'user']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'user' => $target->id,
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_mode_a_invalid_user_uuid_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        $checkIn = Carbon::now()->addDays(5)->toDateString();
        $checkOut = Carbon::now()->addDays(6)->toDateString();

        // UUID format ถูกแต่ไม่มี user นี้ → 422 (exists rule)
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'user' => Str::uuid(),
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_mode_a_admin_exempt_from_advance_notice(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'user']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        // non-admin ต้องจองล่วงหน้า ≥ 2 วัน — admin จองแทนเช้ากว่านั้นได้ (exempt ตาม sanctum admin)
        $checkIn = Carbon::now()->toDateString();
        $checkOut = Carbon::now()->addDays(1)->toDateString();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'user' => $target->id,
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertSame($target->id, Booking::findOrFail($response->json('booking_id'))->user_id);
    }
}
