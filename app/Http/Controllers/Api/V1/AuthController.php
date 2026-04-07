<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str; // 👈 เพิ่มสิ่งนี้เข้ามาเพื่อช่วยสร้าง UUID ค่ะ

class AuthController extends Controller
{
    // 📝 1. สมัครสมาชิก (Register)
    public function register(Request $request)
    {
        
        // ตรวจสอบความถูกต้องของข้อมูลที่ส่งมา
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8', // ถ้าอยากให้ยืนยันรหัสผ่านด้วย เติม '|confirmed' ได้นะคะ
        ]);

        // สร้างข้อมูล User ใหม่ลงฐานข้อมูล
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // ตรงนี้ปล่อยผ่านได้เลยค่ะ เพราะ Model ของนายท่านมี 'password' => 'hashed' คอยเข้ารหัสให้อัตโนมัติแล้ว เก่งสุดๆ!
        ]);

        // สร้าง Token ให้เลยหลังสมัครเสร็จ จะได้ไม่ต้องไปยิง Login ซ้ำค่ะ
        $token = $user->createToken('ku_home_auth_token')->plainTextToken;

        return response()->json([
            'message' => 'ลงทะเบียนสำเร็จแล้วค่ะนายท่าน! 🎉',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ], 201);
    }

    // 🔑 2. เข้าสู่ระบบ (Login)
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'error' => 'อีเมลหรือรหัสผ่านของนายท่านไม่ถูกต้องค่ะ 🥺'
            ], 401);
        }
        
        $token = $user->createToken('ku_home_auth_token')->plainTextToken;

        return response()->json([
            'message' => 'ยินดีต้อนรับกลับมาค่ะนายท่าน! 🎉',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ], 200);
    }

    // 🚪 3. ออกจากระบบ (Logout)
    public function logout(Request $request)
    {
        // ทำลาย Token ปัจจุบันที่นายท่านใช้ Login เข้ามาค่ะ
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'ออกจากระบบเรียบร้อยแล้วค่ะ พักผ่อนเยอะๆ นะคะนายท่าน! 🛏️💤'
        ], 200);
    }
}