<?php

namespace Tests\Feature;

use App\Models\Addon;
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
 * 🎫 Assign-rooms → RoomAllocator รับ flag `include_reserved` (26/09/26)
 *
 *    (wayfinder/reserved-room-pool ticket 04 — allocator flag threading + full-chain E2E)
 *    "ห้องสำรอง" = ห้องติด reserved period ของตาราง room_state_periods (room-state-periods):
 *    - ไม่ส่ง flag: allocator pool ตัดห้องติด period ทุก kind — พฤติกรรมเดิม 100% (backward-compat)
 *    - admin + include_reserved=true: pool ขยายรวมห้องติด reserved period
 *      (maintenance period ยังถูกตัดเสมอ — กฎเหล็ก absolute)
 *    - non-admin: route อยู่ใต้ role:admin อยู่แล้ว → 403 ก่อนถึง gate (สัญญา silent-ignore
 *      ครอบคลุมจริงเฉพาะ endpoint สาธารณะ — ดู tickets 01–03)
 *    - ไม่ persist ค่า flag (grill #2): สร้าง booking ด้วย flag แต่ assign-rooms ไม่ส่ง flag →
 *      allocator fail อย่างสุภาพ ไม่ดึงห้องสำรองมาเอง
 *    - การ assign เขียนแค่ booking_rooms.room_id — สถานะกายภาพของห้องไม่ถูกแตะ
 *      (period model: lifecycle วิ่งอิสระจาก period)
 */
class AssignRoomsIncludeReservedTest extends TestCase
{
    use RefreshDatabase;

    // counter แทน rand() — กันเลขห้องชนกันเอง (precedent FrontDeskTest / capacity test)
    private static int $roomSeq = 0;

    private function createRoomType(): RoomType
    {
        return RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Deluxe',
            'name_th' => 'ห้องดีลักซ์ทดสอบ',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
    }

    private function createRoom(RoomType $roomType): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '7'.(++self::$roomSeq),
            'status' => 'available',
            'floor' => 5,
            'side' => 'V1',
            'pos' => self::$roomSeq,
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
     * W1 = +2 → +4 (ช่วงจอง), period ครอบ +1 → +5 (ทับ W1 ทั้งช่วง)
     */
    private function window(int $startDays, int $endDays): array
    {
        return [
            'check_in' => now()->addDays($startDays)->toDateString(),
            'check_out' => now()->addDays($endDays)->toDateString(),
        ];
    }

    /**
     * 🏨 จองห้อง A ด้วย booking อื่นในช่วงเดียวกัน (paid + confirmed BR → holdingSlot)
     *    เพื่อให้ sellable pool ของช่วงนี้ "เต็ม" — เหลือแค่ห้องติด reserved period
     */
    private function blockRoomWithOtherBooking(Room $room, array $window): Booking
    {
        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => User::factory()->create()->id,
            'source' => 'online',
            'status' => 'paid',
            'is_paid' => true,
            'total_amount' => 1500,
        ]);

        $bookingRoom = BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $room->room_type_id,
            'room_id' => $room->id,
            'check_in' => $window['check_in'],
            'check_out' => $window['check_out'],
            'guests' => [
                ['title' => 'mr', 'name' => 'Somchai Jaidee', 'nationality' => 'TH'],
            ],
            'status' => 'confirmed',
            'room_amount' => 1500,
            'discount_amount' => 0,
            'amount' => 1500,
        ]);

        Addon::create([
            'id' => Str::uuid(),
            'booking_room_id' => $bookingRoom->id,
            'extra_bed' => 0,
        ]);

        return $booking;
    }

    /**
     * 🏨 Full-chain E2E (HTTP ล้วน): create-booking ด้วย flag → update สถานะเป็น paid
     *    → assign-rooms ด้วย flag → ได้ room_id เป็นห้องติด reserved period
     *    · period row คงอยู่ · สถานะกายภาพห้องไม่ถูกแตะ (ไม่มี auto-flip — period model)
     */
    public function test_full_chain_admin_create_with_flag_then_assign_with_flag_gets_reserved_period_room(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();

        $sellable = $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $this->createReservedPeriod($reserved, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());
        $this->blockRoomWithOtherBooking($sellable, $this->window(2, 4));

        // 1) admin create-booking ด้วย flag (capacity check ฝั่ง ticket 03 ขยายเป็น sellable + reserved)
        $w = $this->window(2, 4);
        $create = $this->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'include_reserved' => true,
            'booking_rooms' => [[
                'room_type_id' => $roomType->id,
                'check_in' => $w['check_in'],
                'check_out' => $w['check_out'],
            ]],
        ]);
        $create->assertStatus(201);
        $bookingId = $create->json('booking_id');

        // 2) admin เปลี่ยนสถานะ draft → paid (เงินสดหน้าเคาน์เตอร์) ให้ assign-rooms ผ่าน gate
        $this->putJson("/api/v1/bookings/update/{$bookingId}", ['status' => 'paid'])
            ->assertStatus(200)
            ->assertJsonPath('booking_status', 'paid');

        // 3) assign-rooms ด้วย flag → pool รวมห้อง reserved (ห้อง sellable โดน booking อื่นครอบ)
        $response = $this->putJson("/api/v1/bookings/{$bookingId}/assign-rooms", ['include_reserved' => true]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertSame($reserved->id, $response->json('booking.booking_rooms.0.room_id'));

        // 4) ผลข้างเคียง: period row คงอยู่ + สถานะกายภาพห้องไม่ถูกแตะ (assign เขียนแค่ room_id)
        $this->assertTrue(RoomStatePeriod::where('room_id', $reserved->id)
            ->where('kind', RoomStatePeriod::KIND_RESERVED)
            ->where('start_date', now()->addDays(1)->toDateString())
            ->exists());
        $this->assertSame('available', $reserved->fresh()->status);
        $this->assertNull($sellable->fresh()->bookingRooms()
            ->where('booking_id', $bookingId)
            ->first());
    }

    /**
     * 🛡️ Fail-safe (grill #2): สร้าง booking ด้วย flag แต่ assign-rooms ไม่ส่ง flag
     *    → allocator fail อย่างสุภาพ (422) ไม่ดึงห้องสำรองมาเอง ไม่มี assignment ค้าง
     */
    public function test_assign_without_flag_fails_politely_when_only_reserved_room_remains(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();

        $sellable = $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $this->createReservedPeriod($reserved, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());
        $this->blockRoomWithOtherBooking($sellable, $this->window(2, 4));

        $bookingId = $this->createPaidBookingViaHttp($roomType, withFlag: true);

        $response = $this->putJson("/api/v1/bookings/{$bookingId}/assign-rooms");

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString('ไม่สามารถจัดห้อง', $response->json('message'));

        // ไม่มี assignment ผิดพลาดค้าง — BR ยังไม่มี room_id
        $this->assertNull(BookingRoom::where('booking_id', $bookingId)->first()->room_id);
        $this->assertNull($reserved->fresh()->bookingRooms()
            ->where('booking_id', $bookingId)
            ->first());
    }

    /**
     * ⛔ กฎเหล็ก absolute: ห้องติด maintenance period ไม่เข้า pool จัดห้องเด็ดขาด
     *    แม้ admin + flag — เหลือแต่ reserved → ได้ reserved, ไม่มีทางได้ maintenance
     */
    public function test_flag_never_assigns_maintenance_period_room(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();

        $sellable = $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $maintenance = $this->createRoom($roomType);
        $this->createReservedPeriod($reserved, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());
        $this->createMaintenancePeriod($maintenance, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());
        $this->blockRoomWithOtherBooking($sellable, $this->window(2, 4));

        // create ด้วย flag (ห้อง sellable โดนครอบ — จองเข้าช่วง reserved ได้ตาม ticket 03)
        // จุดทดสอบอยู่ที่ allocator pool: assign ด้วย flag ต้องได้ reserved ไม่มีทางได้ maintenance
        $bookingId = $this->createPaidBookingViaHttp($roomType, withFlag: true);

        $response = $this->putJson("/api/v1/bookings/{$bookingId}/assign-rooms", ['include_reserved' => true]);

        $response->assertStatus(200);
        $this->assertSame($reserved->id, $response->json('booking.booking_rooms.0.room_id'));
        $this->assertNull($maintenance->fresh()->bookingRooms()
            ->where('booking_id', $bookingId)
            ->first());
    }

    /**
     * ⛔ ทุกห้องติด maintenance period — แม้ admin + flag ก็ fail สุภาพ (pool ว่างเปล่า)
     *    (สร้าง booking ด้วย model — เคสนี้ capacity check ต้องบล็อกตั้งแต่ create ทาง HTTP
     *    อยู่แล้วตาม design ของ ticket 03; ตั๋วนี้ทดสอบกฎ pool ของ allocator ล้วน ๆ)
     */
    public function test_flag_with_only_maintenance_rooms_fails_politely(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();

        $maintA = $this->createRoom($roomType);
        $maintB = $this->createRoom($roomType);
        $this->createMaintenancePeriod($maintA, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());
        $this->createMaintenancePeriod($maintB, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());

        $booking = $this->createPaidBookingWithUnassignedRoom($roomType);
        $bookingId = $booking->id;

        $response = $this->putJson("/api/v1/bookings/{$bookingId}/assign-rooms", ['include_reserved' => true]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertNull(BookingRoom::where('booking_id', $bookingId)->first()->room_id);
    }

    /**
     * 🔒 Backward-compat: ไม่ส่ง flag — ห้องติด reserved period ไม่เข้า pool เด็ดขาด
     *    (pool เหลือแค่ห้อง sellable ที่ว่าง → ได้ห้อง sellable เสมอ)
     */
    public function test_default_pool_still_excludes_reserved_period_room(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();

        $sellable = $this->createRoom($roomType);
        $reserved = $this->createRoom($roomType);
        $this->createReservedPeriod($reserved, now()->addDays(1)->toDateString(), now()->addDays(5)->toDateString());

        $bookingId = $this->createPaidBookingViaHttp($roomType);

        $response = $this->putJson("/api/v1/bookings/{$bookingId}/assign-rooms");

        $response->assertStatus(200);
        $this->assertSame($sellable->id, $response->json('booking.booking_rooms.0.room_id'));
    }

    /**
     * 🔒 non-admin + flag บน endpoint assign-rooms: route อยู่ใต้ role:admin → 403
     *    (สัญญา silent-ignore ของ flag ใช้กับ endpoint สาธารณะ — ที่นี่ middleware ดักก่อนเสมอ)
     */
    public function test_non_admin_with_flag_is_rejected_by_role_admin_middleware(): void
    {
        $this->actingAsUser();
        $roomType = $this->createRoomType();
        $this->createRoom($roomType);

        $w = $this->window(2, 4);
        $create = $this->postJson('/api/v1/bookings', [
            'source' => 'online',
            'include_reserved' => true,
            'booking_rooms' => [[
                'room_type_id' => $roomType->id,
                'check_in' => $w['check_in'],
                'check_out' => $w['check_out'],
            ]],
        ]);
        $create->assertStatus(201);
        $bookingId = $create->json('booking_id');

        $this->putJson("/api/v1/bookings/{$bookingId}/assign-rooms", ['include_reserved' => true])
            ->assertStatus(403);
    }

    /**
     * 🏨 helper: สร้าง booking 1 ห้องใน W1 ผ่าน HTTP แล้วเลื่อนเป็น paid (ให้ assign-rooms ผ่าน gate)
     *    admin ล็อกอินอยู่แล้ว — withFlag = สร้างพร้อม include_reserved (grill #2)
     */
    private function createPaidBookingViaHttp(RoomType $roomType, bool $withFlag = false): string
    {
        $w = $this->window(2, 4);
        $payload = [
            'source' => 'admin',
            'booking_rooms' => [[
                'room_type_id' => $roomType->id,
                'check_in' => $w['check_in'],
                'check_out' => $w['check_out'],
            ]],
        ];
        if ($withFlag) {
            $payload['include_reserved'] = true;
        }

        $create = $this->postJson('/api/v1/bookings', $payload);
        $create->assertStatus(201);
        $bookingId = $create->json('booking_id');

        $this->putJson("/api/v1/bookings/update/{$bookingId}", ['status' => 'paid'])
            ->assertStatus(200);

        return $bookingId;
    }

    /**
     * 🏨 helper: สร้าง booking paid + BR ยังไม่มี room_id ผ่าน model (prior art
     *    BookingAutoAssignRoomsTest) — ใช้เมื่อจุดทดสอบคือ allocator pool ล้วน ๆ
     */
    private function createPaidBookingWithUnassignedRoom(RoomType $roomType): Booking
    {
        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => User::factory()->create()->id,
            'source' => 'online',
            'status' => 'paid',
            'is_paid' => true,
            'total_amount' => 1500,
        ]);

        $w = $this->window(2, 4);
        $br = BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => null,
            'check_in' => $w['check_in'],
            'check_out' => $w['check_out'],
            'guests' => [
                ['title' => 'mr', 'name' => 'Somchai Jaidee', 'nationality' => 'TH'],
            ],
            'status' => 'confirmed',
            'room_amount' => 1500,
            'discount_amount' => 0,
            'amount' => 1500,
        ]);

        Addon::create([
            'id' => Str::uuid(),
            'booking_room_id' => $br->id,
            'extra_bed' => 0,
        ]);

        return $booking;
    }
}
