<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sso\Exceptions\InvalidClientException;
use App\Services\Sso\Exceptions\InvalidGrantException;
use App\Services\Sso\Exceptions\KuSsoUnavailableException;
use App\Services\Sso\Exceptions\MissingEmailException;
use App\Services\Sso\GoogleSsoService;
use App\Services\Sso\KuSsoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SsoController extends Controller
{
    /**
     * 🎫 POST /auth/sso/exchange — SPA ส่ง authorization code มาแลก Sanctum token
     *
     * Flow + error map ตาม wayfinder/ku-sso ticket 03 (owner sign-off 2026-09-07):
     * invalid_grant→422 · ไม่มี email→422 · invalid_client→500 · Keycloak ล่ม→502
     * state ฝั่ง SPA · PKCE: SPA สร้าง verifier/challenge — backend แค่ relay code_verifier ตอน exchange
     * (live-verify 2026-09-08: KU enforce S256 — wayfinder ticket 10)
     */
    public function exchange(Request $request, KuSsoService $sso)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            // RFC 7636 §4.1 — verifier ยาว 43–128 ตัวอักษร unreserved [A-Za-z0-9-._~]
            'code_verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9\-._~]+$/'],
        ], [
            'code_verifier.required' => 'กรุณาส่ง code_verifier จากฝั่ง SPA มาด้วยค่ะ 🔑',
            'code_verifier.min' => 'code_verifier สั้นเกินไป ต้องยาวอย่างน้อย 43 ตัวอักษรค่ะ 🔑',
            'code_verifier.max' => 'code_verifier ยาวเกินไป ต้องไม่เกิน 128 ตัวอักษรค่ะ 🔑',
            'code_verifier.regex' => 'code_verifier มีตัวอักษรที่ไม่อนุญาต ใช้ได้เฉพาะ A-Z a-z 0-9 - . _ ~ เท่านั้นค่ะ 🔑',
        ]);

        try {
            $tokens = $sso->exchangeCode($validated['code'], $validated['code_verifier']);
            $claims = $sso->fetchUserinfo($tokens['access_token']); // 🗑️ ใช้ครั้งเดียวแล้วทิ้ง
            $user = $sso->findOrCreateUser($claims);
        } catch (InvalidGrantException $e) {
            // code single-use — ห้าม retry, SPA ต้องเริ่ม login flow ใหม่
            return response()->json([
                'status' => 'error',
                'message' => 'รหัสยืนยันหมดอายุหรือถูกใช้งานแล้ว กรุณาเข้าสู่ระบบผ่าน KU SSO อีกครั้งค่ะ 🔄',
            ], 422);
        } catch (MissingEmailException $e) {
            // fail-closed (ticket 02) — ไม่เดา identity จาก claim อื่น
            return response()->json([
                'status' => 'error',
                'message' => 'บัญชี KU ของท่านไม่ส่งข้อมูลอีเมลกลับมา จึงเข้าสู่ระบบไม่ได้ในขณะนี้ค่ะ 📧',
            ], 422);
        } catch (InvalidClientException $e) {
            // config ฝั่งเราพัง — service log รายละเอียดไว้แล้ว ห้าม expose ออกไป
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการยืนยันตัวตนกับระบบกลางมหาวิทยาลัย กรุณาแจ้งผู้ดูแลระบบค่ะ',
            ], 500);
        } catch (KuSsoUnavailableException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'ระบบ KU SSO ของมหาวิทยาลัยขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้งค่ะ 🛰️',
            ], 502);
        } catch (Throwable $e) {
            // 🚨 error ที่ไม่คาดคิด → 500 generic ไม่ leak (ตาม convention ของ repo)
            Log::error('KU SSO exchange: unexpected error', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ กรุณาลองใหม่อีกครั้งค่ะ',
            ], 500);
        }

        // Sanctum นโยบายเดียวกับ password login ทุกประการ — token ใหม่ ไม่ revoke ของเดิม (ticket 03)
        $token = $user->createToken('ku_home_auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'KU SSO login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
            // 🔖 คืนตาม contract ticket 03 — SPA เก็บไว้ใช้เป็น id_token_hint ตอน END_SESSION อนาคต (ไม่ parse)
            'id_token' => $tokens['id_token'],
        ], 200);
    }

    /**
     * 🎫 POST /auth/sso/google/exchange — SPA ส่ง Google authorization code มาแลก Sanctum token
     *
     * Mirror ของ exchange() ตาม wayfinder/google-integration ticket 06 (owner sign-off 2026-10-06):
     * invalid_grant→422 · email ไม่ผ่าน/ไม่ verified→422 · invalid_client/redirect_uri_mismatch→500
     * · Google ล่ม→502 · role ของ Google-born user = `user` (ไม่ใช่ ku_member — ข้อ 1)
     * state + PKCE ฝั่ง SPA สร้างเอง — backend แค่ relay code_verifier (Google ไม่ enforce แต่ส่ง S256 hardening)
     */
    public function exchangeGoogle(Request $request, GoogleSsoService $sso)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            // RFC 7636 §4.1 — verifier ยาว 43–128 ตัวอักษร unreserved [A-Za-z0-9-._~]
            'code_verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9\-._~]+$/'],
        ], [
            'code_verifier.required' => 'กรุณาส่ง code_verifier จากฝั่ง SPA มาด้วยค่ะ 🔑',
            'code_verifier.min' => 'code_verifier สั้นเกินไป ต้องยาวอย่างน้อย 43 ตัวอักษรค่ะ 🔑',
            'code_verifier.max' => 'code_verifier ยาวเกินไป ต้องไม่เกิน 128 ตัวอักษรค่ะ 🔑',
            'code_verifier.regex' => 'code_verifier มีตัวอักษรที่ไม่อนุญาต ใช้ได้เฉพาะ A-Z a-z 0-9 - . _ ~ เท่านั้นค่ะ 🔑',
        ]);

        try {
            $tokens = $sso->exchangeCode($validated['code'], $validated['code_verifier']);
            $claims = $sso->fetchUserinfo($tokens['access_token']); // 🗑️ ใช้ครั้งเดียวแล้วทิ้ง
            $user = $sso->findOrCreateUser($claims);
        } catch (InvalidGrantException $e) {
            // code single-use — ห้าม retry, SPA ต้องเริ่ม login flow ใหม่
            return response()->json([
                'status' => 'error',
                'message' => 'รหัสยืนยันหมดอายุหรือถูกใช้งานแล้ว กรุณาเข้าสู่ระบบผ่าน Google อีกครั้งค่ะ 🔄',
            ], 422);
        } catch (MissingEmailException $e) {
            // fail-closed (contract ticket 06 ข้อ 5) — Google ยืนยันอีเมลได้จริง ต้อง verified เท่านั้น
            return response()->json([
                'status' => 'error',
                'message' => 'บัญชี Google ไม่ได้รับการยืนยันอีเมล หรือไม่ส่งข้อมูลอีเมลกลับมา จึงเข้าสู่ระบบไม่ได้ค่ะ 📧',
            ], 422);
        } catch (InvalidClientException $e) {
            // config ฝั่งเราพัง (รวม redirect_uri_mismatch) — service log รายละเอียดไว้แล้ว ห้าม expose
            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการยืนยันตัวตนกับผู้ให้บริการ กรุณาแจ้งผู้ดูแลระบบค่ะ',
            ], 500);
        } catch (KuSsoUnavailableException $e) {
            // 🛸 exception reuse ตาม contract ticket 06 ข้อ 7 — ที่นี่คือ "Google ล่ม" ไม่ใช่ KU
            return response()->json([
                'status' => 'error',
                'message' => 'ระบบ Google Authentication ขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้งค่ะ 🛰️',
            ], 502);
        } catch (Throwable $e) {
            // 🚨 error ที่ไม่คาดคิด → 500 generic ไม่ leak (ตาม convention ของ repo)
            Log::error('Google SSO exchange: unexpected error', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ กรุณาลองใหม่อีกครั้งค่ะ',
            ], 500);
        }

        // Sanctum นโยบายเดียวกับ password login ทุกประการ — token ใหม่ ไม่ revoke ของเดิม (ticket 06 ข้อ 7)
        $token = $user->createToken('ku_home_auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Google login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
            // 🔖 คืนครบ 6 keys mirror KU SSO (ticket 06 ข้อ 4) — Google ไม่มี END_SESSION แต่คง shape เดียวกัน
            'id_token' => $tokens['id_token'],
        ], 200);
    }
}
