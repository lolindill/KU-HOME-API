<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 🖼️ (19/08/26): Image system — private disk + signed URL อายุ 15 นาที
 *
 * Flow ที่ทดสอบ:
 *   - signed URL ที่ได้จาก confirm เปิดดูรูปได้โดยไม่ต้อง login (signature คือตัวยืนยัน)
 *   - RequireJsonAccept ถูก exempt เฉพาะ route นี้ — <img Accept: image/*> ต้องผ่าน ไม่โดน 406
 *   - ลายเซ็นถูกแกะ / ไม่มีลายเซ็น / หมดอายุ → 403
 *   - draft POST /upload-image ถูกถอดออกแล้ว → 404
 */
class ImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Helper: สร้าง draft booking พร้อมเจ้าของ แล้ว confirm ผ่าน API จริง
     * คืน [response, booking] — response มี slip_image_url (signed URL)
     */
    private function confirmWithSlip(): array
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = Booking::create([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 4500,
            'payment_deadline' => now()->addHours(24),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->id}/confirm", [
                'slip_image' => UploadedFile::fake()->image('slip.jpg', 800, 600),
            ]);

        $response->assertStatus(201);

        return [$response, $booking];
    }

    // ============================================
    // 🔓 signed URL — เปิดได้ไม่ต้อง login
    // ============================================

    public function test_signed_url_serves_slip_image_without_auth(): void
    {
        [$response] = $this->confirmWithSlip();

        $url = $response->json('slip_image_url');
        $this->assertNotEmpty($url, 'confirm ต้องคืน slip_image_url');

        // ไม่ใส่ Authorization เลย — signature เป็นตัวยืนยันแทน
        $fileResponse = $this->get($url);

        $fileResponse->assertStatus(200);
        $this->assertStringContainsString(
            'image/jpeg',
            $fileResponse->headers->get('Content-Type'),
            'ต้อง stream ไฟล์พร้อม Content-Type ที่บันทึกไว้ตอนอัปโหลด'
        );
    }

    /**
     * ⚠️ RequireJsonAccept exemption — route นี้ต้องรับ <img Accept: image/*> ได้
     * (ไม่งั้น browser จะโดน 406 ตอนฝัง <img src="...">
     */
    public function test_signed_url_accepts_image_accept_header(): void
    {
        [$response] = $this->confirmWithSlip();

        $fileResponse = $this->get($response->json('slip_image_url'), [
            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*',
        ]);

        $fileResponse->assertStatus(200);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        [$response] = $this->confirmWithSlip();

        $tampered = str_replace(
            'signature=',
            'signature=tampered',
            $response->json('slip_image_url'),
        );

        $this->get($tampered)->assertStatus(403);
    }

    public function test_missing_signature_is_rejected(): void
    {
        [$response] = $this->confirmWithSlip();

        // ตัด query string ทิ้ง — เข้าเฉพาะ path เปล่าๆ
        $bareUrl = explode('?', $response->json('slip_image_url'))[0];

        $this->get($bareUrl)->assertStatus(403);
    }

    public function test_expired_signed_url_is_rejected(): void
    {
        [$response] = $this->confirmWithSlip();

        $url = $response->json('slip_image_url');

        // TTL = 15 นาที — เดินทางข้ามเวลาไป 16 นาที ต้องพ้นอายุ
        $this->travel(16)->minutes();

        $this->get($url)->assertStatus(403);
    }

    // ============================================
    // 📋 admin responses มี URL ไปด้วย
    // ============================================

    public function test_pending_dashboard_embeds_slip_image_with_url(): void
    {
        [$response] = $this->confirmWithSlip();

        $pendingResponse = $this->actingAsAdmin()
            ->getJson('/api/v1/booking-confirmations/pending');

        $pendingResponse->assertStatus(200);

        $confirmation = $pendingResponse->json('confirmations.data.0');
        $this->assertNotNull($confirmation['slip_image'] ?? null, 'pending list ต้อง eager load slipImage');
        $this->assertStringContainsString('signature=', $confirmation['slip_image']['url']);
    }

    // ============================================
    // 🚧 draft endpoint ถูกถอดออก
    // ============================================

    public function test_draft_upload_image_route_is_removed(): void
    {
        $this->postJson('/api/v1/upload-image', [
            'image' => UploadedFile::fake()->image('x.jpg'),
        ])->assertStatus(404);
    }
}
