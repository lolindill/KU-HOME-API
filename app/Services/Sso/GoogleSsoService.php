<?php

namespace App\Services\Sso;

use App\Models\User;
use App\Services\Sso\Exceptions\InvalidClientException;
use App\Services\Sso\Exceptions\InvalidGrantException;
use App\Services\Sso\Exceptions\KuSsoUnavailableException;
use App\Services\Sso\Exceptions\MissingEmailException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 🎫 Google SSO service — sibling คู่ขนานกับ KuSsoService (0 package — decision ticket 02)
 *
 *    ทำ 3 อย่าง: (1) แลก authorization code เป็น Google access_token
 *              (2) ยิง userinfo เอา claims (TLS พอ ไม่ verify JWT เอง — เหมือน KU SSO)
 *              (3) find-or-create User ด้วย (email, 'google') — split user model
 *
 *    Google access token ใช้ยิง userinfo รอบเดียวแล้วทิ้ง — ไม่ขอ offline access
 *    จึงไม่มี refresh token / ไม่โดน gotcha อายุ 7 วันของ Testing mode (research ticket 01 §7)
 *
 *    Exception reuse 4 ตัวเดิมของ KuSsoService ตาม contract sign-off (ticket 06 ข้อ 7) —
 *    KuSsoException base อ่านว่า "SSO flow base" ไม่ใช่เฉพาะ Keycloak
 */
