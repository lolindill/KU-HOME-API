<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\GlobalRate;
use App\Models\Organization;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🏛️ Empty-head booking — attach user/organization ทีหลัง (wayfinder/organization-bookings ticket 07)
 *
 * ขยาย PUT /bookings/{id} เดิม — guard แยก field-group:
 *   💳 payment fields = draft-only เดิม · 🏛️ customer identity = จนก่อน complete/no_show
 *   - attach/switch: ส่ง identity ใหม่ = แทนที่ทั้งชุด (validation ชุดเดียวกับ POST /bookings)
 *   - detach: ส่ง user/organize = null ชัด ๆ → กลับเฮดเปล่า (เคลียร์ snapshot ครบ)
 *   - reprice เฉพาะตอน draft ผ่าน DiscountService::reprice() (ku_member → daily_ku อัตโนมัติ)
 *   - admin/system เท่านั้น (non-admin 403) · ไม่ re-run draft-dedup ตอน attach
 */
class BookingAttachCustomerTest extends TestCase
{
    use RefreshDatabase;

    private static int $roomSeq = 0;

    // =========================================================
    // 🔧 helpers
    // =========================================================

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

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

        return $rt;
    }

    private function createRoom(RoomType $roomType): Room
    {
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '40'.(++self::$roomSeq),
            'status' => 'available',
        ]);
    }

    /**
     * สร้าง booking เฮดเปล่า (โหมด B — admin ไม่ส่ง user) 2 คืน
     */
    private function createEmptyHeadBooking(User $admin, RoomType $roomType): Booking
    {
        $created = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => Carbon::now()->addDays(5)->toDateString(),
                    'check_out' => Carbon::now()->addDays(7)->toDateString(),
                ],
            ],
        ])->assertStatus(201);

        return Booking::findOrFail($created->json('booking_id'));
    }

    // =========================================================
    // ✅ attach — user / organization บน draft เฮดเปล่า
    // =========================================================

    public function test_admin_can_attach_user_to_empty_head_draft(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'ku_member']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);

        $this->assertNull($booking->user_id);
        $this->assertSame(2400, $booking->total_amount); // daily 1200 × 2 คืน

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['user' => $target->id])
            ->assertStatus(200)
            ->assertJsonPath('user_id', $target->id)
            ->assertJsonPath('organization_id', null);

        $fresh = $booking->fresh();
        $this->assertSame($target->id, $fresh->user_id);
        // 🌟 reprice ตอน draft — ku_member → daily_ku 1000 × 2 คืน
        $this->assertSame(2000, $fresh->total_amount);
    }

    public function test_admin_can_attach_organization_to_empty_head_draft(): void
    {
        $admin = $this->admin();
        $org = Organization::create(['erp' => 'ORG001', 'name' => 'บริษัท ตัวอย่าง จำกัด', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'organize' => 'ORG001',
                'customer_name' => 'สมชาย ใจดี',
                'customer_phone' => '0812345678',
                'customer_email' => 'somchai@example.com',
            ])
            ->assertStatus(200)
            ->assertJsonPath('user_id', null)
            ->assertJsonPath('organization_id', $org->id)
            ->assertJsonPath('customer_name', 'สมชาย ใจดี');

        $fresh = $booking->fresh();
        $this->assertNull($fresh->user_id);
        $this->assertSame($org->id, $fresh->organization_id);
        $this->assertSame('สมชาย ใจดี', $fresh->customer_name);
        $this->assertSame('0812345678', $fresh->customer_phone);
        $this->assertSame('somchai@example.com', $fresh->customer_email);
        // org booking ไม่มี user/role — ราคา daily เดิม ไม่เปลี่ยน
        $this->assertSame(2400, $fresh->total_amount);
    }

    // =========================================================
    // 🔄 switch / detach — กติกาเดียวกันบน endpoint เดียว
    // =========================================================

    public function test_switch_identity_replaces_whole_previous_set(): void
    {
        $admin = $this->admin();
        $orgA = Organization::create(['erp' => 'ORGA', 'name' => 'องค์กร เอ', 'is_active' => true]);
        $orgB = Organization::create(['erp' => 'ORGB', 'name' => 'องค์กร บี', 'is_active' => true]);
        $target = User::factory()->create(['role' => 'user']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);

        // org → org
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'organize' => 'ORGA', 'customer_name' => 'คนเดิม', 'customer_phone' => '0811111111',
            ])->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'organize' => 'ORGB', 'customer_name' => 'คนใหม่',
            ])->assertStatus(200)
            ->assertJsonPath('organization_id', $orgB->id)
            ->assertJsonPath('customer_name', 'คนใหม่');

        $fresh = $booking->fresh();
        $this->assertSame($orgB->id, $fresh->organization_id);
        // "แทนที่ทั้งชุด" — ไม่ส่ง phone = เคลียร์ค่าเดิม
        $this->assertNull($fresh->customer_phone);

        // org → user (org เดิมถูกแทนที่)
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['user' => $target->id])
            ->assertStatus(200)
            ->assertJsonPath('user_id', $target->id)
            ->assertJsonPath('organization_id', null);

        $fresh = $booking->fresh();
        $this->assertSame($target->id, $fresh->user_id);
        $this->assertNull($fresh->organization_id);
        $this->assertNull($fresh->customer_name);
        $this->assertSame($orgA->id, $orgA->fresh()->id); // org rows ยังอยู่ (FK restrict — ไม่ถูกลบ)
    }

    public function test_detach_identity_via_explicit_null_returns_to_empty_head(): void
    {
        $admin = $this->admin();
        Organization::create(['erp' => 'ORGA', 'name' => 'องค์กร เอ', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'organize' => 'ORGA', 'customer_name' => 'สมชาย ใจดี', 'customer_email' => 'a@b.c',
            ])->assertStatus(200);

        // ส่ง organize = null ชัด ๆ → ถอดกลับเฮดเปล่า (เคลียร์ user_id/organization_id/customer_* ครบ)
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['organize' => null])
            ->assertStatus(200)
            ->assertJsonPath('user_id', null)
            ->assertJsonPath('organization_id', null)
            ->assertJsonPath('customer_name', null);

        $fresh = $booking->fresh();
        $this->assertNull($fresh->user_id);
        $this->assertNull($fresh->organization_id);
        $this->assertNull($fresh->customer_name);
        $this->assertNull($fresh->customer_phone);
        $this->assertNull($fresh->customer_email);
    }

    public function test_contact_only_patch_updates_snapshot_fields_individually(): void
    {
        $admin = $this->admin();
        Organization::create(['erp' => 'ORGA', 'name' => 'องค์กร เอ', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'organize' => 'ORGA', 'customer_name' => 'สมชาย ใจดี',
            ])->assertStatus(200);

        // ไม่ส่ง user/organize = แก้ snapshot ผู้ติดต่อเฉพาะ field ที่ส่ง
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['customer_phone' => '0899999999'])
            ->assertStatus(200)
            ->assertJsonPath('customer_name', 'สมชาย ใจดี')
            ->assertJsonPath('customer_phone', '0899999999');

        $fresh = $booking->fresh();
        $this->assertSame('สมชาย ใจดี', $fresh->customer_name);
        $this->assertSame('0899999999', $fresh->customer_phone);
        $this->assertNotNull($fresh->organization_id);
    }

    // =========================================================
    // 🛑 validation — ชุดเดียวกับ POST /bookings (ticket 03)
    // =========================================================

    public function test_attach_user_and_organize_are_mutually_exclusive(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'user']);
        Organization::create(['erp' => 'ORGA', 'name' => 'องค์กร เอ', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", [
                'user' => $target->id, 'organize' => 'ORGA', 'customer_name' => 'สมชาย',
            ])
            ->assertStatus(422);
    }

    public function test_attach_organize_requires_customer_name(): void
    {
        $admin = $this->admin();
        Organization::create(['erp' => 'ORGA', 'name' => 'องค์กร เอ', 'is_active' => true]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['organize' => 'ORGA'])
            ->assertStatus(422);
    }

    public function test_attach_unknown_or_inactive_erp_is_rejected(): void
    {
        $admin = $this->admin();
        Organization::create(['erp' => 'OFF', 'name' => 'องค์กรปิดแล้ว', 'is_active' => false]);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['organize' => 'NO_SUCH_ERP', 'customer_name' => 'สมชาย'])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['organize' => 'OFF', 'customer_name' => 'สมชาย'])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_attach_identity(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);

        // booking ของ user เอง (flow เดิม)
        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/bookings', [
            'source' => 'online',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomType->id,
                    'check_in' => Carbon::now()->addDays(5)->toDateString(),
                    'check_out' => Carbon::now()->addDays(6)->toDateString(),
                ],
            ],
        ]);
        $bookingId = $created->json('booking_id');

        $target = User::factory()->create(['role' => 'ku_member']);
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}", ['user' => $target->id])
            ->assertStatus(403);
    }

    // =========================================================
    // ⏱️ timing — identity จนก่อน complete/no_show · payment = draft-only เดิม
    // =========================================================

    public function test_identity_attach_allowed_after_draft_but_payment_fields_stay_draft_only(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'ku_member']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);
        $booking->update(['status' => 'paid']);

        // 🏛️ identity — ยังแก้ได้หลัง draft (จนก่อน complete) · แต่ reprice ไม่เกิด — ราคาคงเดิม
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['user' => $target->id])
            ->assertStatus(200)
            ->assertJsonPath('user_id', $target->id);

        $fresh = $booking->fresh();
        $this->assertSame(2400, $fresh->total_amount); // ไม่ reprice หลัง draft — ไม่โดน daily_ku

        // 💳 payment fields — draft-only เดิม (422 หลังส่งสลิป/verify)
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['payment_type' => 'deposit'])
            ->assertStatus(422);
    }

    public function test_identity_attach_blocked_on_complete_and_no_show(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'user']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $this->createRoom($roomType); // 2 ห้อง — กัน availability ชนกันระหว่าง 2 บิล

        $completeBooking = $this->createEmptyHeadBooking($admin, $roomType);
        $completeBooking->update(['status' => 'complete']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$completeBooking->id}", ['user' => $target->id])
            ->assertStatus(422);

        $noShowBooking = $this->createEmptyHeadBooking($admin, $roomType);
        $noShowBooking->update(['status' => 'no_show']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$noShowBooking->id}", ['user' => $target->id])
            ->assertStatus(422);
    }

    public function test_detach_user_on_draft_reprices_back_to_daily(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['role' => 'ku_member']);

        $roomType = $this->createRoomTypeWithRates();
        $this->createRoom($roomType);
        $booking = $this->createEmptyHeadBooking($admin, $roomType);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['user' => $target->id])
            ->assertStatus(200);
        $this->assertSame(2000, $booking->fresh()->total_amount); // daily_ku 1000 × 2

        // ถอด → reprice กลับ daily 1200 × 2 (สมมาตรกับ attach)
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['user' => null])
            ->assertStatus(200)
            ->assertJsonPath('user_id', null);

        $this->assertSame(2400, $booking->fresh()->total_amount);
    }
}
