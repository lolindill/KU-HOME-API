<?php

namespace Tests\Unit;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\GlobalRate;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🌟 Helper: สร้าง Booking + BookingRoom สำหรับทดสอบ
     * Refactor (25/06/26): bookings ไม่มี guest_name/check_in แล้ว
     */
    private function createBooking(string $bookingStatus = 'draft', string $brStatus = 'draft'): Booking
    {
        $user = User::factory()->create();

        $roomType = RoomType::create([
            'name_en' => 'Test Suite',
            'name_th' => 'ห้องทดสอบ',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);
        // 🌟 Refactor (22/07/26): rate_daily_general ย้ายไป global_rates แล้ว
        GlobalRate::create([
            'rate_type' => 'daily',
            'room_type_id' => $roomType->id,
            'code' => null,
            'name_en' => 'Test Suite Daily',
            'default_price' => 1000,
            'is_active' => true,
        ]);

        $booking = Booking::create([
            'confirmation' => 'TEST-'.uniqid(),
            'user_id' => $user->id,
            'source' => 'admin',
            'status' => $bookingStatus,
            'total_amount' => 1000,
            'payment_deadline' => now()->addDay(),
        ]);

        BookingRoom::create([
            'booking_id' => $booking->id,
            'room_type_id' => $roomType->id,
            'room_id' => null,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'status' => $brStatus,
            'guests' => [['title' => 'Mr', 'name' => 'Test Guest', 'nationality' => 'Thai']],
        ]);

        return $booking->fresh(['bookingRooms']);
    }

    // ============================================
    // ✅ Booking Container — Valid Transitions
    // (Container states: draft → paid → confirmed → complete)
    // ❌ ไม่มี cancelled — draft ที่หมดอายุจะถูก hard delete (CleanupExpiredDrafts)
    // ============================================

    public function test_draft_to_paid_by_user(): void
    {
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('paid', 'user');
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    public function test_draft_to_paid_by_admin(): void
    {
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('paid', 'admin');
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    public function test_draft_to_paid_by_system(): void
    {
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('paid', 'system');
        $this->assertEquals('paid', $booking->fresh()->status);
    }

    public function test_paid_to_confirmed_by_admin(): void
    {
        $booking = $this->createBooking('paid');
        $booking->transitionStatus('confirmed', 'admin');
        $this->assertEquals('confirmed', $booking->fresh()->status);
    }

    public function test_confirmed_to_complete_by_admin(): void
    {
        $booking = $this->createBooking('confirmed');
        $booking->transitionStatus('complete', 'admin');
        $this->assertEquals('complete', $booking->fresh()->status);
    }

    // ============================================
    // ❌ Booking Container — Invalid Transitions
    // ============================================

    public function test_draft_to_confirmed_allowed_only_for_admin_walkin(): void
    {
        // Refactor (25/06/26): draft → confirmed อนุญาตเฉพาะ admin (walk-in)
        // user ไม่มีสิทธิ์
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('confirmed', 'user');
    }

    public function test_cannot_go_from_draft_to_complete(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('draft');
        $booking->transitionStatus('complete', 'admin');
    }

    public function test_cannot_go_from_paid_to_complete(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('paid');
        $booking->transitionStatus('complete', 'admin');
    }

    public function test_cannot_go_from_complete_to_anything(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('complete');
        $booking->transitionStatus('draft', 'admin');
    }

    // ============================================
    // 🔒 Role Restrictions (Container)
    // ============================================

    public function test_paid_to_confirmed_rejected_for_user(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('paid');
        $booking->transitionStatus('confirmed', 'user');
    }

    public function test_confirmed_to_complete_rejected_for_user(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('confirmed');
        $booking->transitionStatus('complete', 'user');
    }

    // ============================================
    // 🌟 BookingRoom-level State Transitions
    // (BR states: draft → confirmed → checked_in → checked_out / cancelled / no_show)
    // ============================================

    public function test_br_draft_to_confirmed(): void
    {
        $booking = $this->createBooking('confirmed', 'draft');
        $br = $booking->bookingRooms->first();
        $br->transitionStatus('confirmed', 'admin');
        $this->assertEquals('confirmed', $br->fresh()->status);
    }

    public function test_br_confirmed_to_checked_in_by_admin(): void
    {
        $booking = $this->createBooking('confirmed', 'confirmed');
        $br = $booking->bookingRooms->first();
        $br->transitionStatus('checked_in', 'admin');
        $this->assertEquals('checked_in', $br->fresh()->status);
    }

    public function test_br_checked_in_to_checked_out_by_admin(): void
    {
        $booking = $this->createBooking('confirmed', 'checked_in');
        $br = $booking->bookingRooms->first();
        $br->transitionStatus('checked_out', 'admin');
        $this->assertEquals('checked_out', $br->fresh()->status);
    }

    public function test_br_confirmed_to_no_show_by_admin(): void
    {
        $booking = $this->createBooking('confirmed', 'confirmed');
        $br = $booking->bookingRooms->first();
        $br->transitionStatus('no_show', 'admin');
        $this->assertEquals('no_show', $br->fresh()->status);
    }

    public function test_br_cannot_go_from_draft_directly_to_checked_in(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('confirmed', 'draft');
        $br = $booking->bookingRooms->first();
        $br->transitionStatus('checked_in', 'admin');
    }

    public function test_br_checked_in_rejected_for_user(): void
    {
        $this->expectException(\Exception::class);
        $booking = $this->createBooking('confirmed', 'confirmed');
        $br = $booking->bookingRooms->first();
        $br->transitionStatus('checked_in', 'user');
    }
}
