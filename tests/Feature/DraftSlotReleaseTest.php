<?php

namespace Tests\Feature;

use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⏱️ (2026-09-24, SRS v2 REQ-008 + owner decision "draft + payment_deadline รวม 15 นาที"):
 *    - draft booking ล็อกห้องได้ 15 นาทีนับจากสร้าง (เดิม 24 ชม.)
 *    - ภายใน 15 นาที: คนอื่นจองห้องเดียวกันช่วงเดียวกันไม่ได้ (422)
 *    - เลย 15 นาที: ห้องปลดล็อกเป็นว่าง "ทันทีตอน query" ผ่าน scope holdingSlot
 *      (แม้ CleanupExpiredDrafts ยังไม่มาลบ row — sweep ทุก 5 นาทีเป็นการเก็บกวาด)
 */
class DraftSlotReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private User $userB;

    private RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        // ห้องเดียวต่อประเภท — ให้เห็นการล็อก/ปลดล็อก slot ชัดเจน
        $this->roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Double',
            'name_th' => 'สแตนดาร์ด ดับเบิล',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $this->roomType->id,
            'code' => null,
            'name_en' => 'Standard Double Daily',
            'default_price' => 1500,
            'is_active' => true,
        ]);
        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $this->roomType->id,
            'room_number' => '101',
            'status' => 'available',
        ]);
    }

    private function bookingPayload(): array
    {
        $checkIn = Carbon::today()->addDays(5)->toDateString();

        return [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $this->roomType->id,
                    'check_in' => $checkIn,
                    'check_out' => Carbon::parse($checkIn)->addDay()->toDateString(),
                ],
            ],
        ];
    }

    private function availabilityUrl(): string
    {
        $checkIn = Carbon::today()->addDays(5)->toDateString();
        $checkOut = Carbon::today()->addDays(6)->toDateString();

        return "/api/v1/availability?check_in={$checkIn}&check_out={$checkOut}";
    }

    public function test_draft_holds_slot_within_15_minutes(): void
    {
        $first = $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload());
        $first->assertStatus(201);

        // ⏱️ deadline = ~15 นาทีข้างหน้า (ไม่ใช่ 24 ชม. เดิม)
        $deadline = Carbon::parse($first->json('payment_deadline'));
        $this->assertTrue($deadline->greaterThan(now()));
        $this->assertLessThanOrEqual(15 * 60 + 5, now()->diffInSeconds($deadline));
        $this->assertGreaterThanOrEqual(15 * 60 - 5, now()->diffInSeconds($deadline));

        // user B มาจองห้องเดียวกันช่วงเดียวกัน → 422 (slot ถูก draft ของ A ล็อกอยู่)
        $this->actingAs($this->userB, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload())
            ->assertStatus(422);
    }

    public function test_expired_draft_releases_slot_immediately(): void
    {
        $created = $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload());
        $created->assertStatus(201);
        $bookingId = $created->json('booking_id');

        $this->travel(16)->minutes();

        // draft ยังไม่ถูกลบ (sweep ยังไม่มา) — แต่ slot ต้องปลดล็อกแล้ว
        $this->assertDatabaseHas('bookings', ['id' => $bookingId]);

        $this->actingAs($this->userB, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload())
            ->assertStatus(201);
    }

    public function test_availability_endpoint_counts_released_slot(): void
    {
        $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload())
            ->assertStatus(201);

        // ยังภายใน 15 นาที → draft กิน slot → ห้องว่าง 0
        $this->getJson($this->availabilityUrl())
            ->assertStatus(200)
            ->assertJsonPath('room_types.0.available_rooms', 0);

        $this->travel(16)->minutes();

        // เลย 15 นาที → ห้องว่างกลับมาเป็น 1 (แม้ row draft ยังอยู่ใน DB)
        $this->getJson($this->availabilityUrl())
            ->assertStatus(200)
            ->assertJsonPath('room_types.0.available_rooms', 1);
    }

    public function test_cleanup_command_deletes_expired_drafts(): void
    {
        $created = $this->actingAs($this->userA, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload());
        $created->assertStatus(201);
        $bookingId = $created->json('booking_id');

        $this->travel(16)->minutes();

        $this->artisan('app:cleanup-expired-drafts')->assertSuccessful();

        $this->assertDatabaseMissing('bookings', ['id' => $bookingId]);
    }
}
