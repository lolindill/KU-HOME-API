<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingConfirmation;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Discount\DiscountService;
use Database\Seeders\GlobalRateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🏨 (10/11/26) โหมด 3: allow structured-addon edits on checked_in rooms
 *
 * PUT /api/v1/bookings/{bookingId}/rooms/{bookingRoomId}
 * สำหรับห้องที่เข้าพักแล้ว (checked_in):
 * - container confirmed, admin หรือ staff เท่านั้น
 * - allow-list รับเฉพาะ addons และ extra_beds (field อื่นโยน 422)
 * - สรุปยอดคงค้างเข้า outstanding_amount เพื่อเก็บต่อผ่าน front-desk ก่อน check-out
 */
class BookingCheckedInAddonTest extends TestCase
{
    use RefreshDatabase;

    private static int $roomSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(GlobalRateSeeder::class);
    }

    // =========================================================
    // 🔧 helpers
    // =========================================================

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => 'staff']);
    }

    private function roomTypeSetup(
        int $price = 1500,
        int $rooms = 1,
        string $name = 'Standard',
        bool $extraBedEnabled = false,
        int $maxExtraBeds = 0
    ): RoomType {
        $roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => $name,
            'name_th' => $name,
            'max_guests' => 2,
            'extra_bed_enabled' => $extraBedEnabled,
            'max_extra_beds' => $maxExtraBeds,
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

    private function bookingPayload(string $roomTypeId, array $overrides = [], int $nights = 1): array
    {
        return array_merge([
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomTypeId,
                    'check_in' => now()->addDays(5)->toDateString(),
                    'check_out' => now()->addDays(5 + $nights)->toDateString(),
                    'guests' => [['name' => 'Somchai Jaidee']],
                ],
            ],
        ], $overrides);
    }

    /**
     * สร้าง booking + ส่งสลิป + verify → paid/confirmed แล้วตั้ง BR เป็น checked_in และ container confirmed
     */
    private function checkedInBooking(
        int $price = 1500,
        string $paymentType = 'full',
        ?int $depositAmount = null,
        int $nights = 1,
        ?RoomType $roomType = null,
        ?User $owner = null
    ): array {
        $roomType ??= $this->roomTypeSetup($price);
        $creator = $owner ?? $this->admin();

        $payload = ['payment_type' => $paymentType];
        if ($depositAmount !== null) {
            $payload['deposit_amount'] = $depositAmount;
        }

        $created = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/bookings', $this->bookingPayload($roomType->id, $payload, $nights));
        $created->assertStatus(201);

        $booking = Booking::findOrFail($created->json('booking_id'));
        $totalAmount = $price * $nights;

        $slipAmount = $paymentType === 'deposit'
            ? ($depositAmount ?? (int) ceil($totalAmount * 0.5))
            : $totalAmount;

        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
                'amount' => $slipAmount,
            ])->assertStatus(201);

        $confirmation = BookingConfirmation::where('booking_id', $booking->id)->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify")
            ->assertStatus(200);

        $br = $booking->bookingRooms()->first();

        // 🏨 ตั้งสถานะเป็น checked_in และ container confirmed
        DB::table('booking_rooms')->where('id', $br->id)->update(['status' => 'checked_in']);
        DB::table('bookings')->where('id', $booking->id)->update(['status' => 'confirmed']);

        return [$booking->fresh(), $br->fresh(), $roomType];
    }

    // =========================================================
    // 🧪 TESTS
    // =========================================================

    public function test_staff_adds_breakfast_sets(): void
    {
        $staff = $this->staff();
        [$booking, $br] = $this->checkedInBooking(1500, 'full', null, 2);

        $this->assertEquals(3000, $booking->total_amount);
        $this->assertEquals(3000, $booking->paid_amount);
        $this->assertEquals(0, $booking->outstanding_amount);

        // Staff เพิ่ม breakfast_set_200 1 ชุด สำหรับพัก 2 คืน (200 × 2 = 400 บาท)
        $res = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'breakfast_sets' => [
                        'set_200' => 1,
                    ],
                ],
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('previous_total_amount', 3000)
            ->assertJsonPath('total_amount', 3400)
            ->assertJsonPath('surcharge_amount', 400)
            ->assertJsonPath('paid_amount', 3000)
            ->assertJsonPath('outstanding_amount', 400);

        $this->assertStringContainsString('เพิ่มหรือแก้ไข addon สำหรับห้องพักที่เข้าพักแล้วเรียบร้อยแล้วค่ะนายท่าน', $res->json('message'));

        $fresh = $booking->fresh();
        $this->assertEquals(3400, $fresh->total_amount);
        $this->assertEquals(400, $fresh->outstanding_amount);
        $this->assertFalse($fresh->is_paid);

        $this->assertEquals(1, $br->fresh()->addon->breakfast_set_200);
    }

    public function test_admin_sends_extra_beds_by_night_for_tonight(): void
    {
        $admin = $this->admin();
        $roomType = $this->roomTypeSetup(1500, 1, 'Standard', true, 1);
        [$booking, $br] = $this->checkedInBooking(1500, 'full', null, 2, $roomType);

        $this->assertEquals(3000, $booking->total_amount);
        $tonight = $br->check_in->toDateString();

        // Admin ส่งเตียงเสริมเฉพาะคืนนี้ (1 คืน = 500 บาท)
        $res = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'extra_beds_by_night' => [
                        $tonight => 1,
                    ],
                ],
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('previous_total_amount', 3000)
            ->assertJsonPath('total_amount', 3500)
            ->assertJsonPath('surcharge_amount', 500)
            ->assertJsonPath('outstanding_amount', 500);

        $fresh = $booking->fresh();
        $this->assertEquals(3500, $fresh->total_amount);
        $this->assertEquals(500, $fresh->outstanding_amount);
        $this->assertFalse($fresh->is_paid);
    }

    public function test_owner_gets_403_and_unauthenticated_gets_401(): void
    {
        [$booking, $br] = $this->checkedInBooking(1500, 'full', null, 1);
        $owner = User::factory()->create(['role' => 'user']);
        $booking->update(['user_id' => $owner->id]);

        // 1. Unauthenticated โดน 401
        $this->actingAsGuest('sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'breakfast_sets' => [
                        'set_200' => 1,
                    ],
                ],
            ])->assertStatus(401);

        // 2. Regular user owner โดน 403
        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'breakfast_sets' => [
                        'set_200' => 1,
                    ],
                ],
            ])
            ->assertStatus(403);
    }

    public function test_sending_shape_fields_mixed_with_addons_is_422(): void
    {
        $staff = $this->staff();
        [$booking, $br] = $this->checkedInBooking(1500, 'full', null, 1);
        $otherType = $this->roomTypeSetup(2000, 1, 'Deluxe');

        // ส่ง room_type_id ปนกับ addons => 422
        $res1 = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'room_type_id' => $otherType->id,
                'addons' => ['breakfast_sets' => ['set_200' => 1]],
            ]);
        $res1->assertStatus(422)
            ->assertJsonFragment([
                'status' => 'error',
                'message' => 'ห้องเข้าพักแล้วรับเฉพาะการเพิ่ม/แก้ addon เท่านั้นค่ะ 🏨',
            ]);

        // ส่ง check_in ปนกับ addons => 422
        $res2 = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'check_in' => now()->addDays(6)->toDateString(),
                'addons' => ['breakfast_sets' => ['set_200' => 1]],
            ]);
        $res2->assertStatus(422)
            ->assertJsonFragment([
                'status' => 'error',
                'message' => 'ห้องเข้าพักแล้วรับเฉพาะการเพิ่ม/แก้ addon เท่านั้นค่ะ 🏨',
            ]);

        // ส่ง guests ปนกับ addons => 422
        $res3 = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'guests' => [['name' => 'Alice']],
                'addons' => ['breakfast_sets' => ['set_200' => 1]],
            ]);
        $res3->assertStatus(422)
            ->assertJsonFragment([
                'status' => 'error',
                'message' => 'ห้องเข้าพักแล้วรับเฉพาะการเพิ่ม/แก้ addon เท่านั้นค่ะ 🏨',
            ]);
    }

    public function test_checked_out_and_no_show_are_still_422(): void
    {
        $admin = $this->admin();
        [$booking, $br] = $this->checkedInBooking(1500, 'full', null, 1);

        // จำลองห้องเป็น checked_out
        DB::table('booking_rooms')->where('id', $br->id)->update(['status' => 'checked_out']);

        $res1 = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => ['breakfast_sets' => ['set_200' => 1]],
            ]);
        $res1->assertStatus(422)
            ->assertJsonFragment([
                'status' => 'error',
                'message' => 'ไม่สามารถแก้ไขห้องได้ เนื่องจากห้องเข้าพักหรือปิดสถานะไปแล้วค่ะ',
            ]);

        // จำลองห้องเป็น no_show
        DB::table('booking_rooms')->where('id', $br->id)->update(['status' => 'no_show']);

        $res2 = $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => ['breakfast_sets' => ['set_200' => 1]],
            ]);
        $res2->assertStatus(422)
            ->assertJsonFragment([
                'status' => 'error',
                'message' => 'ไม่สามารถแก้ไขห้องได้ เนื่องจากห้องเข้าพักหรือปิดสถานะไปแล้วค่ะ',
            ]);
    }

    public function test_fully_paid_bill_removing_breakfast_is_blocked_by_downgrade_guard(): void
    {
        $staff = $this->staff();
        [$booking, $br] = $this->checkedInBooking(1500, 'full', null, 1);

        // ให้มี breakfast_set_200 อยู่แล้วตั้งแต่แรก และจ่ายเต็มครบถ้วน
        $br->addon->update(['breakfast_set_200' => 1]);
        app(DiscountService::class)->reprice($booking);
        $totalWithBreakfast = $booking->fresh()->total_amount; // 1700
        $this->assertEquals(1700, $totalWithBreakfast);

        // บันทึก payment ให้ครบ 1700
        DB::table('payments')->where('booking_id', $booking->id)->update(['amount' => 1700]);
        $booking->update(['is_paid' => true]);

        // พยายามถอด breakfast_set_200 ออก ทำให้ยอดรวมลดเป็น 1500 (< 1700 ที่จ่ายแล้ว)
        $res = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'breakfast_sets' => [
                        'set_200' => 0,
                    ],
                ],
            ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('ไม่สามารถลดยอดรวมต่ำกว่าเงินที่ชำระเข้ามาแล้วได้', $res->json('message'));
        $this->assertStringContainsString('ระบบไม่มีการคืนเงินค่ะ', $res->json('message'));
    }

    public function test_deposit_bill_reducing_addon_succeeds_and_outstanding_shrinks(): void
    {
        $staff = $this->staff();
        // จ่ายมัดจำ 1000 บาท
        [$booking, $br] = $this->checkedInBooking(1500, 'deposit', 1000, 1);

        // ตั้งค่า addon เดิมให้มี breakfast_set_200 2 ชุด (400 บาท)
        $br->addon->update(['breakfast_set_200' => 2]);
        app(DiscountService::class)->reprice($booking);
        $this->assertEquals(1900, $booking->fresh()->total_amount);
        $this->assertEquals(1000, $booking->fresh()->paid_amount);
        $this->assertEquals(900, $booking->fresh()->outstanding_amount);

        // ลดจำนวน breakfast_set_200 จาก 2 ชุดเหลือ 1 ชุด (ยอดรวมใหม่ 1700 > 1000 ที่ชำระแล้ว)
        $res = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'breakfast_sets' => [
                        'set_200' => 1,
                    ],
                ],
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('previous_total_amount', 1900)
            ->assertJsonPath('total_amount', 1700)
            ->assertJsonPath('surcharge_amount', -200)
            ->assertJsonPath('paid_amount', 1000)
            ->assertJsonPath('outstanding_amount', 700);

        $this->assertEquals(700, $booking->fresh()->outstanding_amount);
    }

    public function test_integration_addon_surcharge_checkout_rejected_then_paid_and_checkout_succeeds(): void
    {
        $staff = $this->staff();
        $roomType = $this->roomTypeSetup(1500, 1, 'Standard');
        $room = Room::where('room_type_id', $roomType->id)->first();
        $room->update(['status' => 'occupied']);

        [$booking, $br] = $this->checkedInBooking(1500, 'full', null, 1, $roomType);
        $br->update(['room_id' => $room->id]);

        $this->assertEquals(1500, $booking->total_amount);
        $this->assertEquals(1500, $booking->paid_amount);
        $this->assertEquals(0, $booking->outstanding_amount);
        $this->assertTrue($booking->is_paid);

        // 1. Staff เพิ่ม addon อาหารเช้าชุด 200 (200 บาท)
        $putRes = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'breakfast_sets' => [
                        'set_200' => 1,
                    ],
                ],
            ]);
        $putRes->assertStatus(200);

        $freshBooking = $booking->fresh();
        $this->assertEquals(1700, $freshBooking->total_amount);
        $this->assertEquals(200, $freshBooking->outstanding_amount);
        $this->assertFalse($freshBooking->is_paid);

        // 2. พยายาม check-out ขณะมียอดค้างชำระ → โดน reject 400
        $checkOutRes = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/check-out", [
                'verified_by' => $staff->id,
            ]);

        $checkOutRes->assertStatus(400);
        $this->assertStringContainsString('ยังมีรายการค้างชำระอยู่ 200 บาทค่ะนายท่าน', $checkOutRes->json('message'));
        $this->assertEquals('checked_in', $br->fresh()->status);
        $this->assertEquals('occupied', $room->fresh()->status);

        // 3. Front-desk บันทึกการชำระเงินส่วนต่าง (200 บาท) ผ่าน recordPayment
        $payRes = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/payment", [
                'booking_id' => $booking->id,
                'amount' => 200,
            ]);
        $payRes->assertStatus(201);

        $freshBooking = $booking->fresh();
        $this->assertEquals(0, $freshBooking->outstanding_amount);
        $this->assertTrue($freshBooking->is_paid);

        // 4. Check-out อีกครั้ง → สำเร็จ 200
        $checkOutSuccess = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/front-desk/{$booking->id}/rooms/{$br->id}/check-out", [
                'verified_by' => $staff->id,
            ]);

        $checkOutSuccess->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('booking_status', 'complete');

        $this->assertEquals('checked_out', $br->fresh()->status);
        $this->assertEquals('checkout_makeup', $room->fresh()->status);
        $this->assertEquals('complete', $booking->fresh()->status);
    }

    public function test_checked_in_room_with_no_existing_addon_creates_addon_row(): void
    {
        $staff = $this->staff();
        [$booking, $br] = $this->checkedInBooking(1500, 'full', null, 1);

        // ลบแถว addon จำลองเคส walk-in ที่ไม่มีแถว addon ใน DB
        Addon::where('booking_room_id', $br->id)->delete();
        $this->assertNull($br->fresh()->addon);

        $res = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'breakfast_sets' => [
                        'set_100' => 2,
                    ],
                ],
            ]);

        $res->assertStatus(200);
        $this->assertNotNull($br->fresh()->addon);
        $this->assertEquals(2, $br->fresh()->addon->breakfast_set_100);
        $this->assertEquals(1700, $booking->fresh()->total_amount); // 1500 + 2×100
    }

    public function test_addons_early_hours_greater_than_7_is_422(): void
    {
        $staff = $this->staff();
        [$booking, $br] = $this->checkedInBooking(1500, 'full', null, 1);

        $res = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/bookings/{$booking->id}/rooms/{$br->id}", [
                'addons' => [
                    'early_hours' => 8,
                ],
            ]);

        $res->assertStatus(422)
            ->assertJsonValidationErrors(['addons.early_hours']);
    }
}
