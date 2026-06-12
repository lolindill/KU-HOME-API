<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class RoomStateTest extends TestCase
{
    use RefreshDatabase;

    private function createRoomType(): RoomType
    {
        return RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Double',
            'name_th' => 'สแตนดาร์ด ดับเบิล',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
            'rate_daily_general' => 1500,
        ]);
    }

    private function createRoom(string $status = 'available'): Room
    {
        $roomType = $this->createRoomType();
        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '10' . rand(1, 99),
            'status' => $status,
        ]);
    }

    // ============================================
    // ✅ Valid Transitions
    // ============================================

    public function test_available_to_occupied(): void
    {
        $room = $this->createRoom('available');
        $room->transitionStatusTo('occupied');
        $this->assertEquals('occupied', $room->fresh()->status);
    }

    public function test_available_to_dirty(): void
    {
        $room = $this->createRoom('available');
        $room->transitionStatusTo('dirty');
        $this->assertEquals('dirty', $room->fresh()->status);
    }

    public function test_available_to_maintenance(): void
    {
        $room = $this->createRoom('available');
        $room->transitionStatusTo('maintenance');
        $this->assertEquals('maintenance', $room->fresh()->status);
    }

    public function test_available_to_reserved_closed(): void
    {
        $room = $this->createRoom('available');
        $room->transitionStatusTo('reserved_closed');
        $this->assertEquals('reserved_closed', $room->fresh()->status);
    }

    public function test_occupied_to_checkout_makeup(): void
    {
        $room = $this->createRoom('occupied');
        $room->transitionStatusTo('checkout_makeup');
        $this->assertEquals('checkout_makeup', $room->fresh()->status);
    }

    public function test_checkout_makeup_to_available(): void
    {
        $room = $this->createRoom('checkout_makeup');
        $room->transitionStatusTo('available');
        $this->assertEquals('available', $room->fresh()->status);
    }

    public function test_dirty_to_available(): void
    {
        $room = $this->createRoom('dirty');
        $room->transitionStatusTo('available');
        $this->assertEquals('available', $room->fresh()->status);
    }

    public function test_dirty_to_checkout_makeup_is_invalid(): void
    {
        $this->expectException(\Exception::class);
        $room = $this->createRoom('dirty');
        $room->transitionStatusTo('checkout_makeup');
    }

    public function test_prep_checkin_to_available(): void
    {
        $room = $this->createRoom('prep_checkin');
        $room->transitionStatusTo('available');
        $this->assertEquals('available', $room->fresh()->status);
    }

    public function test_prep_checkin_to_dirty(): void
    {
        $room = $this->createRoom('prep_checkin');
        $room->transitionStatusTo('dirty');
        $this->assertEquals('dirty', $room->fresh()->status);
    }

    // ============================================
    // ✅ Wildcard Transitions (→ maintenance, reserved_closed = any source)
    // ============================================

    public function test_any_to_maintenance(): void
    {
        $room = $this->createRoom('maintenance');
        $room->transitionStatusTo('available');
        $this->assertEquals('available', $room->fresh()->status);
    }

    public function test_maintenance_to_occupied_is_invalid(): void
    {
        // occupied only allows from available, prep_checkin — not maintenance
        $this->expectException(\Exception::class);
        $room = $this->createRoom('maintenance');
        $room->transitionStatusTo('occupied');
    }

    public function test_reserved_closed_to_available(): void
    {
        $room = $this->createRoom('reserved_closed');
        $room->transitionStatusTo('available');
        $this->assertEquals('available', $room->fresh()->status);
    }

    public function test_reserved_closed_to_dirty_is_invalid(): void
    {
        // dirty only allows from available, prep_checkin — not reserved_closed
        $this->expectException(\Exception::class);
        $room = $this->createRoom('reserved_closed');
        $room->transitionStatusTo('dirty');
    }

    // ============================================
    // ❌ Invalid Transitions
    // ============================================

    public function test_available_to_prep_checkin_is_valid(): void
    {
        // prep_checkin allows from available, dirty — so this IS valid
        $room = $this->createRoom('available');
        $room->transitionStatusTo('prep_checkin');
        $this->assertEquals('prep_checkin', $room->fresh()->status);
    }

    public function test_cannot_go_from_dirty_to_occupied(): void
    {
        $this->expectException(\Exception::class);
        $room = $this->createRoom('dirty');
        $room->transitionStatusTo('occupied');
    }

    public function test_cannot_go_from_checkout_makeup_to_dirty(): void
    {
        $this->expectException(\Exception::class);
        $room = $this->createRoom('checkout_makeup');
        $room->transitionStatusTo('dirty');
    }

    public function test_cannot_go_from_occupied_to_available(): void
    {
        // occupied → available: allowedTransitions['available'] includes 'checkout_makeup', 'dirty', 'maintenance', 'reserved_closed', 'prep_checkin'
        // NOT 'occupied'. So this should fail.
        $this->expectException(\Exception::class);
        $room = $this->createRoom('occupied');
        $room->transitionStatusTo('available');
    }

    // ============================================
    // 🔄 Same-status no-op
    // ============================================

    public function test_same_status_returns_false(): void
    {
        $room = $this->createRoom('available');
        $result = $room->transitionStatusTo('available');
        $this->assertFalse($result);
        $this->assertEquals('available', $room->fresh()->status);
    }

    // ============================================
    // 📝 Metadata tracking
    // ============================================

    public function test_status_updated_at_is_set(): void
    {
        $room = $this->createRoom('available');
        $this->assertNull($room->status_updated_at);
        $room->transitionStatusTo('dirty');
        $this->assertNotNull($room->fresh()->status_updated_at);
    }

    public function test_status_updated_by_is_set(): void
    {
        $userId = Str::uuid();
        $room = $this->createRoom('available');
        $room->transitionStatusTo('dirty', $userId);
        $this->assertEquals($userId, $room->fresh()->status_updated_by);
    }

    public function test_status_updated_by_is_null_when_not_provided(): void
    {
        $room = $this->createRoom('available');
        $room->transitionStatusTo('dirty');
        $this->assertNull($room->fresh()->status_updated_by);
    }

    // ============================================
    // 🔤 Case insensitivity
    // ============================================

    public function test_uppercase_status_is_lowercased(): void
    {
        $room = $this->createRoom('available');
        $room->transitionStatusTo('DIRTY');
        $this->assertEquals('dirty', $room->fresh()->status);
    }
}