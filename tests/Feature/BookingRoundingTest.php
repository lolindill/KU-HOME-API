<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Discount;
use App\Models\GlobalRate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 💵 (25/09/26) REQ-015/016 — ปัดเศษขึ้นหลักสิบ + normalize คืนเต็ม
 *
 * wayfinder/booking-payment-types ticket 09 (implement ตาม decision ticket 07):
 *   - เรทไม่ใส่เลขสิบ (เช่น 1,255) → amount/total ปัดขึ้นหลักสิบตั้งแต่ตอนสร้าง
 *   - ส่วนลด percent ทำให้เศษ → ลดก่อน ปัดท้ายครั้งเดียว (invariant Σ คงอยู่)
 *   - datetime input มีเวลา → นับคืนเต็มเสมอ (startOfDay ก่อน diffInDays — Carbon 3 float guard)
 *   - มัดจำ default (total × deposit_percent) ปัดสิบต่อ · ยอด admin ตั้งเองไม่ force ปัด
 */
class BookingRoundingTest extends TestCase
{
    use RefreshDatabase;

    private function roomTypeSetup(int $price): RoomType
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
        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '101',
            'status' => 'available',
        ]);
        // ห้องที่สอง — test มัดจำสร้าง booking 2 ใบวันทับกัน
        Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '102',
            'status' => 'available',
        ]);

        return $roomType;
    }

    private function createBooking(User $user, string $roomTypeId, array $overrides = []): Booking
    {
        $payload = array_merge([
            'source' => 'admin',
            'booking_rooms' => [
                [
                    'room_type_id' => $roomTypeId,
                    'check_in' => now()->addDays(5)->toDateString(),
                    'check_out' => now()->addDays(6)->toDateString(),
                ],
            ],
        ], $overrides);

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', $payload);
        $created->assertStatus(201);

        return Booking::findOrFail($created->json('booking_id'));
    }

    public function test_rate_not_multiple_of_ten_rounds_up_to_tens(): void
    {
        $roomType = $this->roomTypeSetup(1255);
        $user = User::factory()->create(['role' => 'user']);

        // 3 คืน × 1,255 = 3,765 → ปัดขึ้นหลักสิบ = 3,770
        $booking = $this->createBooking($user, $roomType->id, [
            'booking_rooms' => [[
                'room_type_id' => $roomType->id,
                'check_in' => now()->addDays(5)->toDateString(),
                'check_out' => now()->addDays(8)->toDateString(),
            ]],
        ]);

        $br = BookingRoom::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(3765, $br->room_amount); // ยอดก่อนลดเก็บดิบ
        $this->assertEquals(3770, $br->amount);      // ยอดสุทธิปัดสิบ
        $this->assertEquals(3770, $booking->fresh()->total_amount); // Σ ไหลตาม
    }

    public function test_percent_discount_remainder_rounds_at_end(): void
    {
        $roomType = $this->roomTypeSetup(1255);
        $user = User::factory()->create(['role' => 'user']);

        Discount::create([
            'code' => 'SEVEN7',
            'type' => 'percent',
            'value' => 7,
            'is_active' => true,
        ]);

        // 1 คืน × 1,255 = 1,255 · ส่วนลด 7% = intdiv(8785,100) = 87 → สุทธิดิบ 1,168 → ปัดสิบ = 1,170
        $booking = $this->createBooking($user, $roomType->id, ['discount_code' => 'SEVEN7']);

        $br = BookingRoom::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(87, $br->discount_amount);
        $this->assertEquals(1170, $br->amount);
        $this->assertEquals(1170, $booking->fresh()->total_amount);
        $this->assertEquals(1170, array_sum(BookingRoom::where('booking_id', $booking->id)->pluck('amount')->all()));
    }

    public function test_datetime_input_counts_full_nights(): void
    {
        $roomType = $this->roomTypeSetup(1000);
        $user = User::factory()->create(['role' => 'user']);

        // เช็คอิน 14:00 / เช็คเอาท์ 11:00 — ต่างกัน 2 วันแบบเต็มคืน (ไม่ใช่ 1.875)
        $booking = $this->createBooking($user, $roomType->id, [
            'booking_rooms' => [[
                'room_type_id' => $roomType->id,
                'check_in' => now()->addDays(5)->setTime(14, 0)->format('Y-m-d H:i:s'),
                'check_out' => now()->addDays(7)->setTime(11, 0)->format('Y-m-d H:i:s'),
            ]],
        ]);

        $br = BookingRoom::where('booking_id', $booking->id)->firstOrFail();
        $this->assertEquals(2000, $br->amount); // 2 คืน × 1,000 — ไม่มีเศษหลุดจาก float
        $this->assertEquals(2000, $booking->fresh()->total_amount);
    }

    public function test_deposit_default_rounds_to_tens_but_admin_amount_is_not_forced(): void
    {
        $roomType = $this->roomTypeSetup(1255);

        // total = 1,260 (1,255 ปัดสิบ) · default 33% = ceil(415.8) = 416 → ปัดสิบ = 420
        config(['booking.deposit_percent' => 33]);
        $admin1 = User::factory()->create(['role' => 'admin']);
        $booking = $this->createBooking($admin1, $roomType->id, ['payment_type' => 'deposit']);
        $this->assertEquals(420, $booking->fresh()->deposit_amount);

        // ยอดที่ admin ตั้งเองไม่ force ปัด (admin รับผิดชอบตัวเลขเอง — ticket 07)
        // (admin คนละคนกัน — กฎ 1 user มี draft รอชำระได้ใบเดียว)
        $admin2 = User::factory()->create(['role' => 'admin']);
        $booking2 = $this->createBooking($admin2, $roomType->id, [
            'payment_type' => 'deposit',
            'deposit_amount' => 416,
        ]);
        $this->assertEquals(416, $booking2->fresh()->deposit_amount);
    }
}
