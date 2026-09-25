<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingConfirmation;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Discount\DiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 💳 (25/09/26) Booking Surcharge หลังจ่าย — wayfinder/booking-payment-types ticket 10
 *
 * แก้ booking_room หลังมีเงินเข้า ledger แล้ว (booking paid/confirmed) ผ่าน
 * PUT /bookings/{bookingId}/rooms/{bookingRoomId} เดิม — admin เท่านั้น:
 *   - surcharge ราคาเพิ่ม → outstanding เก็บต่อผ่าน recordPayment (กลไกเดิม)
 *   - downgrade ต่ำกว่ายอดที่จ่าย = 422 (ticket 08 — ไม่มีการคืนเงิน) · ลดได้ถ้ายัง ≥ paid
 *   - availability re-check + holdingSlot · invariant Σ booking_rooms.amount == total_amount
 *   - is_paid=true เดิม → reset false เมื่อ surcharge ทำให้ยอดค้างเกิด
 *   - draft flow regression 0% (เจ้าของแก้เองได้เหมือนเดิม)
 */
class BookingSurchargeAfterPaymentTest extends TestCase
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

    private static int $roomSeq = 0;

    private function roomTypeSetup(int $price = 1500, int $rooms = 1, string $name = 'Standard'): RoomType
    {
        $roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => $name,
            'name_th' => $name,
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $roomType->id,
            'code' => null,
            'name_en' => $name.' Daily',
            'default_price' => $price,
            'is_active' => true,
        ]);
        for ($i = 1; $i <= $rooms; $i++) {
            Room::create([
                'id' => Str::uuid(),
                'room_type_id' => $roomType->id,
                'room_number' => $name.'-'.str_pad((string) ++self::$roomSeq, 3, '0', STR_PAD_LEFT),
                'status' => 'available',
            ]);
        }

        return $roomType;
    }

    private function bookingPayload(string $roomTypeId, array $overrides = []): array
    {
        return array_merge([
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomTypeId,
                    'check_in' => now()->addDays(5)->toDateString(),
                    'check_out' => now()->addDays(6)->toDateString(),
                ],
            ],
        ], $overrides);
    }

    /**
     * สร้าง booking + ส่งสลิป + verify → paid/confirmed (มี payments row ใน ledger แล้ว)
     */
    private function paidBooking(int $price, string $paymentType = 'deposit', ?int $depositAmount = null): Booking
    {
        $roomType = $this->roomTypeSetup($price);
        $admin = $this->admin();

        $payload = ['payment_type' => $paymentType];
        if ($depositAmount !== null) {
            $payload['deposit_amount'] = $depositAmount;
        }

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, $payload));
        $created->assertStatus(201);

        $booking = Booking::findOrFail($created->json('booking_id'));

        $slipAmount = $paymentType === 'deposit'
            ? ($depositAmount ?? (int) ceil($price * 0.5))
            : $price;

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'amount' => $slipAmount,
            ])->assertStatus(201);

        $confirmation = BookingConfirmation::where('booking_id', $booking->id)->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify")
            ->assertStatus(200);

        return $booking->fresh();
    }

    private function putRoom(Booking $booking, array $payload, ?User $as = null)
    {
        $br = $booking->bookingRooms()->first();

        return ($as ? $this->actingAs($as, 'sanctum') : $this->actingAs($this->admin(), 'sanctum'))
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", $payload);
    }

    // =========================================================
    // ✅ surcharge — ราคาเพิ่มหลังจ่าย
    // =========================================================

    public function test_surcharge_after_deposit_verify_raises_outstanding(): void
    {
        $typeB = $this->roomTypeSetup(2500, 1, 'Deluxe');
        $booking = $this->paidBooking(1500, 'deposit'); // จ่ายมัดจำ 750 · total 1500

        $this->assertEquals(1500, $booking->total_amount);
        $this->assertEquals(750, $booking->paid_amount);
        $this->assertEquals(750, $booking->outstanding_amount);

        // เปลี่ยน room type เป็น Deluxe (2500) หลังจ่ายมัดจำแล้ว — admin
        $res = $this->putRoom($booking, ['room_type_id' => $typeB->id]);
        $res->assertStatus(200)
            ->assertJsonPath('total_amount', 2500)
            ->assertJsonPath('previous_total_amount', 1500)
            ->assertJsonPath('surcharge_amount', 1000)
            ->assertJsonPath('paid_amount', 750)
            ->assertJsonPath('outstanding_amount', 1750);

        // invariant Σ booking_rooms.amount == total_amount
        $fresh = $booking->fresh();
        $this->assertEquals(2500, $fresh->bookingRooms()->sum('amount'));
        $this->assertEquals(2500, $fresh->total_amount);

        // เก็บส่วนต่างต่อผ่าน recordPayment (กลไกเดิม — ไม่มี endpoint เงินใหม่)
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/payment", ['amount' => 1750])
            ->assertStatus(201)
            ->assertJsonPath('booking_is_paid', true)
            ->assertJsonPath('outstanding_amount', 0);
    }

    public function test_surcharge_on_full_paid_resets_is_paid(): void
    {
        $typeB = $this->roomTypeSetup(3000, 1, 'Suite');
        $booking = $this->paidBooking(1500, 'full'); // จ่ายเต็ม 1500 → is_paid true

        $this->assertTrue($booking->fresh()->is_paid);

        $this->putRoom($booking, ['room_type_id' => $typeB->id])
            ->assertStatus(200)
            ->assertJsonPath('total_amount', 3000)
            ->assertJsonPath('surcharge_amount', 1500)
            ->assertJsonPath('outstanding_amount', 1500);

        // is_paid = SUM(payments) ≥ total — surcharge ทำให้ยอดค้างเกิด → reset false
        $this->assertFalse($booking->fresh()->is_paid);

        // เก็บส่วนต่างครบ → is_paid กลับมา true
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/payment", ['amount' => 1500])
            ->assertStatus(201)
            ->assertJsonPath('booking_is_paid', true);
    }

    // =========================================================
    // ⬇️ downgrade — ลดได้เฉพาะยอดรวมใหม่ ≥ paid (ticket 08: ไม่มีคืนเงิน)
    // =========================================================

    public function test_downgrade_below_paid_is_blocked(): void
    {
        $typeCheap = $this->roomTypeSetup(500, 1, 'Budget');
        $booking = $this->paidBooking(1500, 'deposit'); // จ่ายมัดจำ 750

        $this->putRoom($booking, ['room_type_id' => $typeCheap->id])
            ->assertStatus(422);

        // rollback — ยอดเดิมคงอยู่ ไม่มีอะไรแตะ
        $fresh = $booking->fresh();
        $this->assertEquals(1500, $fresh->total_amount);
        $this->assertEquals(RoomType::where('name_en', 'Standard')->firstOrFail()->id, $fresh->bookingRooms()->first()->room_type_id);
        $this->assertEquals(1500, $fresh->bookingRooms()->sum('amount'));
    }

    public function test_downgrade_above_paid_shrinks_outstanding(): void
    {
        // 2 คืน × 1500 = 3000 · มัดจำ default ceil(3000×50%) = 1500 (ปัดสิบต่อ)
        $booking = $this->paidBooking(1500, 'deposit', 1500);
        $booking->bookingRooms()->first()->update([
            'check_out' => now()->addDays(7)->toDateString(),
        ]);
        app(DiscountService::class)->reprice($booking->fresh());
        $this->assertEquals(3000, $booking->fresh()->total_amount);

        // จ่ายแล้ว 1500 — เปลี่ยนเป็น 2 คืน × 1000 = 2000 ≥ paid → อนุญาต
        $typeCheap = $this->roomTypeSetup(1000, 1, 'Cheap');
        $res = $this->putRoom($booking, ['room_type_id' => $typeCheap->id]);
        $res->assertStatus(200)
            ->assertJsonPath('total_amount', 2000)
            ->assertJsonPath('previous_total_amount', 3000)
            ->assertJsonPath('surcharge_amount', -1000)
            ->assertJsonPath('paid_amount', 1500)
            ->assertJsonPath('outstanding_amount', 500);

        $fresh = $booking->fresh();
        $this->assertEquals(2000, $fresh->bookingRooms()->sum('amount'));
    }

    // =========================================================
    // 🔒 สิทธิ์ + สถานะ + availability
    // =========================================================

    public function test_non_admin_owner_cannot_edit_after_payment(): void
    {
        $typeB = $this->roomTypeSetup(2500, 1, 'Deluxe');

        // สร้างผ่าน owner user จริง — paidBooking ใช้ admin สร้าง แต่สิทธิ์ตรวจที่ role
        $booking = $this->paidBooking(1500, 'deposit');
        $owner = User::factory()->create(['role' => 'user']);

        $this->putRoom($booking, ['room_type_id' => $typeB->id], $owner)
            ->assertStatus(403);

        $this->assertEquals(1500, $booking->fresh()->total_amount);
    }

    public function test_pending_booking_still_blocked(): void
    {
        $typeB = $this->roomTypeSetup(2500, 1, 'Deluxe');
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($this->roomTypeSetup(1500)->id));
        $booking = Booking::findOrFail($created->json('booking_id'));

        // ส่งสลิป → pending (ยังไม่ verify — ยังไม่มีเงินเข้า)
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'amount' => 1500,
            ])->assertStatus(201);
        $this->assertEquals('pending', $booking->fresh()->status);

        $this->putRoom($booking, ['room_type_id' => $typeB->id])
            ->assertStatus(422);
    }

    public function test_availability_full_is_422(): void
    {
        // Deluxe มี 1 ห้อง — ถูก holding โดย booking อื่นแล้ว
        $typeDeluxe = $this->roomTypeSetup(2500, 1, 'Deluxe');
        $other = $this->paidBooking(1500, 'full');
        $other->bookingRooms()->first()->update(['room_type_id' => $typeDeluxe->id]);

        $booking = $this->paidBooking(1500, 'deposit');

        $this->putRoom($booking, ['room_type_id' => $typeDeluxe->id])
            ->assertStatus(422);

        $this->assertEquals(1500, $booking->fresh()->total_amount);
    }

    public function test_assigned_room_blocks_shape_change(): void
    {
        $typeB = $this->roomTypeSetup(2500, 1, 'Deluxe');
        $booking = $this->paidBooking(1500, 'full'); // จ่ายเต็ม → confirmed

        // จัดเลขห้องจริง (auto-assign)
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/assign-rooms")
            ->assertStatus(200);
        $this->assertNotNull($booking->bookingRooms()->first()->fresh()->room_id);

        // เปลี่ยน room type หลังถูกจัดห้อง = 422
        $br = $booking->bookingRooms()->first();
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", ['room_type_id' => $typeB->id])
            ->assertStatus(422);
    }

    public function test_checked_in_room_is_blocked(): void
    {
        $typeB = $this->roomTypeSetup(2500, 1, 'Deluxe');
        $booking = $this->paidBooking(1500, 'full');

        // setup เท่านั้น — จำลอง BR เข้าพักแล้ว (ผ่าน DB update ไม่ผ่าน state machine)
        $br = $booking->bookingRooms()->first();
        DB::table('booking_rooms')->where('id', $br->id)->update(['status' => 'checked_in']);

        $this->putRoom($booking, ['room_type_id' => $typeB->id])
            ->assertStatus(422);
    }

    // =========================================================
    // ♻️ draft flow regression 0%
    // =========================================================

    public function test_draft_owner_edit_unchanged_regression(): void
    {
        $typeB = $this->roomTypeSetup(2500, 1, 'Deluxe');
        $roomType = $this->roomTypeSetup(1500);
        $user = User::factory()->create(['role' => 'user']);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id));
        $created->assertStatus(201);
        $booking = Booking::findOrFail($created->json('booking_id'));

        // เจ้าของ (ไม่ใช่ admin) แก้ draft ได้เหมือนเดิม
        $br = $booking->bookingRooms()->first();
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", ['room_type_id' => $typeB->id])
            ->assertStatus(200)
            ->assertJsonPath('total_amount', 2500)
            ->assertJsonPath('previous_total_amount', 1500)
            ->assertJsonPath('surcharge_amount', 1000)
            ->assertJsonPath('outstanding_amount', 2500);

        $this->assertEquals('draft', $booking->fresh()->status);
    }
}
