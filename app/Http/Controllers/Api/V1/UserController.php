<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UploadAvatarRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        // 🎫 split user: email ซ้ำข้าม provider ได้ — โชว์ auth_provider + ให้ admin กรองได้ (ticket 09)
        $provider = $request->query('auth_provider');
        // 🔐 (2026-11-27) กรองสถานะบัญชีได้ ?status=active|inactive
        $status = $request->query('status');

        $users = User::query()
            ->when(
                $provider !== null && $provider !== '',
                fn ($query) => $query->where('auth_provider', $provider)
            )
            ->when(
                $status !== null && $status !== '',
                fn ($query) => $query->where('status', $status)
            )
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'message' => 'Users fetched successfully',
            'users' => $users,
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();
        $user = User::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'สร้างผู้ใช้เรียบร้อยแล้ว',
            'user' => $user,
        ], 201);
    }

    public function show(string $id)
    {
        $user = User::findOrFail($id);

        return response()->json([
            'status' => 'success',
            'message' => 'User fetched successfully',
            'user' => $user,
        ]);
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        $user = User::findOrFail($id);
        $validated = $request->validated();
        $user->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'อัปเดตข้อมูลสำเร็จเรียบร้อยแล้ว',
            'user' => $user,
        ]);
    }

    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'ลบผู้ใช้เรียบร้อยแล้ว',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'User profile fetched successfully',
            'user' => $request->user()->load('avatar'),
        ]);
    }

    public function updateProfile(UpdateUserRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        // 🛡️ SECURITY: Users cannot change their own role via profile update
        // Role changes must go through admin update (PUT /users/{id})
        // (status ไม่อยู่ใน rules/fillable อยู่แล้ว — เปลี่ยนได้ทาง PATCH /users/{id}/status เท่านั้น)
        unset($validated['role']);

        // 🔒 SSO user (ku_sso/google) ห้ามแก้ email — ตัดออกเป็น defense-in-depth
        //    (ชั้นแรกคือ rule 'prohibited' ใน UpdateUserRequest → 422)
        //    email เป็น identity anchor ที่ SSO service ใช้ค้นหา account ตอน login
        if ($user->auth_provider !== 'password') {
            unset($validated['email']);
        }

        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            'user' => $user->fresh(['avatar']),
        ]);
    }

    /**
     * 🖼️ POST /profile/avatar — อัปโหลด/แทนที่รูปโปรไฟล์ (multipart `file`)
     *    1 user มี avatar เดียว — อัปโหลดใหม่ = ลบ row/ไฟล์เก่า (hook ของ Image จัดการ disk)
     *    ดูไฟล์ผ่าน signed URL ของ images.file (เจ้าของ + admin เท่านั้น — ตรวจใน ImageController::show)
     */
    public function uploadAvatar(UploadAvatarRequest $request)
    {
        $user = $request->user();
        $file = $request->file('file');

        $storedPath = null;
        try {
            DB::beginTransaction();

            // 🖼️ เก็บไฟล์บน private disk local (storage/app/private/avatars)
            $storedPath = $file->store('avatars', 'local');

            // 🖼️ ใช้ relation query ตรง (avatar()->first()) ไม่พึ่ง cache บน instance
            //    — กัน stale cache จาก instance ที่ถูกใช้ซ้ำ (เช่น test/long-lived request)
            $oldAvatar = $user->avatar()->first();

            $image = $user->avatar()->create([
                'path' => $storedPath,
                'disk' => 'local',
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'original_name' => $file->getClientOriginalName(),
                'uploaded_by' => $user->id,
                'category' => 'avatar',
            ]);

            // 🖼️ แทนที่ — ลบ row เก่าหลังสร้างใหม่สำเร็จ (hook deleting ลบไฟล์เก่าให้)
            $oldAvatar?->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'อัปเดตรูปโปรไฟล์เรียบร้อยแล้วค่ะนายท่าน ✨',
                'data' => [
                    'id' => $image->id,
                    'file_name' => $image->original_name,
                    'mime_type' => $image->mime_type,
                    'size' => $image->size,
                    'url' => $image->url,
                    'created_at' => $image->created_at?->toISOString(),
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            Log::error('Upload avatar failed: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการอัปโหลดรูปโปรไฟล์ กรุณาลองใหม่อีกครั้งนะคะ 🥺',
            ], 500);
        }
    }

    /**
     * 🖼️ DELETE /profile/avatar — ลบรูปโปรไฟล์ (row + ไฟล์ ผ่าน hook deleting ของ Image)
     */
    public function deleteAvatar(Request $request)
    {
        $avatar = $request->user()->avatar()->first();

        if (! $avatar) {
            return response()->json([
                'status' => 'error',
                'message' => 'ไม่พบรูปโปรไฟล์ให้ลบค่ะนายท่าน',
            ], 404);
        }

        $avatar->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'ลบรูปโปรไฟล์เรียบร้อยแล้วค่ะ',
        ]);
    }

    /**
     * 🔐 PATCH /users/{id}/status — เปิด/ปิดบัญชีผู้ใช้ (admin only) — แทน PUT /users/{id}/verify เดิม (2026-11-27)
     *
     *    inactive → ลบ token ทุกตัวของ user ทันที (kick ออกทุก device) + login ทุกช่องทางตอบ 403
     *    (ชั้นที่สอง: Sanctum token callback ใน AppServiceProvider ปฏิเสธ token ของ user inactive)
     *    🛡️ admin ปิดบัญชีตัวเองไม่ได้ → 422 กันล็อกตัวเองออกจากระบบ
     */
    public function updateStatus(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(User::STATUSES)],
        ]);

        if ($validated['status'] === User::STATUS_INACTIVE && $user->id === $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'ปิดบัญชีของตัวเองไม่ได้ค่ะนายท่าน — ให้ผู้ดูแลระบบคนอื่นเป็นคนปิดนะคะ 🥺',
            ], 422);
        }

        DB::transaction(function () use ($user, $validated) {
            $user->status = $validated['status'];
            $user->save();

            if ($validated['status'] === User::STATUS_INACTIVE) {
                $user->tokens()->delete();
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => $validated['status'] === User::STATUS_INACTIVE
                ? 'ปิดการใช้งานบัญชีผู้ใช้เรียบร้อยแล้วค่ะ'
                : 'เปิดการใช้งานบัญชีผู้ใช้เรียบร้อยแล้วค่ะ',
            'user' => $user->fresh(),
        ]);
    }
}
