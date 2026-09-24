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
 * 🗓️ RoomStatePeriodTest — "ห้องสำรอง/ซ่อมแซม" เป็นช่วงเวลาแบบ booking
 *
 *    (wayfinder/room-state-periods 2026-09-24 — ครอบ spec.md §Testing Decisions)
 *    - CRUD + สิทธิ์ (maintenance: admin+staff · reserved: admin เท่านั้น)
 *    - auto-merge same-kind (union, cascade, เปิดปลาย) + kind immutable
 *    - booking effects: ลบ draft ทับทันที (audit draft → deleted) / affected_bookings (ไม่แตะ)
 *      / PATCH หดวัน = ไม่แตะ booking ในช่วงเดิม
 *    - audit logs entity_type room_state_period
 *    - availability (summary + flag + per-day calendar) + room JSON (active_periods)
 *    - gates: walk-in + check-in
 */
class RoomStatePeriodTest extends TestCase
{
    use RefreshDatabase;

    // counter แทน rand() — กันเลขห้องชนกันเอง (precedent FrontDeskTest)
    private static int $roomSeq = 0;

    private function createRoomType(): RoomType
    {
        return RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Double',
            'name_th' => 'สแตนดาร์ด ดับเบิล',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
    }

    private function createRoom(RoomType $roomType, string $status = 'available'): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '9'.(++self::$roomSeq),
            'status' => $status,
        ]);
    }

    private function createBookingWithRoom(Room $room, string $bookingStatus, string $brStatus, string $checkIn, string $checkOut, ?string $paymentDeadline = null): Booking
    {
        $booking = Booking::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'online',
            'status' => $bookingStatus,
            'total_amount' => 3000,
            'payment_deadline' => $paymentDeadline,
        ]);

        BookingRoom::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'room_type_id' => $room->room_type_id,
            'room_id' => $room->id,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'guests' => [['title' => 'mr', 'name' => 'Period Guest', 'nationality' => 'TH']],
            'status' => $brStatus,
        ]);

        return $booking;
    }

    // ============================================
    // 🔐 สิทธิ์ + validation
    // ============================================

    public function test_staff_has_full_crud_on_maintenance_period(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff, 'sanctum');
        $room = $this->createRoom($this->createRoomType());

        $created = $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
        ]);
        $created->assertStatus(201)->assertJson(['status' => 'success', 'merged' => false]);
        $periodId = $created->json('period.id');
        $this->assertNotNull($periodId);

        $updated = $this->patchJson("/api/v1/rooms/{$room->id}/periods/{$periodId}", [
            'end_date' => now()->addDays(5)->toDateString(),
        ]);
        $updated->assertStatus(200)->assertJson(['status' => 'success']);
        $this->assertSame(now()->addDays(5)->toDateString(), $updated->json('period.end_date'));

        $index = $this->getJson("/api/v1/rooms/{$room->id}/periods");
        $index->assertStatus(200)->assertJsonCount(1, 'periods');

        $deleted = $this->deleteJson("/api/v1/rooms/{$room->id}/periods/{$periodId}");
        $deleted->assertStatus(200)->assertJson(['status' => 'success']);
        $this->assertDatabaseMissing('room_state_periods', ['id' => $periodId]);
    }

    public function test_staff_cannot_manage_reserved_period(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff, 'sanctum');
        $room = $this->createRoom($this->createRoomType());

        // POST kind=reserved → 403 (admin เท่านั้น)
        $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'reserved',
            'start_date' => now()->toDateString(),
        ])->assertStatus(403);

        // สร้างโดย admin แล้ว staff PATCH/DELETE → 403
        $admin = User::factory()->create(['role' => 'admin']);
        $period = RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'reserved',
            'start_date' => now()->toDateString(),
            'end_date' => null,
            'created_by' => $admin->id,
        ]);

        $this->patchJson("/api/v1/rooms/{$room->id}/periods/{$period->id}", [
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertStatus(403);

        $this->deleteJson("/api/v1/rooms/{$room->id}/periods/{$period->id}")->assertStatus(403);

        // แต่ GET index เปิดทั้งสอง role เห็นทุก kind (ข้อมูลห้อง public อยู่แล้ว)
        $this->getJson("/api/v1/rooms/{$room->id}/periods")
            ->assertStatus(200)
            ->assertJsonCount(1, 'periods');
    }

    public function test_regular_user_cannot_access_period_routes(): void
    {
        $this->actingAsUser();
        $room = $this->createRoom($this->createRoomType());

        $this->getJson("/api/v1/rooms/{$room->id}/periods")->assertStatus(403);
        $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
        ])->assertStatus(403);
    }

    public function test_store_validates_kind_and_dates(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());

        // kind ห้ามอื่น
        $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'party',
            'start_date' => now()->toDateString(),
        ])->assertStatus(422);

        // ห้าม period หมดแล้วทั้งช่วง (end < วันนี้ — ticket 04)
        $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ])->assertStatus(422);

        // end <= start ห้าม (end exclusive — ต้องมากกว่า)
        $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('room_state_periods', 0);
    }

    public function test_patch_kind_is_immutable(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());
        $period = RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
            'end_date' => null,
        ]);

        $this->patchJson("/api/v1/rooms/{$room->id}/periods/{$period->id}", [
            'kind' => 'reserved',
        ])->assertStatus(422);

        $this->assertSame('maintenance', $period->fresh()->kind);
    }

    // ============================================
    // 🔀 auto-merge same-kind
    // ============================================

    public function test_store_overlapping_same_kind_merges_into_existing_row(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());
        $existing = RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $response = $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->addDays(7)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(),
        ]);

        // row เดิมขยายครอบ union — id/created_by เดิมคงอยู่ (ticket 04)
        $response->assertStatus(201)->assertJson(['merged' => true]);
        $this->assertSame($existing->id, $response->json('period.id'));
        $this->assertSame(now()->addDays(5)->toDateString(), $response->json('period.start_date'));
        $this->assertSame(now()->addDays(12)->toDateString(), $response->json('period.end_date'));
        $this->assertDatabaseCount('room_state_periods', 1);
    }

    public function test_store_inside_existing_window_returns_row_as_is_without_write(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());
        $existing = RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $response = $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->addDays(6)->toDateString(),
            'end_date' => now()->addDays(8)->toDateString(),
        ]);

        // ซ้อนข้างในจนไม่ขยายอะไร → row เดิมตามสภาพ ไม่มีการเขียน (ticket 04)
        $response->assertStatus(201)->assertJson(['merged' => true]);
        $this->assertSame($existing->id, $response->json('period.id'));
        $this->assertSame(now()->addDays(5)->toDateString(), $response->json('period.start_date'));
        $this->assertSame(now()->addDays(10)->toDateString(), $response->json('period.end_date'));
        $this->assertDatabaseCount('room_state_periods', 1);
        $this->assertDatabaseMissing('status_change_logs', [
            'entity_type' => 'room_state_period',
            'entity_id' => $existing->id,
            'note' => 'merged',
        ]);
    }

    public function test_store_merge_with_open_ended_row_stays_open_ended(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());
        $existing = RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'reserved',
            'start_date' => now()->toDateString(),
            'end_date' => null,
        ]);

        $response = $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'reserved',
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(40)->toDateString(),
        ]);

        // union กับ ∞ = ∞ (ticket 05)
        $response->assertStatus(201)->assertJson(['merged' => true]);
        $this->assertSame($existing->id, $response->json('period.id'));
        $this->assertNull($response->json('period.end_date'));
    }

    public function test_store_different_kinds_coexist(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());

        $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'reserved',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ])->assertStatus(201);

        $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
        ])->assertStatus(201);

        $this->assertDatabaseCount('room_state_periods', 2);
    }

    public function test_patch_extending_over_other_row_absorbs_it(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());
        $first = RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(8)->toDateString(),
        ]);
        $second = RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->addDays(12)->toDateString(),
        ]);

        // PATCH ยืด end จนทับ row หลัง → merge ทันที (กฎเดียวกับ POST — ticket 04)
        $response = $this->patchJson("/api/v1/rooms/{$room->id}/periods/{$first->id}", [
            'end_date' => now()->addDays(11)->toDateString(),
        ]);

        $response->assertStatus(200)->assertJson(['merged' => true]);
        $this->assertSame(now()->addDays(12)->toDateString(), $response->json('period.end_date'));
        $this->assertDatabaseMissing('room_state_periods', ['id' => $second->id]);
        $this->assertDatabaseCount('room_state_periods', 1);
    }

    // ============================================
    // 💥 booking effects (ticket 02)
    // ============================================

    public function test_store_deletes_overlapping_draft_booking_with_audit(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());
        $draft = $this->createBookingWithRoom(
            $room,
            bookingStatus: 'draft',
            brStatus: 'draft',
            checkIn: now()->addDays(1)->toDateString(),
            checkOut: now()->addDays(3)->toDateString(),
            paymentDeadline: now()->addMinutes(15),
        );

        $response = $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        // draft ที่ overlap → ลบทันที + audit draft → deleted (กลไกเดียวกับ DELETE /bookings/{id})
        $response->assertStatus(201);
        $this->assertSame([$draft->id], $response->json('deleted_drafts'));
        $this->assertDatabaseMissing('bookings', ['id' => $draft->id]);
        $this->assertDatabaseMissing('booking_rooms', ['booking_id' => $draft->id]);
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'booking',
            'entity_id' => $draft->id,
            'from_status' => 'draft',
            'to_status' => 'deleted',
        ]);
    }

    public function test_store_reports_affected_confirmed_bookings_without_touching_them(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());
        $confirmed = $this->createBookingWithRoom(
            $room,
            bookingStatus: 'confirmed',
            brStatus: 'confirmed',
            checkIn: now()->addDays(1)->toDateString(),
            checkOut: now()->addDays(3)->toDateString(),
        );

        $response = $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        // confirmed/checked_in → ไม่แตะ แค่รายงาน affected_bookings ให้ admin เก็บงานเอง
        $response->assertStatus(201);
        $this->assertSame([], $response->json('deleted_drafts'));
        $affected = $response->json('affected_bookings');
        $this->assertCount(1, $affected);
        $this->assertSame($confirmed->id, $affected[0]['booking_id']);
        $this->assertSame('confirmed', $affected[0]['status']);
        $this->assertDatabaseHas('bookings', ['id' => $confirmed->id, 'status' => 'confirmed']);
    }

    public function test_expired_draft_does_not_block_or_get_deleted(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());
        // draft ที่ payment_deadline ผ่านไปแล้วไม่กิน slot (holdingSlot) — period ไม่ต้องไปยุ่ง
        $expired = $this->createBookingWithRoom(
            $room,
            bookingStatus: 'draft',
            brStatus: 'draft',
            checkIn: now()->addDays(1)->toDateString(),
            checkOut: now()->addDays(3)->toDateString(),
            paymentDeadline: now()->subMinutes(30),
        );

        $response = $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $response->assertStatus(201);
        $this->assertSame([], $response->json('deleted_drafts'));
        $this->assertDatabaseHas('bookings', ['id' => $expired->id]);
    }

    public function test_patch_shrinking_window_does_not_touch_bookings_in_old_window(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());

        // period ครอบ [today, today+10) ก่อน
        $created = $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ])->assertStatus(201);
        $periodId = $created->json('period.id');

        // draft เกิดทีหลัง ทับวันกลาง ๆ ของช่วงเดิม (สร้างตรง model — createBooking ไม่เช็ค period
        // ตาม non-goal ของ spec · มี room_id แบบที่ admin assign มือได้)
        $draft = $this->createBookingWithRoom(
            $room,
            bookingStatus: 'draft',
            brStatus: 'draft',
            checkIn: now()->addDays(4)->toDateString(),
            checkOut: now()->addDays(6)->toDateString(),
            paymentDeadline: now()->addMinutes(15),
        );

        // PATCH หดวัน (ซ่อมเสร็จก่อนกำหนด) — ห้ามแตะ booking ที่ค้างในช่วงเดิม
        $response = $this->patchJson("/api/v1/rooms/{$room->id}/periods/{$periodId}", [
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertStatus(200)->assertJson(['merged' => false, 'deleted_drafts' => [], 'affected_bookings' => []]);
        $this->assertDatabaseHas('bookings', ['id' => $draft->id]);
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room_state_period',
            'entity_id' => $periodId,
            'note' => 'shortened',
        ]);
    }

    // ============================================
    // 📝 audit trail
    // ============================================

    public function test_period_events_write_audit_logs_that_survive_delete(): void
    {
        $this->actingAsAdmin();
        $room = $this->createRoom($this->createRoomType());

        $created = $this->postJson("/api/v1/rooms/{$room->id}/periods", [
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
        ])->assertStatus(201);
        $periodId = $created->json('period.id');

        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room_state_period',
            'entity_id' => $periodId,
            'from_status' => '-',
            'to_status' => now()->toDateString().'..NULL',
            'note' => 'created',
        ]);

        // hard delete → log เก็บไว้ (append-only — row คือตารางเวลา ไม่ใช่หลักฐานการเงิน)
        $this->deleteJson("/api/v1/rooms/{$room->id}/periods/{$periodId}")->assertStatus(200);
        $this->assertDatabaseHas('status_change_logs', [
            'entity_type' => 'room_state_period',
            'entity_id' => $periodId,
            'to_status' => 'deleted',
            'note' => 'deleted',
        ]);
    }

    // ============================================
    // 🛏️ availability integration
    // ============================================

    public function test_summary_availability_cuts_room_only_on_days_covered_by_period(): void
    {
        $roomType = $this->createRoomType();
        $roomA = $this->createRoom($roomType);
        $this->createRoom($roomType);

        // maintenance period เฉพาะ [today+5, today+8)
        RoomStatePeriod::create([
            'room_id' => $roomA->id,
            'kind' => 'maintenance',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(8)->toDateString(),
        ]);

        // ช่วงทับ period → เหลือ 1 ห้อง
        $inside = $this->getJson('/api/v1/availability?'.http_build_query([
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
        ]));
        $insideRow = collect($inside->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $this->assertEquals(1, $insideRow['available_rooms']);

        // ช่วงนอก period → ขายปกติ 2 ห้อง (derived — ไม่มี sweep)
        $outside = $this->getJson('/api/v1/availability?'.http_build_query([
            'check_in' => now()->addDays(10)->toDateString(),
            'check_out' => now()->addDays(11)->toDateString(),
        ]));
        $outsideRow = collect($outside->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $this->assertEquals(2, $outsideRow['available_rooms']);
    }

    public function test_reserved_period_counts_only_for_admin_flag(): void
    {
        $roomType = $this->createRoomType();
        $roomA = $this->createRoom($roomType);
        $this->createRoom($roomType);
        RoomStatePeriod::create([
            'room_id' => $roomA->id,
            'kind' => 'reserved',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(8)->toDateString(),
        ]);
        $window = [
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
        ];

        // anonymous ไม่ส่ง flag → 1 ห้อง (reserved period ถูกตัด)
        $anon = $this->getJson('/api/v1/availability?'.http_build_query($window));
        $anonRow = collect($anon->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $this->assertEquals(1, $anonRow['available_rooms']);
        $this->assertArrayNotHasKey('reserved_rooms', $anonRow);

        // admin ส่ง flag → available = sellable + reserved − booked + breakdown โปร่งใส
        $this->actingAsAdmin();
        $flagged = $this->getJson('/api/v1/availability?'.http_build_query(array_merge($window, ['include_reserved' => 'true'])));
        $flaggedRow = collect($flagged->json('room_types'))->firstWhere('room_type_id', $roomType->id);
        $this->assertEquals(2, $flaggedRow['available_rooms']);
        $this->assertEquals(1, $flaggedRow['sellable_rooms']);
        $this->assertEquals(1, $flaggedRow['reserved_rooms']);
        $this->assertTrue($flaggedRow['search_criteria']['include_reserved']);
    }

    public function test_non_admin_flag_is_silently_ignored(): void
    {
        $roomType = $this->createRoomType();
        $roomA = $this->createRoom($roomType);
        $this->createRoom($roomType);
        RoomStatePeriod::create([
            'room_id' => $roomA->id,
            'kind' => 'reserved',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => null,
        ]);
        $window = [
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
        ];

        $this->actingAsUser();
        $flagged = $this->getJson('/api/v1/availability?'.http_build_query(array_merge($window, ['include_reserved' => 'true'])));
        $row = collect($flagged->json('room_types'))->firstWhere('room_type_id', $roomType->id);

        // เมยายีเงียบ ๆ — payload เท่าไม่ส่ง flag (ห้าม 403)
        $this->assertEquals(1, $row['available_rooms']);
        $this->assertArrayNotHasKey('reserved_rooms', $row);
    }

    public function test_maintenance_period_beats_reserved_in_flag_breakdown(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $roomA = $this->createRoom($roomType);
        $window = [
            'check_in' => now()->addDays(6)->toDateString(),
            'check_out' => now()->addDays(7)->toDateString(),
        ];

        // ต่าง kind ร่วมอยู่ได้ — ช่วงทับ maintenance ชนะทุก semantics (ticket 04)
        RoomStatePeriod::create([
            'room_id' => $roomA->id,
            'kind' => 'reserved',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(8)->toDateString(),
        ]);
        RoomStatePeriod::create([
            'room_id' => $roomA->id,
            'kind' => 'maintenance',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(8)->toDateString(),
        ]);

        $response = $this->getJson('/api/v1/availability?'.http_build_query(array_merge($window, ['include_reserved' => 'true'])));
        $row = collect($response->json('room_types'))->firstWhere('room_type_id', $roomType->id);

        // ห้องเดียวของ type — โดน maintenance ตัดหมด แม้ส่ง flag
        $this->assertEquals(0, $row['available_rooms']);
        $this->assertEquals(0, $row['reserved_rooms']);
    }

    public function test_per_day_calendar_includes_periods_in_matrix(): void
    {
        $roomType = $this->createRoomType();
        $roomA = $this->createRoom($roomType);
        $this->createRoom($roomType);
        RoomStatePeriod::create([
            'room_id' => $roomA->id,
            'kind' => 'maintenance',
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(), // exclusive — คืน +6 เป็นคืนสุดท้ายที่ห้องหาย
        ]);

        $response = $this->getJson('/api/v1/availability-per-day?'.http_build_query([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(9)->toDateString(),
        ]));

        $response->assertStatus(200);
        $row = collect($response->json('room_types'))->firstWhere('room_type_id', $roomType->id);

        $this->assertEquals(2, $row[now()->addDays(4)->toDateString()]);
        $this->assertEquals(1, $row[now()->addDays(5)->toDateString()]);
        $this->assertEquals(1, $row[now()->addDays(6)->toDateString()]);
        $this->assertEquals(2, $row[now()->addDays(7)->toDateString()]);
    }

    // ============================================
    // 🖥️ room JSON — active_periods แทน is_reserved (ticket 06)
    // ============================================

    public function test_room_json_exposes_active_periods_and_drops_is_reserved(): void
    {
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);
        $active = RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'start_date' => now()->subDays(1)->toDateString(),
            'end_date' => null,
        ]);
        RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'reserved',
            'start_date' => now()->addDays(30)->toDateString(), // อนาคต — ไม่ active วันนี้
            'end_date' => null,
        ]);

        $response = $this->getJson("/api/v1/rooms/{$room->id}");
        $response->assertStatus(200);
        $payload = $response->json('room');

        // ⚠️ breaking change: is_reserved หายพร้อม column — active_periods มาแทน
        $this->assertArrayNotHasKey('is_reserved', $payload);
        $this->assertCount(1, $payload['active_periods']);
        $this->assertSame($active->id, $payload['active_periods'][0]['id']);
        $this->assertSame('maintenance', $payload['active_periods'][0]['kind']);
        $this->assertNull($payload['active_periods'][0]['end_date']);

        // board endpoint ก็ได้ชุดเดียวกัน
        $board = $this->getJson('/api/v1/rooms');
        $boardRoom = collect($board->json('rooms'))->firstWhere('id', $room->id);
        $this->assertArrayNotHasKey('is_reserved', $boardRoom);
        $this->assertCount(1, $boardRoom['active_periods']);
    }

    // ============================================
    // 🚶 gates: walk-in + check-in
    // ============================================

    public function test_walkin_rejects_maintenance_period_and_reserved_without_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');

        $maintRoom = $this->createRoom($this->createRoomType());
        RoomStatePeriod::create([
            'room_id' => $maintRoom->id,
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
            'end_date' => null,
        ]);
        $this->postJson('/api/v1/front-desk/walk-in', [
            'verified_by' => $admin->id,
            'room_id' => $maintRoom->id,
            'nights' => 2,
        ])->assertStatus(400)->assertJson(['status' => 'error']);

        $reservedRoom = $this->createRoom($this->createRoomType());
        RoomStatePeriod::create([
            'room_id' => $reservedRoom->id,
            'kind' => 'reserved',
            'start_date' => now()->toDateString(),
            'end_date' => null,
        ]);
        // ไม่ส่ง flag → reject (เหมือน is_reserved เดิม)
        $this->postJson('/api/v1/front-desk/walk-in', [
            'verified_by' => $admin->id,
            'room_id' => $reservedRoom->id,
            'nights' => 2,
        ])->assertStatus(400);
    }

    public function test_walkin_into_reserved_period_passes_with_admin_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum');
        $room = $this->createRoom($this->createRoomType());
        RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'reserved',
            'start_date' => now()->toDateString(),
            'end_date' => null,
        ]);

        // reserved period + flag (admin) → ผ่าน (ticket 02 ข้อ 3)
        $this->postJson('/api/v1/front-desk/walk-in', [
            'verified_by' => $admin->id,
            'room_id' => $room->id,
            'nights' => 2,
            'include_reserved' => true,
        ])->assertStatus(201);

        $this->assertDatabaseHas('booking_rooms', ['room_id' => $room->id, 'status' => 'checked_in']);
    }

    public function test_checkin_rejects_room_with_maintenance_period_overlap(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);
        $booking = $this->createBookingWithRoom(
            $room,
            bookingStatus: 'confirmed',
            brStatus: 'confirmed',
            checkIn: now()->toDateString(),
            checkOut: now()->addDays(2)->toDateString(),
        );
        $br = $booking->bookingRooms()->first();
        $br->update(['room_id' => null]);

        // maintenance period ครอบช่วงพักของ BR → check-in reject (ticket 02 ข้อ 3)
        RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'maintenance',
            'start_date' => now()->toDateString(),
            'end_date' => null,
        ]);

        $this->postJson("/api/v1/front-desk/{$booking->id}/check-in", [
            'assigned_rooms' => [$room->id],
        ])->assertStatus(422)->assertJson(['status' => 'error']);

        // ไม่มีอะไร transition
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_checkin_into_reserved_period_passes(): void
    {
        $this->actingAsAdmin();
        $roomType = $this->createRoomType();
        $room = $this->createRoom($roomType);
        $booking = $this->createBookingWithRoom(
            $room,
            bookingStatus: 'confirmed',
            brStatus: 'confirmed',
            checkIn: now()->toDateString(),
            checkOut: now()->addDays(2)->toDateString(),
        );
        $br = $booking->bookingRooms()->first();
        $br->update(['room_id' => null]);

        // reserved period ไม่บล็อก check-in — booking include_reserved ของ admin ถูกต้องแล้ว
        RoomStatePeriod::create([
            'room_id' => $room->id,
            'kind' => 'reserved',
            'start_date' => now()->toDateString(),
            'end_date' => null,
        ]);

        $this->postJson("/api/v1/front-desk/{$booking->id}/check-in", [
            'assigned_rooms' => [$room->id],
        ])->assertStatus(200);

        $this->assertSame('checked_in', $br->fresh()->status);
        $this->assertSame('occupied', $room->fresh()->status);
    }
}
