<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;

class UserController extends Controller
{
    public function index()
    {
        $users = User::paginate(15); 
        return response()->json([
            'status' => 'success',
            'message' => 'Users fetched successfully',
            'users' => $users
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $validated = $request->validated();
        $user = User::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'สร้างผู้ใช้เรียบร้อยแล้ว',
            'user' => $user
        ], 201);
    }

    public function show(string $id)
    {
        $user = User::findOrFail($id);
        
        return response()->json([
            'status' => 'success',
            'message' => 'User fetched successfully',
            'user' => $user
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
            'user' => $user
        ]);
    }

    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'ลบผู้ใช้เรียบร้อยแล้ว'
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'User profile fetched successfully',
            'user' => $request->user()
        ]);
    }

    public function updateProfile(UpdateUserRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        // 🛡️ SECURITY: Users cannot change their own role via profile update
        // Role changes must go through admin update (PUT /users/{id})
        unset($validated['role'], $validated['ver']);

        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
    }

    /**
     * Verify user (admin only) - toggle ver status
     */
    public function verify(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'ver' => 'required|boolean',
        ]);

        $user->update(['ver' => $validated['ver']]);

        return response()->json([
            'status' => 'success',
            'message' => $validated['ver'] ? 'ยืนยันตัวตนผู้ใช้สำเร็จ' : 'ยกเลิกการยืนยันตัวตนผู้ใช้สำเร็จ',
            'user' => $user->fresh()
        ]);
    }
}