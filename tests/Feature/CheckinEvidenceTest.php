<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\Image;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 🛎️ (REQ-033): Test การอัปโหลดและจัดการหลักฐานการเข้าพักตอน check-in
 *
 * สิ่งที่ทดสอบ:
 *   1. อัปโหลดสำเร็จ + เก็บไฟล์บน private disk + บันทึก metadata (id_card, registration, guest_register, pdpa, other)
 *   2. ดึงรายการ evidence พร้อม signed URL 15 นาที + โหลดไฟล์ผ่าน route images.file สำเร็จ
 *   3. สิทธิ์การเข้าถึง (RBAC): admin/staff ผ่าน (200/201), user/housekeeping ถูกปฏิเสธ (403), guest (401)
 *   4. State machine guard: อนุญาต confirmed, checked_in, checked_out (draft / no_show ถูกปฏิเสธ 422)
 *   5. Data validation: validate ประเภทหลักฐาน, ไฟล์ และขนาด
 *   6. Retention 365 วัน: app:cleanup-images ลบหลักฐานอายุเกิน 365 วัน และเก็บกวาด orphan files/rows
 */
class CheckinEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected User $user;
    protected User $housekeeping;
    protected RoomType $roomType;
    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->staff = User::factory()->create(['role' => 'staff']);
        $this->user = User::factory()->create(['role' => 'user']);
        $this->housekeeping = User::factory()->create(['role' => 'housekeeping']);

        $this->roomType = RoomType::create([
            'name_en' => 'Deluxe Room',
            'name_th' => 'ห้องดีลักซ์',
            'max_guests' => 3,
            'extra_bed_enabled' => true,
        ]);

        $this->room = Room::create([
            'room_number' => '101',
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
            'floor' => 1,
        ]);
    }

    private function createBookingRoom(string $status = 'checked_in'): BookingRoom
    {
        $booking = Booking::create([
            'user_id' => $this->user->id,
            'confirmation' => 'BK-'.strtoupper(bin2hex(random_bytes(4))),
            'source' => 'online',
            'status' => $status === 'draft' ? 'draft' : 'confirmed',
            'total_amount' => 1200,
        ]);

        return BookingRoom::create([
            'booking_id' => $booking->id,
            'room_type_id' => $this->roomType->id,
            'room_id' => $this->room->id,
            'check_in' => now()->startOfDay(),
            'check_out' => now()->addDays(1)->startOfDay(),
            'status' => $status,
            'room_amount' => 1200,
            'amount' => 1200,
        ]);
    }

    // ============================================================
    // 1) อัปโหลดหลักฐาน (Upload Check-in Evidence)
    // ============================================================

    public function test_staff_can_upload_evidence_successfully(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');
        $file = UploadedFile::fake()->create('id_card.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                'file' => $file,
                'type' => 'id_card',
                'note' => 'บัตรประชาชนนายทดสอบ',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'booking_room_id' => $bookingRoom->id,
                    'type' => 'id_card',
                    'file_name' => 'id_card.jpg',
                    'mime_type' => 'image/jpeg',
                    'metadata' => [
                        'type' => 'id_card',
                        'note' => 'บัตรประชาชนนายทดสอบ',
                    ],
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['id']);
        $this->assertNotEmpty($data['url']);

        // ตรวจในฐานข้อมูล
        $this->assertDatabaseHas('images', [
            'id' => $data['id'],
            'imageable_type' => BookingRoom::class,
            'imageable_id' => $bookingRoom->id,
            'uploaded_by' => $this->staff->id,
            'original_name' => 'id_card.jpg',
        ]);

        // ตรวจไฟล์บน disk local
        $image = Image::findOrFail($data['id']);
        Storage::disk('local')->assertExists($image->path);
    }

    public function test_admin_can_upload_pdf_evidence(): void
    {
        $bookingRoom = $this->createBookingRoom('confirmed');
        $pdf = UploadedFile::fake()->create('registration_form.pdf', 250, 'application/pdf');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                'file' => $pdf,
                'type' => 'registration',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'booking_room_id' => $bookingRoom->id,
                    'type' => 'registration',
                    'file_name' => 'registration_form.pdf',
                    'mime_type' => 'application/pdf',
                ],
            ]);
    }

    public function test_supported_evidence_types(): void
    {
        $types = ['id_card', 'registration', 'guest_register', 'pdpa', 'other'];

        foreach ($types as $type) {
            $bookingRoom = $this->createBookingRoom('checked_in');
            $file = UploadedFile::fake()->create("doc_{$type}.png", 50, 'image/png');

            $response = $this->actingAs($this->staff, 'sanctum')
                ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                    'file' => $file,
                    'type' => $type,
                ]);

            $response->assertStatus(201)
                ->assertJsonPath('data.type', $type);
        }
    }

    // ============================================================
    // 2) สิทธิ์การเข้าถึง (Authorization / RBAC)
    // ============================================================

    public function test_user_cannot_upload_evidence(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');
        $file = UploadedFile::fake()->create('id.jpg', 50, 'image/jpeg');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                'file' => $file,
                'type' => 'id_card',
            ]);

        $response->assertStatus(403);
    }

    public function test_housekeeping_cannot_upload_evidence(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');
        $file = UploadedFile::fake()->create('id.jpg', 50, 'image/jpeg');

        $response = $this->actingAs($this->housekeeping, 'sanctum')
            ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                'file' => $file,
                'type' => 'id_card',
            ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_upload_evidence(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');
        $file = UploadedFile::fake()->create('id.jpg', 50, 'image/jpeg');

        $response = $this->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
            'file' => $file,
            'type' => 'id_card',
        ]);

        $response->assertStatus(401);
    }

    // ============================================================
    // 3) State Guard (อนุญาต confirmed, checked_in, checked_out)
    // ============================================================

    public function test_state_guard_allows_confirmed_checked_in_checked_out(): void
    {
        foreach (['confirmed', 'checked_in', 'checked_out'] as $state) {
            $bookingRoom = $this->createBookingRoom($state);
            $file = UploadedFile::fake()->create("test_{$state}.jpg", 50, 'image/jpeg');

            $response = $this->actingAs($this->staff, 'sanctum')
                ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                    'file' => $file,
                    'type' => 'id_card',
                ]);

            $response->assertStatus(201);
        }
    }

    public function test_state_guard_rejects_draft_and_no_show(): void
    {
        foreach (['draft', 'no_show'] as $invalidState) {
            $bookingRoom = $this->createBookingRoom($invalidState);
            $file = UploadedFile::fake()->create("test_{$invalidState}.jpg", 50, 'image/jpeg');

            $response = $this->actingAs($this->staff, 'sanctum')
                ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                    'file' => $file,
                    'type' => 'id_card',
                ]);

            $response->assertStatus(422)
                ->assertJson([
                    'status' => 'error',
                ]);
        }
    }

    // ============================================================
    // 4) Validation Tests
    // ============================================================

    public function test_invalid_evidence_type_returns_422(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');
        $file = UploadedFile::fake()->create('id.jpg', 50, 'image/jpeg');

        $response = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                'file' => $file,
                'type' => 'invalid_passport_type',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_missing_file_returns_422(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');

        $response = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                'type' => 'id_card',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_unsupported_file_mime_returns_422(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');
        $file = UploadedFile::fake()->create('document.txt', 10, 'text/plain');

        $response = $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                'file' => $file,
                'type' => 'id_card',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    // ============================================================
    // 5) GET Evidences & Signed URL Access
    // ============================================================

    public function test_can_get_evidences_list_with_signed_url_and_stream_file(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');
        $file = UploadedFile::fake()->create('my_id.jpg', 80, 'image/jpeg');

        // อัปโหลด 1 รูป
        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence", [
                'file' => $file,
                'type' => 'id_card',
                'note' => 'รูปบัตร ปชช.',
            ])->assertStatus(201);

        // ดึงรายการ evidence ผ่าน GET
        $response = $this->actingAs($this->staff, 'sanctum')
            ->getJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'booking_room_id',
                'evidences' => [
                    '*' => [
                        'id',
                        'type',
                        'metadata',
                        'file_name',
                        'mime_type',
                        'size',
                        'url',
                        'created_at',
                    ],
                ],
            ]);

        $evidences = $response->json('evidences');
        $this->assertCount(1, $evidences);
        $this->assertSame('id_card', $evidences[0]['type']);
        $this->assertNotEmpty($evidences[0]['url']);

        // ทดสอบเปิด signed URL ผ่าน route images.file โดยตรง (ไม่ต้องมี sanctum token)
        $signedUrl = $evidences[0]['url'];
        $streamResponse = $this->get($signedUrl);
        $streamResponse->assertStatus(200);
        $streamResponse->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_user_cannot_get_evidences(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/front-desk/booking-rooms/{$bookingRoom->id}/evidence");

        $response->assertStatus(403);
    }

    // ============================================================
    // 6) Retention Policy (365 วัน) & Cleanup Command
    // ============================================================

    public function test_cleanup_deletes_evidences_older_than_365_days(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');

        // สร้าง evidence เก่า 366 วัน (ควรถูกลบ)
        $oldPath = 'checkin_evidences/old-evidence.jpg';
        Storage::disk('local')->put($oldPath, 'fake-old-content');
        $oldImage = $bookingRoom->evidences()->create([
            'path' => $oldPath,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 16,
            'original_name' => 'old_id.jpg',
            'uploaded_by' => $this->staff->id,
            'metadata' => ['type' => 'id_card'],
        ]);
        Image::where('id', $oldImage->id)->update(['created_at' => Carbon::now()->subDays(366)]);

        // สร้าง evidence สดใหม่ 30 วัน (ควรยังอยู่)
        $freshPath = 'checkin_evidences/fresh-evidence.jpg';
        Storage::disk('local')->put($freshPath, 'fake-fresh-content');
        $freshImage = $bookingRoom->evidences()->create([
            'path' => $freshPath,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 18,
            'original_name' => 'fresh_id.jpg',
            'uploaded_by' => $this->staff->id,
            'metadata' => ['type' => 'id_card'],
        ]);
        Image::where('id', $freshImage->id)->update(['created_at' => Carbon::now()->subDays(30)]);

        // สั่งรัน cleanup command
        $this->artisan('app:cleanup-images')->assertExitCode(0);

        // ภาพเก่าต้องถูกลบจากทั้ง DB และ disk
        $this->assertDatabaseMissing('images', ['id' => $oldImage->id]);
        Storage::disk('local')->assertMissing($oldPath);

        // ภาพสดใหม่ต้องยังคงอยู่
        $this->assertDatabaseHas('images', ['id' => $freshImage->id]);
        Storage::disk('local')->assertExists($freshPath);
    }

    public function test_cleanup_sweeps_orphan_evidence_files_and_rows(): void
    {
        $bookingRoom = $this->createBookingRoom('checked_in');

        // 1. ไฟล์กำพร้าใน checkin_evidences folder (ไม่มี row ใน images)
        $orphanFile = 'checkin_evidences/orphan-file.jpg';
        Storage::disk('local')->put($orphanFile, 'stray-evidence');

        // 2. row กำพร้า (BookingRoom ถูกลบไปแล้ว)
        $referencedPath = 'checkin_evidences/referenced.jpg';
        Storage::disk('local')->put($referencedPath, 'referenced-content');
        $image = $bookingRoom->evidences()->create([
            'path' => $referencedPath,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 18,
            'original_name' => 'ref.jpg',
            'uploaded_by' => $this->staff->id,
            'metadata' => ['type' => 'id_card'],
        ]);

        // ลบ booking room ตรงๆ ทิ้ง row image ไว้
        BookingRoom::where('id', $bookingRoom->id)->delete();

        // รัน cleanup
        $this->artisan('app:cleanup-images')->assertExitCode(0);

        // ไฟล์กำพร้าต้องถูกลบ
        Storage::disk('local')->assertMissing($orphanFile);

        // row กำพร้าและไฟล์ที่ผูกอยู่ต้องถูกลบ
        $this->assertDatabaseMissing('images', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($referencedPath);
    }
}
