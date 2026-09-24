<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🎫 Booking capacity checks — denominator = sellable pool + flag `include_reserved` (26/09/26)
 *
 *    (wayfinder/reserved-room-pool ticket 03 — bug fix grill #5 + flag extension)
 *    เดิมทั้ง 4 write paths นับ denominator จากห้องกายภาพเต็มของ type ทำให้ user จองเกิน
 *    pool ที่ assign ได้จริงแล้วจองค้างไม่มีห้องให้ — ตอนนี้ denominator คิดต่อช่วงเข้าพัก:
 *    - ไม่มี flag: นับเฉพาะห้องว่างจากทั้ง maintenance และ reserved period (sellable pool)
 *    - admin + include_reserved=true: ขยายเป็น sellable + reserved (maintenance ยังถูกตัดเสมอ)
 *    - non-admin ส่ง flag: เมยายีเงียบ ๆ (ไม่ขยาย pool ไม่ 403 — สัญญา silent ignore)
 *    "ห้องสำรอง" = ห้องติด reserved period ของตาราง room_state_periods (room-state-periods)
 */
class BookingCapacitySellablePoolTest extends TestCase
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

    private function createRoom(RoomType $roomType, string $status = 'available'): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '7'.(++self::$roomSeq),
            'status' => $status,
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

    /**
     * ช่วงเข้าพัก + period ใช้ร่วมกันทั้งไฟล์:
     * W1 = +2 → +4 (ช่วงจองปกติ), W2 = +6 → +8 (ช่วงที่ period บังคับ)
     * period ที่ครอบ W2 = +5 → +9 (ไม่ทับ W1 — W1 ยังขายได้เต็ม)
     */
    private function window(int $startDays, int $endDays): array
    {
        return [
            'check_in' => now()->addDays($startDays)->toDateString(),
            'check_out' => now()->addDays($endDays)->toDateString(),
        ];
    }

    private function bookingRoomsPayload(RoomType $roomType, array $window, int $rooms): array
    {
        return [
            'source' => 'online',
            'booking_rooms' => array_fill(0, $rooms, [
                'room_type_id' => $roomType->id,
                'check_in' => $window['check_in'],
                'check_out' => $window['check_out'],
            ]),
        ];
    }

    public function test_create_rejects_when_sellable_pool_exhausted_even_if_physical_rooms_exist(): void
    {
        $this->actingAsUser();
        $roomType = $this->createRoomType();

        // ห้องกายภาพ 3 — แต่ขายได้จริงแค่ 1 (1 ติด reserved period, 1 ติด maintenance period ใน W1)
        $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $maint = $this->createRoom($roomType);
        $this->createReservedPeriod($reserved, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());
        $this->createMaintenancePeriod($maint, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());

        // ขอ 2 ห้องในช่วงเดียวกัน → เกิน sellable (1) ทั้งที่ physical มี 3 → 422
        $response = $this->postJson('/api/v1/bookings', $this->bookingRoomsPayload($roomType, $this->window(2, 4), 2));

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $this->assertStringContainsString('ห้องพักประเภทที่เลือกเต็มแล้ว', $response->json('message'));

        // transactional boundary — booking ต้องไม่ถูกเหลือค้างจากการจองที่ล้ม
        $this->assertSame(0, Booking::count());
    }

    public function test_admin_flag_extends_create_capacity_to_sellable_plus_reserved(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();

        $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $maint = $this->createRoom($roomType);
        $this->createReservedPeriod($reserved, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());
        $this->createMaintenancePeriod($maint, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());

        // admin + flag → denominator = sellable(1) + reserved(1) = 2 → ขอ 2 ห้องผ่าน
        $payload = $this->bookingRoomsPayload($roomType, $this->window(2, 4), 2);
        $payload['source'] = 'admin';
        $payload['include_reserved'] = true;

        $response = $this->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonCount(2, 'booking_rooms');
    }

    public function test_admin_flag_never_counts_maintenance_rooms(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();

        // physical 2 — ติด maintenance period ทั้งคู่ ในช่วงจอง
        $maintA = $this->createRoom($roomType);
        $maintB = $this->createRoom($roomType);
        $this->createMaintenancePeriod($maintA, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());
        $this->createMaintenancePeriod($maintB, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());

        // กฎเหล็ก absolute — แม้ admin + flag: sellable + reserved = 0 → 422
        $payload = $this->bookingRoomsPayload($roomType, $this->window(2, 4), 1);
        $payload['source'] = 'admin';
        $payload['include_reserved'] = true;

        $response = $this->postJson('/api/v1/bookings', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('ห้องพักประเภทที่เลือกเต็มแล้ว', $response->json('message'));
    }

    public function test_non_admin_flag_is_silently_ignored(): void
    {
        $roomType = $this->createRoomType();

        $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $this->createReservedPeriod($reserved, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());

        // user A ส่ง flag ตอนจองห้องเดียว — sellable มี 1 → ผ่านปกติ (flag ไม่ทำ flow พัง)
        $userA = User::factory()->create();
        $response = $this->actingAs($userA, 'sanctum')->postJson(
            '/api/v1/bookings',
            $this->bookingRoomsPayload($roomType, $this->window(2, 4), 1) + ['include_reserved' => true]
        );
        $response->assertStatus(201);

        // user B ส่ง flag แต่ pool ขายได้เต็มแล้ว — flag ถูกเมยายี (ไม่ขยาย pool, ไม่ 403) → 422
        $userB = User::factory()->create();
        $response = $this->actingAs($userB, 'sanctum')->postJson(
            '/api/v1/bookings',
            $this->bookingRoomsPayload($roomType, $this->window(2, 4), 1) + ['include_reserved' => true]
        );
        $response->assertStatus(422);
        $this->assertStringContainsString('ห้องพักประเภทที่เลือกเต็มแล้ว', $response->json('message'));
    }

    public function test_sequential_bookings_stop_at_sellable_pool_but_admin_flag_reaches_reserved(): void
    {
        $roomType = $this->createRoomType();

        $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $this->createReservedPeriod($reserved, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());

        // booking แรก (user ปกติ) กิน sellable ห้องเดียว → ผ่าน
        $user1 = User::factory()->create();
        $response = $this->actingAs($user1, 'sanctum')->postJson(
            '/api/v1/bookings',
            $this->bookingRoomsPayload($roomType, $this->window(2, 4), 1)
        );
        $response->assertStatus(201);

        // booking ที่สอง (user ปกติ) — sellable หมดแล้ว (physical ยังมี reserved) → 422 (bug fix)
        $user2 = User::factory()->create();
        $response = $this->actingAs($user2, 'sanctum')->postJson(
            '/api/v1/bookings',
            $this->bookingRoomsPayload($roomType, $this->window(2, 4), 1)
        );
        $response->assertStatus(422);

        // admin + flag — ระดมห้องสำรองต่อได้ → 201
        $payload = $this->bookingRoomsPayload($roomType, $this->window(2, 4), 1);
        $payload['source'] = 'admin';
        $payload['include_reserved'] = true;
        $response = $this->actingAsAdmin()->postJson('/api/v1/bookings', $payload);
        $response->assertStatus(201);

        $this->assertSame(2, Booking::count());
    }

    public function test_add_rooms_respects_sellable_pool_and_admin_flag_extends(): void
    {
        $roomType = $this->createRoomType();

        $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $this->createReservedPeriod($reserved, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());

        // เริ่มจาก draft 1 ห้อง (ผ่าน — sellable มี 1)
        $user = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')->postJson(
            '/api/v1/bookings',
            $this->bookingRoomsPayload($roomType, $this->window(2, 4), 1)
        );
        $response->assertStatus(201);
        $bookingId = $response->json('booking_id');

        // เจ้าของเพิ่มอีก 1 ห้องช่วงเดิม — draft เดิมกิน slot 1 + ขอเพิ่ม 1 > sellable 1 → 422
        $response = $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/bookings/{$bookingId}/rooms",
            ['booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => $this->window(2, 4)['check_in'],
                    'check_out' => $this->window(2, 4)['check_out'],
                ],
            ]]
        );
        $response->assertStatus(422);

        // admin + flag — denominator = sellable + reserved = 2 → 1 + 1 ผ่าน
        $response = $this->actingAsAdmin()->postJson(
            "/api/v1/bookings/{$bookingId}/rooms",
            [
                'include_reserved' => true,
                'booking_rooms' => [
                    [
                        'room_type_id' => $roomType->id,
                        'check_in' => $this->window(2, 4)['check_in'],
                        'check_out' => $this->window(2, 4)['check_out'],
                    ],
                ],
            ]
        );
        $response->assertStatus(200);
    }

    public function test_update_room_respects_sellable_pool_and_admin_flag_extends(): void
    {
        $roomType = $this->createRoomType();

        // ทั้งคู่ว่างใน W1 — แต่ W2: ห้อง A ติด maintenance, ห้อง B ติด reserved
        $maint = $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $this->createMaintenancePeriod($maint, now()->addDays(5)->toDateString(), now()->addDays(9)->toDateString());
        $this->createReservedPeriod($reserved, now()->addDays(5)->toDateString(), now()->addDays(9)->toDateString());

        $user = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')->postJson(
            '/api/v1/bookings',
            $this->bookingRoomsPayload($roomType, $this->window(2, 4), 1)
        );
        $response->assertStatus(201);
        $bookingId = $response->json('booking_id');
        $bookingRoomId = $response->json('booking_rooms.0.id');

        // ย้ายช่วงเข้าพักไป W2 (ไม่ส่ง flag) — sellable ใน W2 = 0 → 422
        $response = $this->actingAs($user, 'sanctum')->putJson(
            "/api/v1/bookings/{$bookingId}/rooms/{$bookingRoomId}",
            $this->window(6, 8)
        );
        $response->assertStatus(422);

        // admin + flag — sellable + reserved ใน W2 = 1 (reserved ห้อง) → ผ่าน และ maintenance ยังถูกตัด
        $this->actingAsAdmin()->putJson(
            "/api/v1/bookings/{$bookingId}/rooms/{$bookingRoomId}",
            $this->window(6, 8) + ['include_reserved' => true]
        )->assertStatus(200);

        $this->assertDatabaseHas('booking_rooms', [
            'id' => $bookingRoomId,
            'check_in' => $this->window(6, 8)['check_in'].' 00:00:00',
            'check_out' => $this->window(6, 8)['check_out'].' 00:00:00',
        ]);
    }

    public function test_batch_update_rooms_respects_sellable_pool_and_admin_flag_extends(): void
    {
        $roomType = $this->createRoomType();

        // 3 ห้อง ว่างใน W1 — W2: 1 ติด maintenance + 2 ติด reserved
        $maint = $this->createRoom($roomType);
        $reservedA = $this->createRoom($roomType);
        $reservedB = $this->createRoom($roomType);
        $this->createMaintenancePeriod($maint, now()->addDays(5)->toDateString(), now()->addDays(9)->toDateString());
        $this->createReservedPeriod($reservedA, now()->addDays(5)->toDateString(), now()->addDays(9)->toDateString());
        $this->createReservedPeriod($reservedB, now()->addDays(5)->toDateString(), now()->addDays(9)->toDateString());

        $user = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')->postJson(
            '/api/v1/bookings',
            $this->bookingRoomsPayload($roomType, $this->window(2, 4), 2)
        );
        $response->assertStatus(201);
        $bookingId = $response->json('booking_id');
        $brIds = [$response->json('booking_rooms.0.id'), $response->json('booking_rooms.1.id')];

        $batchBody = fn (bool $flag) => [
            'include_reserved' => $flag,
            'booking_rooms' => array_map(fn ($brId) => [
                'booking_room_id' => $brId,
                'check_in' => $this->window(6, 8)['check_in'],
                'check_out' => $this->window(6, 8)['check_out'],
            ], $brIds),
        ];

        // ย้ายทั้ง batch ไป W2 (ไม่ส่ง flag) — sellable ใน W2 = 0 → 422
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}/rooms", $batchBody(false))
            ->assertStatus(422);

        // admin + flag — sellable + reserved ใน W2 = 2 → ทั้ง batch ผ่าน (maintenance ถูกตัดเสมอ)
        $this->actingAsAdmin()
            ->putJson("/api/v1/bookings/{$bookingId}/rooms", $batchBody(true))
            ->assertStatus(200);

        foreach ($brIds as $brId) {
            $this->assertDatabaseHas('booking_rooms', [
                'id' => $brId,
                'check_in' => $this->window(6, 8)['check_in'].' 00:00:00',
                'check_out' => $this->window(6, 8)['check_out'].' 00:00:00',
            ]);
        }
    }
}
