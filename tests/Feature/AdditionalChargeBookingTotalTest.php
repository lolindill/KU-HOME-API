<?php

namespace Tests\Feature;

use App\Models\AdditionalCharge;
use App\Models\Booking;
use App\Models\BookingConfirmation;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\ReportExcel\Data\AdditionalChargesReportData;
use App\Services\ReportExcel\Data\DailyFinancialReportData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 💸 (15/10/26) Additional charges บวกยอดจริง — srs-v2-gap-closure ticket 13 (REQ-042/044)
 *
 * ตาม design ที่ล็อกใน ticket 12 — ห้ามเปลี่ยน decision:
 *   - total_amount คงค่าห้องล้วน (invariant Σ booking_rooms.amount == total_amount)
 *   - charges_total = Σ additional_charges → outstanding = total + charges − paid
 *   - is_paid = paid ≥ total + charges — recompute ทั้งตอนสร้าง/แก้/ลบ charge
 *   - ผูกห้อง booking_room_id nullable — ต้องเป็นลูกของ booking_id (422 ถ้าไม่ใช่)
 *   - charge_type เพิ่ม addon (REQ-044 — กลไกเดียวกับ charges ไม่แตะตาราง addons)
 *   - เก็บเงินหลัง charge ผ่าน recordPayment เท่านั้น · บันทึก charge ได้ทุกสถานะ
 *   - regression: ไม่มี charge → ยอดเดิมทุกอย่าง · deposit 50% ไม่ขยับ
 */
class AdditionalChargeBookingTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // =========================================================
    // 🔧 helpers (แบบเดียวกับ BookingSurchargeAfterPaymentTest)
    // =========================================================

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
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
                    'guests' => [['name' => 'Somchai Jaidee']],
                ],
            ],
        ], $overrides);
    }

    private function createBooking(int $price, string $paymentType = 'full', int $rooms = 1): Booking
    {
        $roomType = $this->roomTypeSetup($price, $rooms);

        $created = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, ['payment_type' => $paymentType]));
        $created->assertStatus(201);

        return Booking::findOrFail($created->json('booking_id'));
    }

    /**
     * สร้าง booking + ส่งสลิป + verify → paid/confirmed (มี payments row ใน ledger แล้ว)
     */
    private function paidBooking(int $price, string $paymentType = 'full'): Booking
    {
        $booking = $this->createBooking($price, $paymentType);
        $admin = $this->admin();

        $slipAmount = $paymentType === 'deposit' ? (int) ceil($price * 0.5) : $price;

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

    private function storeCharge(Booking $booking, array $overrides = [], ?User $as = null)
    {
        $payload = array_merge([
            'booking_id' => $booking->id,
            'transaction_date' => now()->toDateString(),
            'item_name' => 'ค่าเสียหาย แก้วแตก',
            'qty' => 1,
            'price' => 250,
            'charge_type' => 'damage',
        ], $overrides);

        $actor = $as ?? $this->admin();

        return $this->actingAs($actor, 'sanctum')->postJson('/api/v1/additional-charges', $payload);
    }

    // =========================================================
    // ✅ ยอดบวกจริง — charges_total / outstanding / is_paid
    // =========================================================

    public function test_per_room_charge_adds_to_outstanding_and_resets_is_paid(): void
    {
        $booking = $this->paidBooking(1500, 'full'); // จ่ายเต็ม → is_paid true
        $this->assertTrue($booking->fresh()->is_paid);

        $bookingRoom = $booking->bookingRooms()->first();

        $res = $this->storeCharge($booking, ['booking_room_id' => $bookingRoom->id]);
        $res->assertStatus(201)
            ->assertJsonPath('additional_charge.booking_room_id', $bookingRoom->id)
            // snapshot เงินใน response — หน้างานอัปเดตรอบเดียว
            ->assertJsonPath('total_amount', 1500)
            ->assertJsonPath('charges_total', 250)
            ->assertJsonPath('outstanding_amount', 250)
            ->assertJsonPath('booking_is_paid', false);

        // total_amount คงค่าห้องล้วน — invariant ไม่พัง
        $fresh = $booking->fresh();
        $this->assertEquals(1500, $fresh->total_amount);
        $this->assertEquals(1500, $fresh->bookingRooms()->sum('amount'));
        $this->assertEquals(250, $fresh->charges_total);
        $this->assertFalse($fresh->is_paid);
        // สถานะไม่เดินตาม charge (state machine = เหตุการณ์เงินเท่านั้น)
        $this->assertEquals('confirmed', $fresh->status);
    }

    public function test_booking_level_charge_without_room_binds_whole_booking(): void
    {
        $booking = $this->paidBooking(1500, 'deposit'); // จ่ายมัดจำ 750

        $this->storeCharge($booking, ['item_name' => 'ค่าบริการ ซักรีด', 'charge_type' => 'addon'])
            ->assertStatus(201)
            ->assertJsonPath('additional_charge.booking_room_id', null)
            ->assertJsonPath('charges_total', 250)
            ->assertJsonPath('outstanding_amount', 1000); // (1500 + 250) − 750
    }

    public function test_booking_room_must_belong_to_booking(): void
    {
        $bookingA = $this->createBooking(1500);
        $bookingB = $this->createBooking(2000, 'full', 2); // booking อื่น — มี 2 ห้อง
        $foreignRoom = $bookingB->bookingRooms()->first();

        // store — booking_room ของ booking อื่น → 422
        $this->storeCharge($bookingA, ['booking_room_id' => $foreignRoom->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('booking_room_id');

        // update — ย้าย charge ไปแปะห้องของ booking อื่น → 422 เช่นกัน
        $charge = AdditionalCharge::create([
            'booking_id' => $bookingA->id,
            'transaction_date' => now()->toDateString(),
            'item_name' => 'ค่าเสียหาย หมอน',
            'price' => 100,
            'charge_type' => 'damage',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/additional-charges/{$charge->id}", ['booking_room_id' => $foreignRoom->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('booking_room_id');
    }

    public function test_update_and_delete_charge_recompute_is_paid(): void
    {
        $booking = $this->paidBooking(1500, 'full'); // paid 1500 · is_paid true
        $bookingRoom = $booking->bookingRooms()->first();

        $created = $this->storeCharge($booking, ['booking_room_id' => $bookingRoom->id])
            ->assertStatus(201);
        $chargeId = $created->json('additional_charge.id');
        $this->assertFalse($booking->fresh()->is_paid);

        // แก้ราคา → snapshot อัปเดตตาม
        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/v1/additional-charges/{$chargeId}", ['price' => 300])
            ->assertStatus(200)
            ->assertJsonPath('charges_total', 300)
            ->assertJsonPath('outstanding_amount', 300)
            ->assertJsonPath('booking_is_paid', false);

        // ลบ charge → paid ครบยอดใหม่ → is_paid คืน true (recompute ที่ล็อกใน ticket 12/13)
        // โดยสถานะยังคงเดิม — ไม่มี transition เกิดจาก charge
        $statusBefore = $booking->fresh()->status;
        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/additional-charges/{$chargeId}")
            ->assertStatus(200)
            ->assertJsonPath('charges_total', 0)
            ->assertJsonPath('outstanding_amount', 0)
            ->assertJsonPath('booking_is_paid', true);

        $fresh = $booking->fresh();
        $this->assertTrue($fresh->is_paid);
        $this->assertEquals($statusBefore, $fresh->status);
    }

    public function test_deposit_amount_unchanged_by_charge(): void
    {
        $booking = $this->paidBooking(1500, 'deposit'); // มัดจำ 750 (ปัดหลักสิบแล้ว)
        $this->assertEquals(750, $booking->fresh()->deposit_amount);

        $this->storeCharge($booking)->assertStatus(201);

        // deposit 50% ผูกค่าห้อง — charge ไม่ขยับมัดจำ (มติ ticket 12 ข้อ 1)
        $fresh = $booking->fresh();
        $this->assertEquals(750, $fresh->deposit_amount);
        $this->assertEquals(1500, $fresh->total_amount);
    }

    // =========================================================
    // 💵 การชำระ — recordPayment เท่านั้น (มติ ticket 12 ข้อ 4)
    // =========================================================

    public function test_staff_settles_charge_via_record_payment(): void
    {
        $booking = $this->paidBooking(1500, 'deposit'); // paid 750 · outstanding 750
        $this->storeCharge($booking, ['booking_room_id' => $booking->bookingRooms()->first()->id])
            ->assertStatus(201);

        // staff เก็บส่วนต่าง (ค่าห้องค้าง 750 + charge 250 = 1000) ที่ front desk
        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/payment", ['amount' => 1000])
            ->assertStatus(201)
            ->assertJsonPath('booking_is_paid', true)
            ->assertJsonPath('outstanding_amount', 0);

        $fresh = $booking->fresh();
        $this->assertTrue($fresh->is_paid);
        $this->assertEquals(1750, $fresh->paid_amount);
    }

    public function test_verify_slip_amount_includes_charges(): void
    {
        // charge เกิดตอน draft ได้ (ทุกสถานะ) → ยอดสลิปควรรวม charge
        $bookingWithCharge = $this->createBooking(1500, 'full');
        $this->storeCharge($bookingWithCharge, ['booking_room_id' => $bookingWithCharge->bookingRooms()->first()->id])
            ->assertStatus(201);

        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingWithCharge->id}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg'),
                'amount' => 1750, // 1500 ห้อง + 250 charge
            ])->assertStatus(201);

        $confirmation = BookingConfirmation::where('booking_id', $bookingWithCharge->id)->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify")
            ->assertStatus(200)
            ->assertJsonPath('booking_is_paid', true);

        $this->assertTrue($bookingWithCharge->fresh()->is_paid);

        // สลิปยอดเดิม (ไม่รวม charge) → is_paid ยัง false · outstanding คือ charge
        $bookingShort = $this->createBooking(1500, 'full');
        $this->storeCharge($bookingShort)->assertStatus(201);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingShort->id}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg'),
                'amount' => 1500,
            ])->assertStatus(201);

        $confirmation2 = BookingConfirmation::where('booking_id', $bookingShort->id)->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/booking-confirmations/{$confirmation2->id}/verify")
            ->assertStatus(200)
            ->assertJsonPath('booking_is_paid', false);

        $fresh = $bookingShort->fresh();
        $this->assertFalse($fresh->is_paid);
        $this->assertEquals(250, $fresh->outstanding_amount);
    }

    // =========================================================
    // 🆕 GET /bookings/{id}/additional-charges — หน้า front desk
    // =========================================================

    public function test_list_charges_per_booking_with_money_envelope(): void
    {
        $booking = $this->paidBooking(1500, 'full');
        $bookingRoom = $booking->bookingRooms()->first();

        $this->storeCharge($booking, ['booking_room_id' => $bookingRoom->id, 'charge_type' => 'damage'])
            ->assertStatus(201);
        $this->storeCharge($booking, ['item_name' => 'ค่าบริการ ซักรีด', 'price' => 100, 'charge_type' => 'addon'])
            ->assertStatus(201);

        // staff อ่านได้ (front desk)
        $res = $this->actingAs($this->staff(), 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}/additional-charges")
            ->assertStatus(200)
            ->assertJsonPath('charges_total', 350)
            ->assertJsonPath('outstanding_amount', 350);

        $this->assertCount(2, $res->json('additional_charges'));

        // housekeeping/user อ่านไม่ได้ (role:admin,staff)
        $this->actingAs(User::factory()->create(['role' => 'housekeeping']), 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}/additional-charges")
            ->assertStatus(403);

        // 404 — booking ไม่ exist
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/bookings/'.Str::uuid().'/additional-charges')
            ->assertStatus(404);
    }

    // =========================================================
    // 🛡️ สิทธิ์ — admin+staff เขียน · role อื่น 403
    // =========================================================

    public function test_charge_write_requires_admin_or_staff(): void
    {
        $booking = $this->createBooking(1500);

        $this->storeCharge($booking, [], User::factory()->create(['role' => 'housekeeping']))
            ->assertStatus(403);
        $this->storeCharge($booking, [], User::factory()->create(['role' => 'user']))
            ->assertStatus(403);

        // staff เขียนได้
        $this->storeCharge($booking, [], $this->staff())
            ->assertStatus(201)
            ->assertJsonPath('charges_total', 250);
    }

    // =========================================================
    // 🎟️ charge_type addon (REQ-044) + รายงานยังตรง
    // =========================================================

    public function test_addon_charge_separated_in_report_filter(): void
    {
        $booking = $this->createBooking(1500);
        $today = now()->toDateString();

        $this->storeCharge($booking, ['item_name' => 'ค่าเสียหาย ผ้าปูที่นอน', 'charge_type' => 'damage'])->assertStatus(201);
        $this->storeCharge($booking, ['item_name' => 'บริการเสริม ตกแต่งห้อง', 'price' => 400, 'charge_type' => 'addon'])->assertStatus(201);

        $report = app(AdditionalChargesReportData::class);
        $range = ['date_from' => $today, 'date_to' => $today];

        $all = iterator_to_array($report->rows(array_merge($range, ['item_type' => 'ทุกประเภท'])));
        $this->assertCount(2, $all);

        $addon = iterator_to_array($report->rows(array_merge($range, ['item_type' => 'บริการเสริม'])));
        $this->assertCount(1, $addon);
        $this->assertEquals('บริการเสริม ตกแต่งห้อง', $addon[0]['item_name']);

        $damage = iterator_to_array($report->rows(array_merge($range, ['item_type' => 'ค่าเสียหาย'])));
        $this->assertCount(1, $damage);
        $this->assertEquals('ค่าเสียหาย ผ้าปูที่นอน', $damage[0]['item_name']);
    }

    public function test_charge_settlement_appears_in_daily_financial_report(): void
    {
        $booking = $this->paidBooking(1500, 'full'); // is_paid true
        $this->storeCharge($booking)->assertStatus(201); // +250 ค้าง

        $this->actingAs($this->staff(), 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/payment", ['amount' => 250, 'reference_number' => 'ใบเสร็จหน้างาน 001'])
            ->assertStatus(201);

        // เงินเข้าจริง = แถวใน daily-financial (เงินสด — reference ไม่ใช่ confirmation UUID)
        $rows = iterator_to_array(app(DailyFinancialReportData::class)->rows(['date' => now()->toDateString()]));
        $settlement = collect($rows)->first(fn ($row) => $row['received_amount'] === 250);

        $this->assertNotNull($settlement);
        $this->assertEquals(DailyFinancialReportData::CHANNEL_CASH, $settlement['payment_channel']);
        $this->assertEquals($booking->confirmation, $settlement['booking_no']);
    }

    // =========================================================
    // ♻️ regression — ไม่มี charge → ยอดเดิมทุกอย่าง
    // =========================================================

    public function test_booking_without_charges_keeps_original_amounts(): void
    {
        $booking = $this->paidBooking(1500, 'deposit'); // paid 750

        $fresh = $booking->fresh();
        $this->assertEquals(0, $fresh->charges_total);
        $this->assertEquals(750, $fresh->outstanding_amount); // total − paid เดิมล้วน
        $this->assertFalse($fresh->is_paid);
        $this->assertEquals(750, $fresh->expected_amount); // deposit flow ไม่แตะ
    }
}
