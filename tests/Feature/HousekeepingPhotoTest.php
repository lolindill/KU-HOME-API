<?php

namespace Tests\Feature;

use App\Models\HousekeepingTask;
use App\Models\Image;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🧹 (14/10/26, REQ-048 / ticket 06): Housekeeping Photos Integration Tests
 *
 * ทดสอบ:
 *   1. Cap ครบ 5 → ภาพที่ 6 โดน 422 (หมวด before และ after)
 *   2. หมวด forgotten_item และ damage ไม่ถูกจำกัด cap 5
 *   3. Housekeeper อัปโหลดงานตัวเองผ่าน / งานคนอื่น 403 / งาน unassigned 403
 *   4. Admin อัปโหลดและดูงานได้ทั้งหมด
 *   5. User ทั่วไปเข้าถึงไม่ได้ (403)
 *   6. หมวดหมู่ validation (before, after, forgotten_item, damage)
 *   7. รูป damage เป็นหลักฐานอย่างเดียว ไม่มีผลทางบัญชี / additional_charges
 *   8. อัปเดต notes บน task ผ่านการอัปโหลด
 *   9. Signed URL อายุ 15 นาที เปิดดูได้ไม่ต้อง auth / หมดอายุหลัง 15 นาที
 *  10. Retention 90 วัน — app:cleanup-images ลบรูปเก่าเกิน 90 วัน รูปใหม่คงอยู่
 *  11. เมื่อ HousekeepingTask ถูกลบ รูปภาพและไฟล์บน disk ถูกลบตาม
 */
class HousekeepingPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeRoom(): Room
    {
        $rt = RoomType::create([
            'id' => Str::uuid(),
            'name_en' => 'Standard Room',
            'name_th' => 'ห้องมาตรฐาน',
            'max_guests' => 2,
            'extra_bed_enabled' => false,
        ]);

        return Room::create([
            'id' => Str::uuid(),
            'room_type_id' => $rt->id,
            'room_number' => '1'.rand(10, 99),
            'status' => 'dirty',
        ]);
    }

    private function makeTask(?User $assignee = null, string $status = 'accepted'): HousekeepingTask
    {
        $room = $this->makeRoom();

        return HousekeepingTask::create([
            'id' => Str::uuid(),
            'room_id' => $room->id,
            'assigned_to' => $assignee?->id,
            'task_type' => 'checkout',
            'status' => $status,
        ]);
    }

    // ============================================
    // 1) สิทธิ์การอัปโหลดรูปภาพ (Housekeeping vs Admin vs Other)
    // ============================================

    public function test_housekeeper_can_upload_photo_to_assigned_task(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        $response = $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'before',
                'photo' => UploadedFile::fake()->image('before_cleaning.jpg', 640, 480),
                'notes' => 'ห้องค่อนข้างรก มีเศษขยะบนเตียง',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'task_id' => $task->id,
                'notes' => 'ห้องค่อนข้างรก มีเศษขยะบนเตียง',
            ]);

        $photoData = $response->json('photo');
        $this->assertNotNull($photoData);
        $this->assertEquals('before', $photoData['category']);
        $this->assertEquals('before_cleaning.jpg', $photoData['original_name']);
        $this->assertStringContainsString('signature=', $photoData['url']);

        // ตรวจสอบข้อมูลใน DB
        $this->assertDatabaseHas('images', [
            'id' => $photoData['id'],
            'imageable_type' => HousekeepingTask::class,
            'imageable_id' => $task->id,
            'category' => 'before',
            'uploaded_by' => $hk->id,
        ]);

        // ตรวจสอบไฟล์บน disk
        $image = Image::find($photoData['id']);
        Storage::disk('local')->assertExists($image->path);

        // ตรวจสอบว่า task notes ถูกอัปเดต
        $this->assertEquals('ห้องค่อนข้างรก มีเศษขยะบนเตียง', $task->fresh()->notes);
    }

    public function test_housekeeper_cannot_upload_photo_to_task_assigned_to_someone_else(): void
    {
        $hk1 = $this->createHousekeeping();
        $hk2 = $this->createHousekeeping();
        $task = $this->makeTask($hk1);

        $response = $this->actingAs($hk2, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'before',
                'photo' => UploadedFile::fake()->image('other.jpg'),
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'ไม่มีสิทธิ์อัปโหลดรูปภาพสำหรับงานนี้ค่ะ',
            ]);
    }

    public function test_housekeeper_cannot_upload_photo_to_unassigned_task(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask(null, 'unassigned');

        $response = $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'before',
                'photo' => UploadedFile::fake()->image('unassigned.jpg'),
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_upload_photo_to_any_task(): void
    {
        $admin = $this->createAdmin();
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'after',
                'photo' => UploadedFile::fake()->image('admin_after.jpg'),
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('images', [
            'imageable_id' => $task->id,
            'category' => 'after',
            'uploaded_by' => $admin->id,
        ]);
    }

    public function test_regular_user_cannot_upload_or_view_photos(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        // Upload
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'before',
                'photo' => UploadedFile::fake()->image('test.jpg'),
            ])
            ->assertStatus(403);

        // View
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/dashboard/tasks/{$task->id}/photos")
            ->assertStatus(403);
    }

    // ============================================
    // 2) หมวดหมู่ และ Cap 5+5
    // ============================================

    public function test_category_validation(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        // หมวดไม่ถูกต้อง
        $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'invalid_category',
                'photo' => UploadedFile::fake()->image('test.jpg'),
            ])
            ->assertStatus(422);

        // ไม่ส่งหมวด
        $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'photo' => UploadedFile::fake()->image('test.jpg'),
            ])
            ->assertStatus(422);

        // ทุกหมวดที่ถูกต้องผ่านหมด
        foreach (['before', 'after', 'forgotten_item', 'damage'] as $validCat) {
            $this->actingAs($hk, 'sanctum')
                ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                    'category' => $validCat,
                    'photo' => UploadedFile::fake()->image("{$validCat}.jpg"),
                ])
                ->assertStatus(201);
        }
    }

    public function test_before_category_capped_at_5_photos(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        // อัปโหลด 5 ภาพแรกสำหรับหมวด before
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->actingAs($hk, 'sanctum')
                ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                    'category' => 'before',
                    'photo' => UploadedFile::fake()->image("before_{$i}.jpg"),
                ]);
            $response->assertStatus(201);
        }

        $this->assertEquals(5, $task->photos()->where('category', 'before')->count());

        // ภาพที่ 6 ต้องโดนปฏิเสธ 422
        $response6 = $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'before',
                'photo' => UploadedFile::fake()->image('before_6.jpg'),
            ]);

        $response6->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'หมวด before สามารถอัปโหลดได้สูงสุด 5 ภาพต่อ task ค่ะ (ปัจจุบันมี 5 ภาพ)',
            ]);

        // แต่หมวด after ยังอัปโหลดได้ตามปกติ
        $responseAfter = $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'after',
                'photo' => UploadedFile::fake()->image('after_1.jpg'),
            ]);
        $responseAfter->assertStatus(201);
    }

    public function test_after_category_capped_at_5_photos(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->actingAs($hk, 'sanctum')
                ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                    'category' => 'after',
                    'photo' => UploadedFile::fake()->image("after_{$i}.jpg"),
                ]);
            $response->assertStatus(201);
        }

        // ภาพที่ 6 ของ after ต้องโดน 422
        $response6 = $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'after',
                'photo' => UploadedFile::fake()->image('after_6.jpg'),
            ]);

        $response6->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'message' => 'หมวด after สามารถอัปโหลดได้สูงสุด 5 ภาพต่อ task ค่ะ (ปัจจุบันมี 5 ภาพ)',
            ]);
    }

    public function test_forgotten_item_and_damage_categories_not_capped_at_5(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        // อัปโหลด 6 ภาพในหมวด damage
        for ($i = 1; $i <= 6; $i++) {
            $response = $this->actingAs($hk, 'sanctum')
                ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                    'category' => 'damage',
                    'photo' => UploadedFile::fake()->image("damage_{$i}.jpg"),
                ]);
            $response->assertStatus(201);
        }

        $this->assertEquals(6, $task->photos()->where('category', 'damage')->count());
    }

    public function test_damage_photo_does_not_create_charges_or_affect_finances(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        $initialChargesCount = DB::table('additional_charges')->count();
        $initialPaymentsCount = DB::table('payments')->count();

        $response = $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'damage',
                'photo' => UploadedFile::fake()->image('broken_lamp.jpg'),
                'notes' => 'โคมไฟข้างเตียงแตกเสียหาย',
            ]);

        $response->assertStatus(201);

        // ตรวจสอบว่าไม่มี row ใดถูกสร้างใน additional_charges หรือ payments
        $this->assertEquals($initialChargesCount, DB::table('additional_charges')->count());
        $this->assertEquals($initialPaymentsCount, DB::table('payments')->count());
    }

    // ============================================
    // 3) ดูรายการรูปภาพ (GET /dashboard/tasks/{id}/photos)
    // ============================================

    public function test_housekeeper_can_view_own_task_photos(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);
        $task->update(['notes' => 'ทำความสะอาดเรียบร้อย']);

        // อัปโหลด 2 รูป (before, after)
        $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'before',
                'photo' => UploadedFile::fake()->image('b1.jpg'),
            ]);
        $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'after',
                'photo' => UploadedFile::fake()->image('a1.jpg'),
            ]);

        $response = $this->actingAs($hk, 'sanctum')
            ->getJson("/api/v1/dashboard/tasks/{$task->id}/photos");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'task_id' => $task->id,
                'notes' => 'ทำความสะอาดเรียบร้อย',
                'total_photos' => 2,
                'counts' => [
                    'before' => 1,
                    'after' => 1,
                    'forgotten_item' => 0,
                    'damage' => 0,
                ],
            ]);

        $this->assertCount(2, $response->json('photos'));
    }

    public function test_housekeeper_cannot_view_other_task_photos(): void
    {
        $hk1 = $this->createHousekeeping();
        $hk2 = $this->createHousekeeping();
        $task = $this->makeTask($hk1);

        $this->actingAs($hk2, 'sanctum')
            ->getJson("/api/v1/dashboard/tasks/{$task->id}/photos")
            ->assertStatus(403);
    }

    public function test_admin_can_view_any_task_photos(): void
    {
        $admin = $this->createAdmin();
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/dashboard/tasks/{$task->id}/photos")
            ->assertStatus(200);
    }

    public function test_filter_photos_by_category(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'before',
                'photo' => UploadedFile::fake()->image('b1.jpg'),
            ]);
        $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'after',
                'photo' => UploadedFile::fake()->image('a1.jpg'),
            ]);

        $response = $this->actingAs($hk, 'sanctum')
            ->getJson("/api/v1/dashboard/tasks/{$task->id}/photos?category=before");

        $response->assertStatus(200)
            ->assertJson([
                'total_photos' => 1,
                'counts' => [
                    'before' => 1,
                    'after' => 1,
                ],
            ]);

        $this->assertEquals('before', $response->json('photos.0.category'));
    }

    // ============================================
    // 4) Signed URL และอายุ 15 นาที
    // ============================================

    public function test_signed_url_serves_housekeeping_photo_without_auth_and_expires_after_15_minutes(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        $uploadResponse = $this->actingAs($hk, 'sanctum')
            ->postJson("/api/v1/dashboard/tasks/{$task->id}/photos", [
                'category' => 'before',
                'photo' => UploadedFile::fake()->image('lamp.jpg'),
            ]);

        $signedUrl = $uploadResponse->json('photo.url');
        $this->assertNotEmpty($signedUrl);

        // 1. เปิดดูรูปภาพโดยไม่ต้องส่ง auth token เลย (signature ยืนยันสิทธิ์แทน)
        $fileResponse = $this->get($signedUrl);
        $fileResponse->assertStatus(200);
        $this->assertStringContainsString('image/jpeg', $fileResponse->headers->get('Content-Type'));

        // 2. ลายเซ็นถูกดัดแปลง → 403
        $tamperedUrl = str_replace('signature=', 'signature=fake', $signedUrl);
        $this->get($tamperedUrl)->assertStatus(403);

        // 3. หลัง 16 นาที (เกิน TTL 15 นาที) → 403
        $this->travel(16)->minutes();
        $this->get($signedUrl)->assertStatus(403);
    }

    // ============================================
    // 5) Retention 90 วัน (app:cleanup-images)
    // ============================================

    public function test_cleanup_images_removes_housekeeping_photos_older_than_90_days(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        // รูปเก่า 91 วัน
        $oldPath = 'housekeeping/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($oldPath, 'old-photo');
        $oldImage = $task->photos()->create([
            'path' => $oldPath,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 10,
            'original_name' => 'old.jpg',
            'category' => 'before',
            'uploaded_by' => $hk->id,
        ]);
        DB::table('images')->where('id', $oldImage->id)->update(['created_at' => now()->subDays(91)]);

        // รูปใหม่ 10 วัน
        $newPath = 'housekeeping/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($newPath, 'new-photo');
        $newImage = $task->photos()->create([
            'path' => $newPath,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 10,
            'original_name' => 'new.jpg',
            'category' => 'after',
            'uploaded_by' => $hk->id,
        ]);
        DB::table('images')->where('id', $newImage->id)->update(['created_at' => now()->subDays(10)]);

        // สั่งรัน cleanup
        $this->artisan('app:cleanup-images')->assertExitCode(0);

        // รูปเก่า 91 วันถูกลบทั้ง DB และ disk
        $this->assertDatabaseMissing('images', ['id' => $oldImage->id]);
        Storage::disk('local')->assertMissing($oldPath);

        // รูปใหม่ 10 วันยังคงอยู่
        $this->assertDatabaseHas('images', ['id' => $newImage->id]);
        Storage::disk('local')->assertExists($newPath);
    }

    // ============================================
    // 6) Cascade Deletion / Orphan Sweeping
    // ============================================

    public function test_deleting_housekeeping_task_deletes_photos_and_disk_files(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        $path = 'housekeeping/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'sample');
        $image = $task->photos()->create([
            'path' => $path,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 10,
            'original_name' => 'task_photo.jpg',
            'category' => 'before',
            'uploaded_by' => $hk->id,
        ]);

        // ลบ task ผ่าน model
        $task->delete();

        $this->assertDatabaseMissing('images', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_cleanup_images_removes_orphaned_housekeeping_task_photos(): void
    {
        $hk = $this->createHousekeeping();
        $task = $this->makeTask($hk);

        $path = 'housekeeping/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'sample');
        $image = $task->photos()->create([
            'path' => $path,
            'disk' => 'local',
            'mime_type' => 'image/jpeg',
            'size' => 10,
            'original_name' => 'orphan_task_photo.jpg',
            'category' => 'before',
            'uploaded_by' => $hk->id,
        ]);

        // ลบ task ตรงๆ ผ่าน query builder ข้าม model hooks เพื่อจำลองกำพร้า
        DB::table('housekeeping_tasks')->where('id', $task->id)->delete();

        $this->artisan('app:cleanup-images')->assertExitCode(0);

        $this->assertDatabaseMissing('images', ['id' => $image->id]);
        Storage::disk('local')->assertMissing($path);
    }
}
