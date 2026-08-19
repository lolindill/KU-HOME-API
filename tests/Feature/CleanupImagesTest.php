<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingConfirmation;
use App\Models\Image;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 🧹 (19/08/26): app:cleanup-images — เก็บกวาดรูปสลิปรอบ 02:30
 *
 * สายที่ทดสอบ:
 *   1. ไฟล์กำพร้าบน disk (ไม่มี Image row คุม) → ลบ
 *   2. Image row กำพร้า (confirmation หายไปแล้ว / ไม่มี imageable แก่กว่า 24 ชม.) → ลบ (ไฟล์ถูกลบโดย hook)
 *   3. retention — สลิป rejected เก่ากว่า 30 วัน → ลบรูป แต่คง confirmation row (audit trail)
 *   4. สลิป verified/pending อยู่รอดเสมอ (หลักฐานการเงิน/กำลังใช้งาน)
 */
class CleanupImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Helper: สร้าง booking + confirmation + ไฟล์ slip จริงบน fake disk + Image morph
     */
    private function createConfirmationWithSlip(string $status = 'pending', ?CarbonInterface $createdAt = null): BookingConfirmation
    {
        $user = User::factory()->create();
        $booking = Booking::create([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'draft',
            'total_amount' => 4500,
            'payment_deadline' => now()->addHours(24),
        ]);

        $confirmation = BookingConfirmation::create([
            'booking_id' => $booking->id,
            'status' => $status,
        ]);

        $path = 'slips/'.str()->uuid().'.jpg';
        Storage::disk('local')->put($path, 'fake-slip-content');

        $confirmation->slipImage()->create([
            'path' => $path,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 19,
            'original_name' => 'slip.jpg',
            'uploaded_by' => $user->id,
        ]);

        if ($createdAt) {
            // query-builder update — ข้าม casts/fillable ตั้ง created_at ย้อนหลังได้ตรงๆ
            BookingConfirmation::where('id', $confirmation->id)->update(['created_at' => $createdAt]);
            Image::where('imageable_id', $confirmation->id)->update(['created_at' => $createdAt]);
        }

        return $confirmation->fresh();
    }

    // ============================================
    // 1) ไฟล์กำพร้าบน disk
    // ============================================

    public function test_orphan_files_are_deleted_but_referenced_files_kept(): void
    {
        $confirmation = $this->createConfirmationWithSlip();
        $keptPath = $confirmation->slipImage->path;

        // ไฟล์หลงเหลือจาก failed transaction (เขียนไปแล้วแต่ไม่มี row)
        Storage::disk('local')->put('slips/orphan-from-rollback.jpg', 'stray');

        $this->artisan('app:cleanup-images')->assertExitCode(0);

        Storage::disk('local')->assertMissing('slips/orphan-from-rollback.jpg');
        Storage::disk('local')->assertExists($keptPath);
        $this->assertDatabaseHas('images', ['id' => $confirmation->slipImage->id]);
    }

    // ============================================
    // 2) Image row กำพร้า
    // ============================================

    public function test_images_of_deleted_confirmation_are_swept(): void
    {
        $confirmation = $this->createConfirmationWithSlip();
        $image = $confirmation->slipImage;

        // จำลอง "จุดลบที่ลืมเก็บรูป" — ลบ confirmation ตรงๆ ทิ้ง image ไว้
        BookingConfirmation::where('id', $confirmation->id)->delete();

        $this->artisan('app:cleanup-images')->assertExitCode(0);

        $this->assertDatabaseMissing('images', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($image->path); // hook deleting ลบไฟล์ให้
    }

    public function test_old_unlinked_images_are_swept_but_fresh_ones_kept(): void
    {
        // แก่กว่า 24 ชม. → ลบ
        $old = Image::create([
            'path' => 'slips/old-unlinked.jpg',
            'disk' => 'local',
        ]);
        Image::where('id', $old->id)->update(['created_at' => now()->subHours(25)]);
        Storage::disk('local')->put('slips/old-unlinked.jpg', 'x');

        // สดว่า 24 ชม. → คงไว้ (อาจกำลังอยู่ระหว่าง flow ที่ยังไม่ผูก imageable)
        $fresh = Image::create([
            'path' => 'slips/fresh-unlinked.jpg',
            'disk' => 'local',
        ]);
        Storage::disk('local')->put('slips/fresh-unlinked.jpg', 'x');

        $this->artisan('app:cleanup-images')->assertExitCode(0);

        $this->assertDatabaseMissing('images', ['id' => $old->id]);
        Storage::disk('local')->assertMissing('slips/old-unlinked.jpg');
        $this->assertDatabaseHas('images', ['id' => $fresh->id]);
    }

    // ============================================
    // 3) retention — rejected เก่ากว่า 30 วัน
    // ============================================

    public function test_rejected_slips_older_than_retention_are_pruned(): void
    {
        $rejected = $this->createConfirmationWithSlip('rejected', now()->subDays(31));
        $image = $rejected->slipImage; // จับ reference ไว้ก่อน — หลัง command รูปจะหายไปแล้ว

        $this->artisan('app:cleanup-images')->assertExitCode(0);

        // ลบเฉพาะรูป — confirmation row คงไว้ตาม audit trail
        $this->assertDatabaseMissing('images', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($image->path);
        $this->assertDatabaseHas('booking_confirmations', ['id' => $rejected->id, 'status' => 'rejected']);
    }

    public function test_recent_rejected_slips_are_kept_until_retention(): void
    {
        $recentRejected = $this->createConfirmationWithSlip('rejected', now()->subDays(7));

        $this->artisan('app:cleanup-images')->assertExitCode(0);

        $this->assertDatabaseHas('images', ['id' => $recentRejected->slipImage->id]);
        Storage::disk('local')->assertExists($recentRejected->slipImage->path);
    }

    // ============================================
    // 4) verified / pending อยู่รอดเสมอ
    // ============================================

    public function test_verified_slips_are_never_pruned(): void
    {
        $verified = $this->createConfirmationWithSlip('verified', now()->subDays(365));

        $this->artisan('app:cleanup-images')->assertExitCode(0);

        $this->assertDatabaseHas('images', ['id' => $verified->slipImage->id]);
        Storage::disk('local')->assertExists($verified->slipImage->path);
    }

    public function test_pending_slips_are_never_pruned(): void
    {
        $pending = $this->createConfirmationWithSlip('pending', now()->subDays(31));

        $this->artisan('app:cleanup-images')->assertExitCode(0);

        $this->assertDatabaseHas('images', ['id' => $pending->slipImage->id]);
    }
}
