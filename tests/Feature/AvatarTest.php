<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 🖼️ (11/11/26): Avatar (รูปโปรไฟล์) — POST/DELETE /profile/avatar
 *
 * ใช้ images system (polymorphic imageable=User, private disk, signed URL 15 นาที)
 *
 * Flow ที่ทดสอบ:
 *   - อัปโหลด 201 → Image row (category=avatar, imageable=User) + ไฟล์บน private disk
 *   - อัปโหลดซ้ำ → แทนที่ (ลบ row/ไฟล์เก่า เหลือ 1 row เท่านั้น)
 *   - validation: pdf / เกิน 2MB → 422 · ไม่ login → 401
 *   - DELETE → row + ไฟล์ถูกลบ · ไม่มี avatar → 404
 *   - GET /me คืน avatar + signed url (ซ่อน field ภายใน path/disk/imageable_*)
 *   - ownership ผ่าน images.file: เจ้าของ 200 / user อื่น 403 / admin 200
 *   - ลบ user → avatar row + ไฟล์ถูกลบติดไปด้วย (User::deleting hook)
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function uploadAvatar(User $user)
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/avatar', [
                'file' => UploadedFile::fake()->image('avatar.png', 300, 300),
            ]);
    }

    // ============================================
    // ✅ Upload
    // ============================================

    public function test_user_can_upload_avatar(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->uploadAvatar($user);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['status', 'message', 'data' => ['id', 'file_name', 'mime_type', 'size', 'url', 'created_at']]);

        $image = Image::findOrFail($response->json('data.id'));
        $this->assertSame('avatar', $image->category);
        $this->assertSame(User::class, $image->imageable_type);
        $this->assertSame((string) $user->id, (string) $image->imageable_id);
        $this->assertSame((string) $user->id, (string) $image->uploaded_by);
        $this->assertSame('image/png', $image->mime_type);

        // ไฟล์อยู่บน private disk จริง
        Storage::disk('local')->assertExists($image->path);

        // url ที่ได้เป็น signed URL ของ images.file
        $this->assertStringContainsString('/api/v1/images/'.$image->id.'/file', $response->json('data.url'));
    }

    public function test_reupload_replaces_old_avatar(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->uploadAvatar($user)->assertStatus(201);
        $oldPath = $user->fresh()->avatar->path;

        $this->uploadAvatar($user)->assertStatus(201);

        // แทนที่ — เหลือ row เดียว ไฟล์เก่าถูกลบ (hook deleting ของ Image)
        $this->assertSame(1, Image::where('imageable_type', User::class)->where('imageable_id', $user->id)->count());
        Storage::disk('local')->assertMissing($oldPath);

        $newPath = $user->fresh()->avatar->path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_upload_avatar_requires_authentication(): void
    {
        $this->postJson('/api/v1/profile/avatar', [
            'file' => UploadedFile::fake()->image('avatar.png'),
        ])->assertStatus(401);
    }

    public function test_upload_avatar_rejects_non_image_file(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/avatar', [
                'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    public function test_upload_avatar_rejects_file_over_2mb(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/avatar', [
                'file' => UploadedFile::fake()->create('big.png', 3000, 'image/png'), // 3MB > 2MB
            ])
            ->assertStatus(422);
    }

    // ============================================
    // ✅ DELETE
    // ============================================

    public function test_user_can_delete_own_avatar(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->uploadAvatar($user)->assertStatus(201);
        $path = $user->fresh()->avatar->path;

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/profile/avatar');

        $response->assertStatus(200)->assertJsonPath('status', 'success');
        $this->assertDatabaseMissing('images', ['id' => $user->fresh()->avatar?->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_delete_avatar_without_avatar_returns_404(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/profile/avatar')
            ->assertStatus(404);
    }

    // ============================================
    // ✅ GET /me + ownership ผ่าน images.file
    // ============================================

    public function test_me_includes_avatar_with_signed_url(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->uploadAvatar($user)->assertStatus(201);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me');

        $response->assertStatus(200);
        $avatar = $response->json('user.avatar');
        $this->assertNotNull($avatar);
        $this->assertSame('avatar.png', $avatar['original_name']);
        $this->assertStringContainsString('/api/v1/images/'.$avatar['id'].'/file', $avatar['url']);

        // field ภายในถูกซ่อนตาม $hidden ของ Image
        $this->assertArrayNotHasKey('path', $avatar);
        $this->assertArrayNotHasKey('disk', $avatar);
        $this->assertArrayNotHasKey('imageable_id', $avatar);
        $this->assertArrayNotHasKey('uploaded_by', $avatar);
    }

    public function test_me_without_avatar_returns_null_avatar(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')
            ->assertStatus(200)
            ->assertJsonPath('user.avatar', null);
    }

    public function test_avatar_file_ownership_via_images_file(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $upload = $this->uploadAvatar($user)->assertStatus(201);
        $url = $upload->json('data.url');

        // เจ้าของเปิดได้ 200
        $this->actingAs($user, 'sanctum')->get($url)->assertStatus(200);

        // user อื่น (แม้ admin ไม่ใช่) โดน 403
        $other = User::factory()->create(['role' => 'user']);
        $this->actingAs($other, 'sanctum')->get($url)->assertStatus(403);

        // admin เปิดได้ทุกกรณี
        $this->actingAsAdmin()->get($url)->assertStatus(200);
    }

    // ============================================
    // ✅ ลบ user → avatar ถูกลบติดตาม (User::deleting hook)
    // ============================================

    public function test_deleting_user_deletes_avatar_row_and_file(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->uploadAvatar($user)->assertStatus(201);
        $avatarId = $user->fresh()->avatar->id;
        $path = $user->fresh()->avatar->path;

        $user->delete();

        $this->assertDatabaseMissing('images', ['id' => $avatarId]);
        Storage::disk('local')->assertMissing($path);
    }
}
