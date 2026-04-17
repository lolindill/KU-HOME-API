<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    
    public function index()
    {
        // ใช้ paginate แทน all() เพื่อไม่ให้ดึงข้อมูลหนักเกินไปค่ะ
        $users = User::paginate(15); 
        return response()->json($users);
    }

    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'sometimes|string|in:user,admin',
            'title' => 'nullable|string',
            'phone' => 'nullable|string',
            'nationality' => 'nullable|string',
            'is_ku_member' => 'sometimes|boolean',
        ]);

        $user = User::create($validated);

        return response()->json([
            'message' => 'สร้างผู้ใช้งานสำเร็จค่ะ',
            'user' => $user
        ], 201);
    }

    
    public function show(string $id)
    {
        $user = User::findOrFail($id);
        
        return response()->json($user);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            // ตรวจสอบ email ซ้ำ ยกเว้น email ของตัวเองค่ะ
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|string|min:8',
            'role' => 'sometimes|string|in:user,admin',
            'title' => 'nullable|string',
            'phone' => 'nullable|string',
            'nationality' => 'nullable|string',
            'is_ku_member' => 'sometimes|boolean',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'อัปเดตข้อมูลสำเร็จเรียบร้อยค่ะ',
            'user' => $user
        ]);
    }

    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'message' => 'ลบผู้ใช้งานสำเร็จแล้วค่ะ'
        ]);
    }

    public function me(Request $request)
    {
        // 🪄 ดึงข้อมูลผู้ใช้งานที่กำลังล็อกอินอยู่จาก Token
        $user = $request->user();

        return response()->json([
            'message' => 'success',
            'user' => $user
        ]);
    }

    /**
     * แก้ไขข้อมูลส่วนตัว (Edit Profile) โดยอิงจาก Token
     */
    public function updateProfile(Request $request)
    {
        // 🪄 ดึงข้อมูลผู้ใช้งานที่กำลังล็อกอินอยู่
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            // 🛡️ เช็คอีเมลซ้ำ แต่ยกเว้นอีเมลของตัวเอง
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'sometimes|string|min:8',
            'title' => 'nullable|string',
            'phone' => 'nullable|string',
            'nationality' => 'nullable|string',
        ]);

        // 🔒 ถ้ามีการส่ง password มาให้ทำการ Hash ก่อนบันทึกนะคะ
        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'message' => 'success',
            'user' => $user
        ]);
    }
}