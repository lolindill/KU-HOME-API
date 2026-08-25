<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingConfirmation;
use App\Models\GlobalRate;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🌟 Refactor (24/07/26): Booking Confirmation tests
 *
 * Flow ที่ทดสอบ:
 *   user confirm → admin verify/reject → user re-submit หลัง reject
 *   + state machine guards + ownership + terminal state locks
 */
class BookingConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 🚀 ป้องกันไฟล์จริงเขียนลง disk ตอน test (19/08/26: สลิปย้ายมาอยู่บน local/private disk แล้ว)
        Storage::fake('local');
    }

    /**
     * Helper: สร้าง draft booking (มี payment_deadline ล่วงหน้า 24h เหมือน controller)
     */
    private function createDraftBooking(?string $userId = null): Booking
    {
        $user = $userId ? User::find($userId) : User::factory()->create();

        $booking = Booking::create([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 4500,
            'payment_deadline' => now()->addHours(24),
        ]);

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
            'default_price' => 1500,
            'is_active' => true,
        ]);

        return $booking->fresh();
    }

    private function slipFile(): UploadedFile
    {
        return UploadedFile::fake()->image('slip.jpg', 800, 600);
    }

    private function confirmPayload(array $overrides = []): array
    {
        return array_merge([
            'slip_image' => $this->slipFile(),
            'transfer_time' => now()->subHour()->toDateTimeString(),
        ], $overrides);
    }

    // ============================================
    // 💳 confirm endpoint (user)
    // ============================================

    public function test_owner_can_submit_confirmation_with_slip(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($user->id);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('confirmation_status', 'pending')
            ->assertJsonPath('booking_status', 'pending');

        // ✅ response ต้องคืน signed URL (มี signature + อายุ 15 นาที) ไม่ใช่ path เปล่าๆ
        $slipUrl = $response->json('slip_image_url');
        $this->assertStringContainsString('/api/v1/images/', $slipUrl);
        $this->assertStringContainsString('signature=', $slipUrl);

        // ✅ DB assertions: confirmation created + booking pending (รอ admin ตรวจ)
        $this->assertDatabaseHas('booking_confirmations', [
            'booking_id' => $booking->id,
            'status' => 'pending',
        ]);
        // 🌟 Refactor (25/08/26): is_paid ยังเป็น false — submit สลิป ≠ จ่ายแล้ว (รอ admin verify)
        $this->assertFalse($booking->fresh()->is_paid);

        // ✅ 🖼️ (19/08/26) สลิปอยู่ใน images table ผ่าน morph + ไฟล์เขียนลง private (local) disk
        $confirmation = BookingConfirmation::first();
        $this->assertNotNull($confirmation->slipImage, 'confirmation ต้องมี slip Image row');
        $this->assertSame('local', $confirmation->slipImage->disk);
        $this->assertSame($user->id, $confirmation->slipImage->uploaded_by);
        $this->assertSame('image/jpeg', $confirmation->slipImage->mime_type);
        Storage::disk('local')->assertExists($confirmation->slipImage->path);
    }

    public function test_admin_can_submit_confirmation_for_any_booking(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($owner->id);

        $response = $this->actingAsAdmin()
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(201)
            ->assertJsonPath('booking_status', 'pending');
    }

    public function test_ku_member_can_submit_confirmation(): void
    {
        $owner = User::factory()->create(['role' => 'ku_member', 'is_ku_member' => true]);
        $booking = $this->createDraftBooking($owner->id);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(201)
            ->assertJsonPath('booking_status', 'pending');
        $this->assertEquals('pending', $booking->fresh()->status);
    }

    public function test_staff_can_submit_confirmation(): void
    {
        $owner = User::factory()->create(['role' => 'staff']);
        $booking = $this->createDraftBooking($owner->id);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(201)
            ->assertJsonPath('booking_status', 'pending');
        $this->assertEquals('pending', $booking->fresh()->status);
    }

    public function test_non_owner_cannot_submit_confirmation(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($owner->id);

        $otherUser = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(403);
        $this->assertDatabaseMissing('booking_confirmations', ['booking_id' => $booking->id]);
    }

    public function test_unauthenticated_confirm_returns_401(): void
    {
        $booking = $this->createDraftBooking();

        $response = $this->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(401);
    }

    public function test_cannot_confirm_completed_booking(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($user->id);
        $booking->update(['status' => 'confirmed']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(422);
    }

    public function test_cannot_confirm_expired_booking(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($user->id);
        $booking->update(['payment_deadline' => now()->subHour()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_confirm_requires_slip_image(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($user->id);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", [
                'transfer_time' => now()->subHour()->toDateTimeString(),
                // ❌ ไม่ส่ง slip_image (บังคับเสมอแล้ว)
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['slip_image']);
    }

    public function test_transfer_time_is_optional(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($user->id);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", [
                'slip_image' => $this->slipFile(),
                // ❌ ไม่ส่ง transfer_time (optional)
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('confirmation_status', 'pending');
        $this->assertNotNull(BookingConfirmation::first()->slipImage);
        $this->assertNull(BookingConfirmation::first()->transfer_time);
    }

    public function test_cannot_submit_when_pending_exists(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($user->id);

        // ส่งครั้งแรก — สำเร็จ
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload())
            ->assertStatus(201);

        // ส่งซ้ำขณะยัง pending → ต้องปฏิเสธ
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        // ✅ ต้องมี confirmation row เดียวเท่านั้น (ไม่สร้าง row ที่ 2)
        $this->assertEquals(1, BookingConfirmation::where('booking_id', $booking->id)->count());
    }

    // ============================================
    // ✅ verify / ❌ reject endpoints (admin)
    // ============================================

    public function test_admin_can_verify_confirmation(): void
    {
        $booking = $this->createDraftBooking();
        $confirmation = BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'pending',
        ]);
        $booking->update(['status' => 'pending']);

        $response = $this->actingAsAdmin()
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify", [
                'review_note' => 'slip ok',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('confirmation.status', 'verified')
            ->assertJsonPath('booking_status', 'confirmed');

        $this->assertDatabaseHas('booking_confirmations', [
            'id' => $confirmation->id,
            'status' => 'verified',
            'review_note' => 'slip ok',
        ]);
        $this->assertNotNull($confirmation->fresh()->reviewed_by);

        // 🌟 Refactor (25/08/26): is_paid ถูก set ตอน verify (pending → paid → confirmed)
        $this->assertTrue($booking->fresh()->is_paid);
    }

    public function test_full_flow_confirm_then_verify_confirms_booking(): void
    {
        // 🌟 Refactor (25/08/26): flow เต็ม — draft → (submit) pending → (verify) paid → confirmed
        $user = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($user->id);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload())
            ->assertStatus(201)
            ->assertJsonPath('booking_status', 'pending');
        $this->assertFalse($booking->fresh()->is_paid);

        $confirmation = BookingConfirmation::where('booking_id', $booking->id)
            ->where('status', 'pending')
            ->first();
        $this->assertNotNull($confirmation);

        $this->actingAsAdmin()
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify")
            ->assertStatus(200)
            ->assertJsonPath('confirmation.status', 'verified')
            ->assertJsonPath('booking_status', 'confirmed');

        $this->assertTrue($booking->fresh()->is_paid);
    }

    public function test_admin_can_reject_confirmation(): void
    {
        $booking = $this->createDraftBooking();
        $confirmation = BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'pending',
        ]);
        $booking->update(['status' => 'pending']);

        $response = $this->actingAsAdmin()
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/reject", [
                'review_note' => 'slip ไม่ชัด',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('confirmation.status', 'rejected')
            ->assertJsonPath('booking_status', 'verify_error');

        // 🌟 Refactor (25/08/26): booking เปลี่ยนเป็น verify_error — user ส่ง slip ใหม่ได้ (row ใหม่)
        $this->assertEquals('verify_error', $booking->fresh()->status);
        $this->assertFalse($booking->fresh()->is_paid);
    }

    public function test_user_can_resubmit_after_reject(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($user->id);
        $booking->update(['status' => 'verify_error']);

        // 📜 row #1 — rejected (history)
        BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'rejected',
        ]);

        // 🆕 user ส่ง slip ใหม่ (booking เป็น verify_error หลัง reject) → สร้าง row #2 pending
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(201)
            ->assertJsonPath('confirmation_status', 'pending')
            ->assertJsonPath('booking_status', 'pending');

        // ✅ 1:N — มี 2 rows (rejected + pending)
        $this->assertEquals(2, BookingConfirmation::where('booking_id', $booking->id)->count());
        $this->assertEquals(1, BookingConfirmation::where('booking_id', $booking->id)->where('status', 'rejected')->count());
        $this->assertEquals(1, BookingConfirmation::where('booking_id', $booking->id)->where('status', 'pending')->count());
    }

    public function test_user_can_resubmit_from_verify_error_even_after_deadline_expired(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = $this->createDraftBooking($user->id);
        // จำลองเคสที่ payment_deadline ผ่านไปแล้ว และสถานะเป็น verify_error
        $booking->update([
            'status' => 'verify_error',
            'payment_deadline' => now()->subHours(2),
        ]);

        BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'rejected',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", $this->confirmPayload());

        $response->assertStatus(201)
            ->assertJsonPath('confirmation_status', 'pending')
            ->assertJsonPath('booking_status', 'pending');

        $this->assertEquals('pending', $booking->fresh()->status);
    }

    public function test_rejected_confirmation_stays_in_history(): void
    {
        $booking = $this->createDraftBooking();
        BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'rejected',
        ]);

        // relation confirmations() ต้องเห็น history ทั้งหมด
        $this->assertEquals(1, $booking->confirmations()->count());
        $this->assertEquals('rejected', $booking->confirmations()->first()->status);
    }

    public function test_non_admin_cannot_verify(): void
    {
        $booking = $this->createDraftBooking();
        $confirmation = BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAsUser()
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify");

        $response->assertStatus(403);
    }

    public function test_cannot_verify_already_verified(): void
    {
        $booking = $this->createDraftBooking();
        $confirmation = BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'verified', // terminal
        ]);

        $response = $this->actingAsAdmin()
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify");

        $response->assertStatus(422); // state machine rejects
    }

    public function test_cannot_verify_rejected_confirmation(): void
    {
        $booking = $this->createDraftBooking();
        $confirmation = BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'rejected', // terminal
        ]);

        $response = $this->actingAsAdmin()
            ->putJson("/api/v1/booking-confirmations/{$confirmation->id}/verify");

        $response->assertStatus(422);
    }

    // ============================================
    // 📋 pending dashboard (admin)
    // ============================================

    public function test_admin_can_list_pending_confirmations(): void
    {
        $booking = $this->createDraftBooking();
        BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'pending',
        ]);
        BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => 'verified', // ไม่ควรขึ้น list
        ]);

        $response = $this->actingAsAdmin()
            ->getJson('/api/v1/booking-confirmations/pending');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // ✅ เห็นเฉพาะ pending เท่านั้น
        $confirmations = $response->json('confirmations.data');
        $this->assertCount(1, $confirmations);
        $this->assertEquals('pending', $confirmations[0]['status']);
    }

    public function test_non_admin_cannot_list_pending(): void
    {
        $response = $this->actingAsUser()
            ->getJson('/api/v1/booking-confirmations/pending');

        $response->assertStatus(403);
    }

    // ============================================
    // ❄️ webhook frozen
    // ============================================

    public function test_webhook_returns_410_gone(): void
    {
        $response = $this->postJson('/api/v1/payment/webhook', [
            'payment_id' => Str::uuid(),
            'status' => 'success',
        ]);

        $response->assertStatus(410)
            ->assertJsonPath('status', 'error');
    }
}
