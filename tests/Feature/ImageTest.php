<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HousekeepingTask;
use App\Models\Image;
use App\Models\Organization;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🖼️ (19/08/26): Image system — private disk + signed URL อายุ 15 นาที
 * 🔐 (26/10/26): เพิ่ม auth:sanctum — token + signature คุมคู่กัน (2 ชั้น)
 *
 * Flow ที่ทดสอบ:
 *   - ไม่ส่ง token → 401 (auth:sanctum ตรวจก่อน signature)
 *   - token ถูก + signature ถูก → เจ้าของ slip / admin เปิดรูปได้ 200
 *   - token ถูก แต่ไม่ใช่เจ้าของ → 403
 *   - RequireJsonAccept ถูก exempt เฉพาะ route นี้ — Accept: image/* ต้องผ่าน ไม่โดน 406
 *   - ลายเซ็นถูกแกะ / ไม่มีลายเซ็น / หมดอายุ (เมื่อ login แล้ว) → 403
 *   - JSON ของ slip_image ซ่อน field ภายใน (path, disk, imageable_id/type, uploaded_by, metadata)
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
     * คืน [response, booking, user] — response มี slip_image_url (signed URL)
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
                'amount' => 4500, // 💳 (25/09/26) confirm บังคับ amount แล้ว
            ]);

        $response->assertStatus(201);

        return [$response, $booking, $user];
    }

    /**
     * Helper: สร้าง slip Image ผูกกับ confirmation ตรงผ่าน model (ไม่ยุ่ง auth state)
     * ใช้กับเคสที่ต้องเทสต์ request แบบ anonymous
     */
    private function createSlipImageDirectly(): Image
    {
        $user = User::factory()->create(['role' => 'user']);
        $booking = Booking::create([
            'user_id' => $user->id,
            'source' => 'online',
            'status' => 'pending',
            'total_amount' => 4500,
            'payment_deadline' => now()->addHours(24),
        ]);
        $confirmation = $booking->confirmations()->create([
            'transfer_time' => now(),
            'amount' => 4500,
            'status' => 'pending',
        ]);

        $path = 'slips/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'fake image bytes');

        return $confirmation->slipImage()->create([
            'path' => $path,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => strlen('fake image bytes'),
            'original_name' => 'slip.jpg',
            'category' => 'slip',
            'uploaded_by' => $user->id,
        ]);
    }

    // ============================================
    // 🔐 auth:sanctum — ไม่มี token → 401
    // ============================================

    public function test_image_file_requires_authentication(): void
    {
        // สร้าง slip ตรงผ่าน model — ห้ามผ่าน actingAs (user ค้างใน guard ทั้ง test method)
        $image = $this->createSlipImageDirectly();
        $url = URL::temporarySignedRoute(
            'images.file', now()->addMinutes(15), ['image' => $image->id],
        );

        // signature ถูกทั้งชุด แต่ไม่ส่ง Authorization → โดน auth:sanctum ก่อนตรวจ signature
        $this->get($url)->assertStatus(401);
    }

    // ============================================
    // 🔓 เจ้าของ / admin — token + signature ครบ → 200
    // ============================================

    public function test_signed_url_serves_slip_image_for_owner(): void
    {
        [$response, , $user] = $this->confirmWithSlip();

        $url = $response->json('slip_image_url');
        $this->assertNotEmpty($url, 'confirm ต้องคืน slip_image_url');

        $fileResponse = $this->actingAs($user, 'sanctum')->get($url);

        $fileResponse->assertStatus(200);
        $this->assertStringContainsString(
            'image/jpeg',
            $fileResponse->headers->get('Content-Type'),
            'ต้อง stream ไฟล์พร้อม Content-Type ที่บันทึกไว้ตอนอัปโหลด'
        );
    }

    public function test_signed_url_serves_slip_image_for_admin(): void
    {
        [$response] = $this->confirmWithSlip();

        $this->actingAsAdmin()
            ->get($response->json('slip_image_url'))
            ->assertStatus(200);
    }

    /**
     * ⚠️ RequireJsonAccept exemption — route นี้ต้องรับ Accept: image/* ได้
     * (frontend fetch รูปด้วย header Accept: image/* — ไม่ exempt แล้วโดน 406)
     */
    public function test_signed_url_accepts_image_accept_header(): void
    {
        [$response, , $user] = $this->confirmWithSlip();

        $fileResponse = $this->actingAs($user, 'sanctum')
            ->get($response->json('slip_image_url'), [
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*',
            ]);

        $fileResponse->assertStatus(200);
    }

    // ============================================
    // 🔒 authenticated แต่ไม่ใช่เจ้าของ → 403
    // ============================================

    public function test_signed_url_denies_other_user(): void
    {
        [$response] = $this->confirmWithSlip();

        $other = User::factory()->create(['role' => 'user']);

        $this->actingAs($other, 'sanctum')
            ->get($response->json('slip_image_url'))
            ->assertStatus(403);
    }

    // ============================================
    // ✍️ signature — แกะ / ไม่มี / หมดอายุ → 403 (เมื่อ login แล้ว)
    // ============================================

    public function test_tampered_signature_is_rejected(): void
    {
        [$response, , $user] = $this->confirmWithSlip();

        $tampered = str_replace(
            'signature=',
            'signature=tampered',
            $response->json('slip_image_url'),
        );

        $this->actingAs($user, 'sanctum')->get($tampered)->assertStatus(403);
    }

    public function test_missing_signature_is_rejected(): void
    {
        [$response, , $user] = $this->confirmWithSlip();

        // ตัด query string ทิ้ง — เข้าเฉพาะ path เปล่าๆ
        $bareUrl = explode('?', $response->json('slip_image_url'))[0];

        $this->actingAs($user, 'sanctum')->get($bareUrl)->assertStatus(403);
    }

    public function test_expired_signed_url_is_rejected(): void
    {
        [$response, , $user] = $this->confirmWithSlip();

        $url = $response->json('slip_image_url');

        // TTL = 15 นาที — เดินทางข้ามเวลาไป 16 นาที ต้องพ้นอายุ
        $this->travel(16)->minutes();

        $this->actingAs($user, 'sanctum')->get($url)->assertStatus(403);
    }

    // ============================================
    // 🙈 $hidden — JSON ของ slip_image ไม่หลุด field ภายใน
    // ============================================

    public function test_slip_image_json_hides_internal_fields(): void
    {
        [$response, $booking, $user] = $this->confirmWithSlip();

        $detail = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->id}");

        $detail->assertStatus(200);

        $slipImage = $detail->json('booking.confirmations.0.slip_image');
        $this->assertNotNull($slipImage, 'booking detail ต้องแนบ confirmations.slip_image');

        foreach (['path', 'disk', 'imageable_id', 'imageable_type', 'uploaded_by', 'metadata'] as $hiddenField) {
            $this->assertArrayNotHasKey(
                $hiddenField,
                $slipImage,
                "slip_image ต้องไม่ expose field ภายใน: {$hiddenField}"
            );
        }

        $this->assertArrayHasKey('id', $slipImage);
        $this->assertArrayHasKey('url', $slipImage, 'url (signed) ต้องยังอยู่ใน JSON');
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

    // ============================================
    // 🛡️ Negative-path tests: 4 สาขา (m12)
    // ============================================

    public function test_signed_url_denies_access_to_org_booking_slip_for_non_admin(): void
    {
        $org = Organization::create(['erp' => 'ORG-IMG-01', 'name' => 'KU Dept']);
        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => null,
            'organization_id' => $org->id,
            'source' => 'admin',
            'status' => 'pending',
            'total_amount' => 5000,
            'payment_deadline' => now()->addHours(24),
        ]);
        $confirmation = $booking->confirmations()->create([
            'transfer_time' => now(),
            'amount' => 5000,
            'status' => 'pending',
        ]);

        $path = 'slips/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'fake image bytes');

        $image = $confirmation->slipImage()->create([
            'path' => $path,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 16,
            'original_name' => 'slip.jpg',
            'category' => 'slip',
        ]);

        $url = URL::temporarySignedRoute('images.file', now()->addMinutes(15), ['image' => $image->id]);

        $user = User::factory()->create(['role' => 'user']);
        $this->actingAs($user, 'sanctum')->get($url)->assertStatus(403);

        // Admin can access
        $this->actingAsAdmin()->get($url)->assertStatus(200);
    }

    public function test_signed_url_denies_unknown_imageable_type_for_non_admin(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $path = 'misc/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'fake image bytes');

        $image = Image::create([
            'path' => $path,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 16,
            'original_name' => 'test.jpg',
            'category' => 'other',
            'imageable_type' => User::class,
            'imageable_id' => $user->id,
            'uploaded_by' => $user->id,
        ]);

        $url = URL::temporarySignedRoute('images.file', now()->addMinutes(15), ['image' => $image->id]);

        $this->actingAs($user, 'sanctum')->get($url)->assertStatus(403);
    }

    public function test_signed_url_denies_staff_from_opening_guest_slip(): void
    {
        [$response] = $this->confirmWithSlip();
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($staff, 'sanctum')
            ->get($response->json('slip_image_url'))
            ->assertStatus(403);
    }

    public function test_signed_url_denies_housekeeper_who_is_not_task_assignee(): void
    {
        $hkAssignee = User::factory()->create(['role' => 'housekeeping']);
        $hkOther = User::factory()->create(['role' => 'housekeeping']);

        $roomType = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard',
            'name_th' => 'สแตนดาร์ด',
            'max_guests' => 2,
        ]);
        $room = Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $roomType->id,
            'room_number' => '301',
            'status' => 'available',
        ]);
        $task = HousekeepingTask::create([
            'id' => Str::uuid(),
            'room_id' => $room->id,
            'assigned_to' => $hkAssignee->id,
            'task_type' => 'daily',
            'status' => 'in_progress',
        ]);

        $path = 'housekeeping/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'fake image bytes');

        $image = Image::create([
            'path' => $path,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 16,
            'original_name' => 'photo.jpg',
            'category' => 'evidence',
            'imageable_type' => HousekeepingTask::class,
            'imageable_id' => $task->id,
            'uploaded_by' => $hkAssignee->id,
        ]);

        $url = URL::temporarySignedRoute('images.file', now()->addMinutes(15), ['image' => $image->id]);

        // Unassigned housekeeper gets 403
        $this->actingAs($hkOther, 'sanctum')->get($url)->assertStatus(403);

        // Assigned housekeeper gets 200
        $this->actingAs($hkAssignee, 'sanctum')->get($url)->assertStatus(200);
    }
}
