<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ⏰ (2026-09-24, SRS v2 REQ-032/034): Early Check-in / Late Check-out ได้ไม่เกิน 7 ชั่วโมง
 *    Validation `max:7` มีครบทั้ง 4 write paths (create / add-rooms / แก้รายห้อง / แก้ batch)
 *    — ชุดนี้ป้องกัน regression ของ 3 paths ที่เหลือ (create มี test คุมอยู่แล้วใน BookingTest)
 */
class BookingAddonHoursCapTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Booking $booking;

    private BookingRoom $bookingRoom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Double',
            'name_th' => 'สแตนดาร์ด ดับเบิล',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        $this->booking = Booking::create([
            'user_id' => $this->user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 0,
            'payment_deadline' => now()->addMinutes(15),
        ]);
        $this->bookingRoom = BookingRoom::create([
            'booking_id' => $this->booking->id,
            'room_type_id' => $roomType->id,
            'check_in' => now()->addDays(5)->toDateString(),
            'check_out' => now()->addDays(6)->toDateString(),
            'status' => 'draft',
            'room_amount' => 0,
            'discount_amount' => 0,
        ]);
    }

    public function test_add_rooms_rejects_early_hours_over_7(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$this->booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'room_type_id' => $this->bookingRoom->room_type_id,
                        'check_in' => now()->addDays(5)->toDateString(),
                        'check_out' => now()->addDays(6)->toDateString(),
                        'addons' => ['early_hours' => 8],
                    ],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_update_room_rejects_late_checkout_over_7(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$this->booking->id}/rooms/{$this->bookingRoom->id}", [
                'addons' => ['late_checkout' => 8],
            ])
            ->assertStatus(422);
    }

    public function test_batch_update_rejects_late_hours_over_7(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/bookings/{$this->booking->id}/rooms", [
                'booking_rooms' => [
                    [
                        'booking_room_id' => $this->bookingRoom->id,
                        'addons' => ['late_hours' => 8],
                    ],
                ],
            ])
            ->assertStatus(422);
    }
}
