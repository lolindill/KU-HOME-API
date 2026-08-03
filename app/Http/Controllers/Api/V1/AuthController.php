<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
use Throwable;

class AuthController extends Controller
{
    // 📝 สมัครสมาชิก
    public function register(StoreUserRequest $request)
    {
        // 📌 validation ถูกจัดการที่ StoreUserRequest
        // หากไม่ผ่าน → Laravel คืน 422 อัตโนมัติ (มี errors[] แยกฟิลด์)
        $validated = $request->validated();

        try {
            // 🛡️ SECURITY: Only pick safe fields — never trust client with role/ver
            // role defaults to 'user' via DB column default
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => $validated['password'],
            ]);

            $token = $user->createToken('ku_home_auth_token')->plainTextToken;
        } catch (QueryException $e) {
            // 🚨 DB error — เช่น unique constraint (email ซ้ำ) ผ่าน race condition
            $sqlState = $e->errorInfo[0] ?? null;
            Log::error('Register DB error', [
                'email' => $validated['email'] ?? null,
                'sql_state' => $sqlState,
                'message' => $e->getMessage(),
            ]);

            // unique violation / integrity constraint violation → 409
            if (in_array($sqlState, ['23000', '23505'], true)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'อีเมลนี้ถูกใช้งานแล้วค่ะ',
                ], 409);
            }

            // DB error อื่นๆ → 500 (ไม่ leak)
            return response()->json([
                'status'  => 'error',
                'message' => 'เกิดข้อผิดพลาดในระบบฐานข้อมูล กรุณาลองใหม่อีกครั้งค่ะ',
            ], 500);
        } catch (Throwable $e) {
            // 🚨 error อื่นๆ ที่ไม่ใช่ DB → 500 generic (ไม่ leak getMessage)
            Log::error('Register unexpected error', [
                'email' => $validated['email'] ?? null,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'เกิดข้อผิดพลาดในการสมัครสมาชิก กรุณาลองใหม่อีกครั้งค่ะ',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Registration successful',
            'access_token' => $token,
            'token_type' => 'Bearer'
        ], 201);
    }

    // 🔑 เข้าสู่ระบบ
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้องค่ะ'
            ], 401);
        }
        
        $token = $user->createToken('ku_home_auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer'
        ], 200);
    }

    // 🚪 ออกจากระบบ
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logout successful',
        ], 200);
    }
}