class GoogleSsoService
{
    /**
     * แลก authorization code ที่ได้จาก SPA — code single-use ห้าม retry (replay = invalid_grant)
     *
     * @return array{access_token: string, id_token: ?string}
     *
     * @throws InvalidGrantException code หมดอายุ/ใช้แล้ว → 422
     * @throws InvalidClientException client credentials พัง (config เรา) → 500
     * @throws KuSsoUnavailableException Google ล่ม/timeout/ตอบผิดปกติ → 502
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $response = $this->httpFormCall([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('google_sso.client_id'),
            'client_secret' => config('google_sso.client_secret'),
            // ⚠️ ต้องตรงกับ authorization request ทุก byte (RFC 6749 §4.1.3) — ค่าเดียวจาก config
            'redirect_uri' => config('google_sso.redirect_uri'),
            // 🔑 PKCE S256 — Google รองรับไม่ enforce สำหรับ web client แต่ส่งเสมอเป็น hardening (research ticket 01 §2)
            'code_verifier' => $codeVerifier,
        ]);

        // 🔍 OAuth 2.0 error shape: {"error": "...", "error_description": "..."} (research ticket 01 §6)
        $error = (string) $response->json('error');

        // client auth ถูกตรวจ "ก่อน" code — 401 invalid_client = config ฝั่งเราพัง
        if ($response->status() === 401 && $error === 'invalid_client') {
            Log::error('Google SSO: token endpoint ปฏิเสธ client credentials — ตรวจ GOOGLE_SSO_CLIENT_ID / GOOGLE_SSO_CLIENT_SECRET ใน .env');
            throw new InvalidClientException;
        }

        // redirect_uri ที่ token endpoint ไม่ตรงกับ authorize = config ไม่ตรงกันระหว่าง SPA/API → 500 ไม่ใช่ความผิด user
        if ($error === 'redirect_uri_mismatch') {
            Log::error('Google SSO: redirect_uri_mismatch ที่ token endpoint — GOOGLE_SSO_REDIRECT_URI ไม่ตรงกับ Authorized redirect URI ใน Cloud Console');
            throw new InvalidClientException;
        }

        if ($error === 'invalid_grant') {
            // code หมดอายุ/ใช้แล้ว — retry ไม่มีทางสำเร็จ (single-use) ต้องเริ่ม login ใหม่จาก browser
            throw new InvalidGrantException;
        }

        if ($response->failed()) {
            Log::error('Google SSO: token endpoint ตอบผิดปกติ', [
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new KuSsoUnavailableException;
        }

        $accessToken = (string) $response->json('access_token');
        if ($accessToken === '') {
            Log::error('Google SSO: token endpoint ไม่คืน access_token', ['status' => $response->status()]);
            throw new KuSsoUnavailableException;
        }

        // 🔖 id_token ส่งต่อให้ SPA ตาม contract 6 keys (ticket 06 ข้อ 4) — flow ไม่ parse (identity มาจาก userinfo)
        return [
            'access_token' => $accessToken,
            'id_token' => $response->json('id_token'),
        ];
    }

    /**
     * ดึง claims จาก userinfo endpoint — ตัวตนที่เชื่อถือได้คือ response นี้ (TLS-protected)
     * `sub` มีเสมอตาม OIDC Core §5.3.2 — claim อื่น nullable ได้ทั้งหมด (research ticket 01 §3)
     *
     * @return array<string, mixed>
     *
     * @throws KuSsoUnavailableException non-200 (ตัดสินด้วย HTTP status เท่านั้น)
     */
    public function fetchUserinfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('google_sso.timeout'))
                ->connectTimeout((int) config('google_sso.connect_timeout'))
                ->get((string) config('google_sso.userinfo_endpoint'));
        } catch (ConnectionException $e) {
            Log::error('Google SSO: เชื่อมต่อ userinfo endpoint ไม่สำเร็จ', ['error' => $e->getMessage()]);
            throw new KuSsoUnavailableException(previous: $e);
        }

        if ($response->failed()) {
            Log::error('Google SSO: userinfo endpoint ปฏิเสธ access_token', ['status' => $response->status()]);
            throw new KuSsoUnavailableException;
        }

        return $response->json() ?? [];
    }

    /**
     * find-or-create User ด้วย (email, 'google') — split user ไม่มีการ link account (req change 2026-09-01)
     *
     * @throws MissingEmailException ไม่มี email หรือ email_verified !== true → fail-closed 422 (contract ticket 06 ข้อ 5)
     */
    public function findOrCreateUser(array $claims): User
    {
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // 📧 fail-closed — Google เป็น public identity ยืนยันอีเมลได้จริง ต้อง verified เท่านั้น
        //   (ต่างจาก KU SSO ที่ Keycloak คืน false เสมอจึงใช้ chain — contract ticket 06 ข้อ 5)
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! $emailVerified) {
            throw new MissingEmailException;
        }

        $existing = User::where('email', $email)->where('auth_provider', 'google')->first();
        if ($existing !== null) {
            // re-login — ห้าม overwrite profile ของ user เดิม (decision ticket 02)
            return $existing;
        }

        try {
            return User::create([
                'name' => $this->nameFromClaims($claims) ?? $email,
                'email' => $email,
                'auth_provider' => 'google',
                // first login Google = user ทั่วไป — Google ไม่พิสูจน์สถานะ มก. ป้องกันส่วนลด daily_ku หลุด
                // (ต่างจาก KU SSO ที่ได้ ku_member — contract sign-off ticket 06 ข้อ 1)
                'role' => 'user',
                // 🔐 password สุ่ม 64 ตัวอักษรทิ้ง — Hash::check ไม่มีทางผ่าน, password login ของ email นี้ไม่เกี่ยว (split-user)
                'password' => Str::random(64),
            ]);
        } catch (QueryException $e) {
            // 🏁 race: SSO login พร้อมกัน 2 request ด้วย email เดิม — composite unique กัน duplicate ไว้
            //   ตัวที่แพ้ insert กลับมา find แทน (เหมือน KuSsoService)
            $sqlState = $e->errorInfo[0] ?? null;
            if (in_array($sqlState, ['23000', '23505'], true)) {
                return User::where('email', $email)->where('auth_provider', 'google')->firstOrFail();
            }

            throw $e;
        }
    }

    /**
     * ชื่อแสดงผลจาก claims — Google ใช้ OIDC standard claims (research ticket 01 §3)
     * ทุก claim ยกเว้น `sub` nullable ได้ → คืน null ได้ (controller ใช้ email แทน)
     */
    private function nameFromClaims(array $claims): ?string
    {
        $name = trim((string) ($claims['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $first = trim((string) ($claims['given_name'] ?? ''));
        $last = trim((string) ($claims['family_name'] ?? ''));

        $full = trim($first.' '.$last);

        return $full !== '' ? $full : null;
    }

    /**
     * POST form-encoded ไป token endpoint (client_secret_post — discovery ยืนยันรองรับ, research ticket 01 §1)
     *
     * @throws KuSsoUnavailableException network-level failure
     */
    private function httpFormCall(array $data): Response
    {
        try {
            return Http::asForm()
                ->timeout((int) config('google_sso.timeout'))
                ->connectTimeout((int) config('google_sso.connect_timeout'))
                ->post((string) config('google_sso.token_endpoint'), $data);
        } catch (ConnectionException $e) {
            Log::error('Google SSO: เชื่อมต่อ token endpoint ไม่สำเร็จ', ['error' => $e->getMessage()]);
            throw new KuSsoUnavailableException(previous: $e);
        }
    }
}
