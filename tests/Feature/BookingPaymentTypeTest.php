<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingConfirmation;
use App\Models\GlobalRate;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\LegacyPaymentBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 💳 (25/09/26) Booking Payment Types — full | deposit | deferred
 *
 * ครอบคลุม wayfinder/booking-payment-types tickets 01–05:
 *   - full regression 0% (flow เดิม + ledger row จาก verify)
 *   - deposit (effective 50% / ยอดกำหนดเอง · verify → is_paid false · เก็บส่วนเหลือผ่าน recordPayment)
 *   - deferred (บล็อกสลิป · admin อนุมัติ draft → confirmed · เก็บปลายทาง)
 *   - สิทธิ์ payment fields (admin/system เท่านั้น — non-admin 403)
 *   - PUT /bookings/{id} (endpoint ใหม่ — draft only)
 *   - reject → ส่งสลิปใหม่ (row ใหม่ · ledger ไม่เปลี่ยน)
 *   - backfill ledger (booking เดิม paid/confirmed = row เต็ม)
 *   - CleanupExpiredDrafts ข้าม deferred + draft ที่มี payments row
 *   - slot-holding: draft deferred ยึด slot แม้เลย deadline
 */
class BookingPaymentTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // =========================================================
    // 🔧 helpers
    // =========================================================

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function roomTypeSetup(int $price = 1500, int $rooms = 1): RoomType
    {
        $roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard',
            'name_th' => 'สแตนดาร์ด',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $roomType->id,
            'code' => null,
            'name_en' => 'Standard Daily',
            'default_price' => $price,
            'is_active' => true,
        ]);
        // ห้องพักจริง — sellableCapacity ใช้นับ availability (ไม่มีห้อง = จองไม่ได้ 422)
        for ($i = 1; $i <= $rooms; $i++) {
            Room::create([
                'id' => Str::uuid(),
                'room_type_id' => $roomType->id,
                'room_number' => '1'.$i,
                'status' => 'available',
            ]);
        }

        return $roomType;
    }

    private function bookingPayload(string $roomTypeId, array $overrides = []): array
    {
        $checkIn = now()->addDays(5)->toDateString();

        return array_merge([
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomTypeId,
                    'check_in' => $checkIn,
                    'check_out' => now()->addDays(6)->toDateString(),
                ],
            ],
        ], $overrides);
    }

    private function slipFile(): UploadedFile
    {
        return UploadedFile::fake()->image('slip.jpg', 800, 600);
    }

    /**
     * ส่งสลิป (confirm endpoint) — amount default = ยอดที่ส่งมา
     */
    private function submitSlip(Booking $booking, ?User $as = null, int $amount = 4500)
    {
        return ($as ? $this->actingAs($as, 'sanctum') : $this)
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", [
                'slip_image' => $this->slipFile(),
                'amount' => $amount,
            ]);
    }

    // =========================================================
    // ✅ full — regression 0% + ledger
    // =========================================================

    public function test_full_flow_default_regression_with_ledger(): void
    {
        $roomType = $this->roomTypeSetup();
        $user = User::factory()->create(['role' => 'user']);

        // ไม่ส่ง payment_type → full เสมอ
        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id));
        $created->assertStatus(201)
            ->assertJsonPath('payment_type', 'full')
            ->assertJsonPath('deposit_amount', null)
            ->assertJsonPath('paid_amount', 0);

        $booking = Booking::findOrFail($created->json('booking_id'));
        $this->assertEquals(1500, $booking->total_amount);
        $this->assertEquals(1500, $booking->outstanding_amount);

        // สลิปเต็มจำนวน → verify → paid → confirmed + is_paid true (flow เดิม)
        $this->submitSlip($booking, $user, 1500)
            ->assertStatus(201)
            ->assertJsonPath('booking_status', 'pending');

        $confirmation = BookingConfirmation::where('booking_id', $booking->id)->firstOrFail();

        $verified = $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify");

        $verified->assertStatus(200)
            ->assertJsonPath('booking_status', 'confirmed')
            // 💳 หลัง verify เต็มจำนวน — ledger เขียนแล้ว expected = outstanding = 0 (claimed ยัง echo 1500)
            ->assertJsonPath('expected_amount', 0)
            ->assertJsonPath('claimed_amount', 1500)
            ->assertJsonPath('paid_amount', 1500)
            ->assertJsonPath('outstanding_amount', 0);

        // 💳 verify เขียน payments ledger row (reference = confirmation UUID)
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'amount' => 1500,
            'status' => 'completed',
            'reference_number' => $confirmation->id,
        ]);
        $this->assertTrue($booking->fresh()->is_paid);
    }

    // =========================================================
    // 💰 deposit
    // =========================================================

    public function test_deposit_default_50_percent_and_partial_ledger(): void
    {
        $roomType = $this->roomTypeSetup();
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['payment_type' => 'deposit']));
        $created->assertStatus(201)
            ->assertJsonPath('payment_type', 'deposit')
            // effective = ceil(1500 × 50%) = 750
            ->assertJsonPath('deposit_amount', 750);

        $booking = Booking::findOrFail($created->json('booking_id'));

        // สลิปมัดจำ → verify → confirmed (เหมือนเดิม) แต่ is_paid ยัง false
        $this->submitSlip($booking, $admin, 750)->assertStatus(201);
        $confirmation = BookingConfirmation::where('booking_id', $booking->id)->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify")
            ->assertStatus(200)
            ->assertJsonPath('booking_status', 'confirmed')
            ->assertJsonPath('paid_amount', 750)
            ->assertJsonPath('outstanding_amount', 750)
            ->assertJsonPath('deposit_amount', 750);

        $this->assertFalse($booking->fresh()->is_paid, 'มัดจำผ่าน ≠ จ่ายครบ — is_paid ต้อง false');

        // เก็บส่วนที่เหลือที่เคาน์เตอร์ → ครบยอด is_paid = true · สถานะคง confirmed
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/payment", ['amount' => 750])
            ->assertStatus(201)
            ->assertJsonPath('booking_is_paid', true)
            ->assertJsonPath('booking_status', 'confirmed')
            ->assertJsonPath('paid_amount', 1500)
            ->assertJsonPath('outstanding_amount', 0);

        $this->assertTrue($booking->fresh()->is_paid);
        $this->assertEquals(2, Payment::where('booking_id', $booking->id)->count(), 'ledger 2 rows — 1 งวดสลิป + 1 เงินสด');
    }

    public function test_deposit_custom_amount_by_admin(): void
    {
        $roomType = $this->roomTypeSetup();

        $created = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, [
                'payment_type' => 'deposit',
                'deposit_amount' => 500,
            ]));

        $created->assertStatus(201)
            ->assertJsonPath('deposit_amount', 500);
    }

    public function test_deposit_amount_without_deposit_type_is_422(): void
    {
        $roomType = $this->roomTypeSetup();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['deposit_amount' => 500]))
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_set_payment_fields(): void
    {
        $roomType = $this->roomTypeSetup();
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['payment_type' => 'deposit']))
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['deposit_amount' => 500]))
            ->assertStatus(403);
    }

    // =========================================================
    // ⏸️ deferred
    // =========================================================

    public function test_deferred_blocks_slip_and_confirms_via_admin(): void
    {
        $roomType = $this->roomTypeSetup();
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['payment_type' => 'deferred']));
        $created->assertStatus(201)->assertJsonPath('payment_type', 'deferred');

        $booking = Booking::findOrFail($created->json('booking_id'));

        // 💳 deferred บล็อกสลิป 422 — ก่อน state guard เดิม (draft ก็ block)
        $this->submitSlip($booking, $admin, 1500)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertEquals(0, BookingConfirmation::where('booking_id', $booking->id)->count());

        // admin อนุมัติผ่าน updateStatus เดิม — draft → confirmed (ข้าม paid) ไม่มี endpoint ใหม่
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/update/{$booking->id}", ['status' => 'confirmed'])
            ->assertStatus(200)
            ->assertJsonPath('booking_status', 'confirmed')
            ->assertJsonPath('payment_type', 'deferred')
            ->assertJsonPath('paid_amount', 0)
            ->assertJsonPath('outstanding_amount', 1500);

        $this->assertFalse($booking->fresh()->is_paid);

        // เก็บเงินปลายทาง — สถานะคง confirmed จนครบยอด
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/payment", ['amount' => 1500])
            ->assertStatus(201)
            ->assertJsonPath('booking_is_paid', true)
            ->assertJsonPath('booking_status', 'confirmed');

        $this->assertTrue($booking->fresh()->is_paid);
    }

    // =========================================================
    // 🆕 PUT /bookings/{id} (admin แก้ draft)
    // =========================================================

    public function test_admin_can_update_draft_payment_fields(): void
    {
        $roomType = $this->roomTypeSetup();
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['payment_type' => 'deposit']));
        $bookingId = $created->json('booking_id');

        // แก้ยอดมัดจำเป็นตัวเลขตายตัว
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}", ['deposit_amount' => 600])
            ->assertStatus(200)
            ->assertJsonPath('deposit_amount', 600);

        // ส่ง null ชัด ๆ → revert effective 50% (750)
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}", ['deposit_amount' => null])
            ->assertStatus(200)
            ->assertJsonPath('deposit_amount', 750);

        // deposit_amount กับ type ที่ไม่ใช่ deposit → 422
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}", ['payment_type' => 'full', 'deposit_amount' => 600])
            ->assertStatus(422);

        // เปลี่ยน type ได้ใน draft — deposit_amount effective เป็น null (type ≠ deposit)
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}", ['payment_type' => 'deferred'])
            ->assertStatus(200)
            ->assertJsonPath('payment_type', 'deferred')
            ->assertJsonPath('deposit_amount', null);
    }

    public function test_update_payment_fields_blocked_after_draft(): void
    {
        $roomType = $this->roomTypeSetup();
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id));
        $booking = Booking::findOrFail($created->json('booking_id'));
        $booking->update(['status' => 'paid']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}", ['payment_type' => 'deposit'])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_access_update_payment_endpoint(): void
    {
        $roomType = $this->roomTypeSetup();
        $user = User::factory()->create(['role' => 'user']);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id));
        $bookingId = $created->json('booking_id');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$bookingId}", ['payment_type' => 'deposit'])
            ->assertStatus(403);
    }

    // =========================================================
    // ❌ reject → ส่งสลิปใหม่ (row ใหม่ · ledger ถูกต้อง)
    // =========================================================

    public function test_reject_then_resubmit_keeps_ledger_correct(): void
    {
        $roomType = $this->roomTypeSetup();
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['payment_type' => 'deposit']));
        $booking = Booking::findOrFail($created->json('booking_id'));

        // สลิป #1 (มัดจำ 750) → reject → verify_error · ไม่มี payments row
        $this->submitSlip($booking, $admin, 750)->assertStatus(201);
        $first = BookingConfirmation::where('booking_id', $booking->id)->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/booking-confirmations/{$first->id}/reject")
            ->assertStatus(200)
            ->assertJsonPath('booking_status', 'verify_error')
            ->assertJsonPath('paid_amount', 0)
            ->assertJsonPath('outstanding_amount', 1500);

        $this->assertEquals(0, Payment::where('booking_id', $booking->id)->count(), 'reject ไม่เขียน ledger');

        // สลิป #2 (row ใหม่) → verify → ledger เขียน 1 row
        $this->submitSlip($booking, $admin, 750)->assertStatus(201);
        $second = BookingConfirmation::where('booking_id', $booking->id)
            ->where('status', 'pending')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/booking-confirmations/{$second->id}/verify")
            ->assertStatus(200)
            ->assertJsonPath('booking_status', 'confirmed')
            ->assertJsonPath('paid_amount', 750);

        $this->assertEquals(1, Payment::where('booking_id', $booking->id)->count());
    }

    // =========================================================
    // 🆕 backfill ledger (booking เดิม paid/confirmed)
    // =========================================================

    public function test_backfill_creates_full_payment_row_for_legacy_paid_bookings(): void
    {
        $paid = Booking::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'online',
            'status' => 'confirmed',
            'total_amount' => 4500,
        ]);
        $draft = Booking::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 2000,
            'payment_deadline' => now()->addHour(),
        ]);

        // รัน backfill (จำลอง migrate บนข้อมูลเดิม — migration เองรันตอน RefreshDatabase ตอนที่ยังไม่มี data)
        LegacyPaymentBackfill::run();

        $this->assertDatabaseHas('payments', [
            'booking_id' => $paid->id,
            'amount' => 4500,
            'status' => 'completed',
            'reference_number' => 'legacy-backfill:'.$paid->id,
        ]);
        $this->assertEquals(4500, $paid->fresh()->paid_amount);
        $this->assertEquals(0, $paid->fresh()->outstanding_amount);

        // draft ไม่ถูก backfill (ยังไม่จ่ายจริง)
        $this->assertEquals(0, Payment::where('booking_id', $draft->id)->count());

        // idempotent — รันซ้ำไม่เพิ่ม row
        LegacyPaymentBackfill::run();
        $this->assertEquals(1, Payment::where('booking_id', $paid->id)->count());
    }

    // =========================================================
    // 🧹 CleanupExpiredDrafts — ข้าม deferred + draft ที่มี payments row
    // =========================================================

    public function test_cleanup_skips_deferred_and_drafts_with_payments(): void
    {
        $deferred = Booking::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'admin',
            'status' => 'draft',
            'payment_type' => 'deferred',
            'total_amount' => 1000,
            'payment_deadline' => now()->subHour(), // เลย deadline — แต่ deferred ต้องรอด
        ]);

        $withMoney = Booking::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 1000,
            'payment_deadline' => now()->subHour(),
        ]);
        Payment::create(['booking_id' => $withMoney->id, 'amount' => 300, 'status' => 'completed']);

        $normal = Booking::create([
            'user_id' => User::factory()->create()->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 1000,
            'payment_deadline' => now()->subHour(),
        ]);

        $this->artisan('app:cleanup-expired-drafts')->assertSuccessful();

        // deferred ต้องไม่ถูกลบ
        $this->assertDatabaseHas('bookings', ['id' => $deferred->id]);
        // draft ที่มี payments row ต้องไม่ถูกลบ (กัน ledger หาย)
        $this->assertDatabaseHas('bookings', ['id' => $withMoney->id]);
        // draft ปกติหมดเวลาถูกลบ
        $this->assertDatabaseMissing('bookings', ['id' => $normal->id]);
    }

    // =========================================================
    // 🚪 slot-holding — draft deferred ยึด slot แม้เลย deadline
    // =========================================================

    public function test_deferred_draft_holds_slot_past_deadline(): void
    {
        $roomType = $this->roomTypeSetup(); // ห้องเดียว — เห็นการยึด slot ชัดเจน
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['payment_type' => 'deferred']));
        $created->assertStatus(201);

        $this->travel(16)->minutes(); // เลย 15 นาที — draft ปกติปล่อย slot แล้ว

        // user ทั่วไปมาจองห้องเดียวกัน → ยัง 422 เพราะ deferred ยึด slot จน admin ตัดสินใจ
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['source' => 'online']))
            ->assertStatus(422);
    }

    // =========================================================
    // 🧮 expected_amount formula (ticket 05 หัวข้อ 9)
    // =========================================================

    public function test_expected_amount_switches_to_outstanding_after_partial_payment(): void
    {
        $roomType = $this->roomTypeSetup();
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['payment_type' => 'deposit']));
        $booking = Booking::findOrFail($created->json('booking_id'));

        // งวดแรก (ยังไม่มีเงินเข้า) → expected = ยอดมัดจำ
        $this->assertEquals(750, $booking->expected_amount);

        // เก็บบางส่วนก่อนแล้ว (เช่น resubmit หลัง reject ระหว่างมีเงินค้าง) → expected = outstanding
        Payment::create(['booking_id' => $booking->id, 'amount' => 300, 'status' => 'completed']);
        $this->assertEquals(1200, $booking->fresh()->expected_amount);
    }
}
