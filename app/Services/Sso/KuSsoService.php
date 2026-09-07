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
 * 🎫 KU SSO service — hand-rolled Http facade (0 package — decision ticket 05)
 *
 *    ทำ 3 อย่าง: (1) แลก authorization code เป็น KU access_token
 *              (2) ยิง userinfo เอา claims (TLS พอ ไม่ verify JWT เอง)
 *              (3) find-or-create User ด้วย (email, 'ku_sso') — split user model
 *
 *    KU tokens ทิ้งหมดหลังใช้ — ไม่เก็บ DB (decision ticket 03, สอดคล้อง "ไม่เก็บ sub" ของ ticket 02)
 */
class KuSsoService
{
    /**
     * แลก authorization code ที่ได้จาก SPA — code single-use อายุ ~60 วิ ห้าม retry
     *
     * @return array{access_token: string, id_token: ?string}
     *
     * @throws InvalidGrantException code หมดอายุ/ใช้แล้ว → 422
     * @throws InvalidClientException client credentials พัง (config เรา) → 500
     * @throws KuSsoUnavailableException Keycloak ล่ม/timeout/ตอบผิดปกติ → 502
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->httpFormCall('token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('ku_sso.client_id'),
            'client_secret' => config('ku_sso.client_secret'),
            // ⚠️ ต้องตรงกับ authorization request ทุก byte (RFC 6749 §4.1.3) — ค่าเดียวจาก config
            'redirect_uri' => config('ku_sso.redirect_uri'),
        ]);

        // 🔍 OAuth 2.0 error shape: {"error": "...", "error_description": "..."} (research ticket 04 §6)
        $error = (string) $response->json('error');

        // client auth ถูกตรวจ "ก่อน" code — 401 invalid_client = config ฝั่งเราพัง
        if ($response->status() === 401 && $error === 'invalid_client') {
            Log::error('KU SSO: token endpoint ปฏิเสธ client credentials — ตรวจ KU_SSO_CLIENT_ID / KU_SSO_CLIENT_SECRET ใน .env');
            throw new InvalidClientException;
        }

        if ($error === 'invalid_grant') {
            // code หมดอายุ/ใช้แล้ว — retry ไม่มีทางสำเร็จ (single-use) ต้องเริ่ม login ใหม่จาก browser
            throw new InvalidGrantException;
        }

        if ($response->failed()) {
            Log::error('KU SSO: token endpoint ตอบผิดปกติ', [
                'status' => $response->status(),
                'error' => $error,
            ]);
            throw new KuSsoUnavailableException;
        }

        $accessToken = (string) $response->json('access_token');
        if ($accessToken === '') {
            Log::error('KU SSO: token endpoint ไม่คืน access_token', ['status' => $response->status()]);
            throw new KuSsoUnavailableException;
        }

        // 🗑️ id_token ส่งต่อให้ SPA คงไว้เป็น id_token_hint เผื่อ END_SESSION อนาคต (ticket 03) — ไม่ parse
        return [
            'access_token' => $accessToken,
            'id_token' => $response->json('id_token'),
        ];
    }

    /**
     * ดึง claims จาก userinfo endpoint — ตัวตนที่เชื่อถือได้คือ response นี้ (TLS-protected)
     *
     * @return array<string, mixed>
     *
     * @throws KuSsoUnavailableException non-200 (บาง failure คืน body ว่าง — ตัดสินด้วย status เท่านั้น)
     */
    public function fetchUserinfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout((int) config('ku_sso.timeout'))
                ->connectTimeout((int) config('ku_sso.connect_timeout'))
                ->get($this->endpoint('userinfo'));
        } catch (ConnectionException $e) {
            Log::error('KU SSO: เชื่อมต่อ userinfo endpoint ไม่สำเร็จ', ['error' => $e->getMessage()]);
            throw new KuSsoUnavailableException(previous: $e);
        }

        if ($response->failed()) {
            // research ticket 04 §4: 401 อาจมาพร้อม body ว่าง — ห้ามเดาจาก body
            Log::error('KU SSO: userinfo endpoint ปฏิเสธ access_token', ['status' => $response->status()]);
            throw new KuSsoUnavailableException;
        }

        return $response->json() ?? [];
    }

    /**
     * find-or-create User ด้วย (email, 'ku_sso') — split user ไม่มีการ link account (req change 2026-09-01)
     *
     * @throws MissingEmailException userinfo ไม่มี email → fail-closed 422 (decision ticket 02)
     */
    public function findOrCreateUser(array $claims): User
    {
        $email = strtolower(trim((string) ($claims['email'] ?? '')));

        // 📧 fail-closed — ไม่เดา identity จาก preferred_username (amendment ticket 02: รอ live-verify)
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new MissingEmailException;
        }

        $existing = User::where('email', $email)->where('auth_provider', 'ku_sso')->first();
        if ($existing !== null) {
            // re-login — ห้าม overwrite profile ของ user เดิม (decision ticket 02)
            return $existing;
        }

        try {
            return User::create([
                'name' => $this->nameFromClaims($claims) ?? $email,
                'email' => $email,
                'auth_provider' => 'ku_sso',
                'role' => 'ku_member', // first login KU SSO = สมาชิก KU (decision ticket 08)
                // 🔐 password สุ่ม 64 ตัวอักษรทิ้ง — Hash::check ไม่มีทางผ่าน, login เดิมไม่ต้องแตะ (ticket 02)
                'password' => Str::random(64),
            ]);
        } catch (QueryException $e) {
            // 🏁 race: SSO login พร้อมกัน 2 request ด้วย email เดิม — composite unique กัน duplicate ไว้
            //   ตัวที่แพ้ insert กลับมา find แทน (รายละเอียด race ดู AGENTS.md "Multi-Client & Concurrency")
            $sqlState = $e->errorInfo[0] ?? null;
            if (in_array($sqlState, ['23000', '23505'], true)) {
                return User::where('email', $email)->where('auth_provider', 'ku_sso')->firstOrFail();
            }

            throw $e;
        }
    }

    /**
     * ชื่อแสดงผลจาก claims — KU ไม่ใช้ชื่อ claim ตาม OIDC standard (research ticket 04 + คู่มือ OCS)
     * ลอง full-name claims ก่อน (standard แล้วตามด้วยของ KU) ไม่มีค่อยประกอบจาก first/last
     */
    private function nameFromClaims(array $claims): ?string
    {
        foreach (['name', 'thainame', 'cn'] as $claim) {
            $value = trim((string) ($claims[$claim] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $first = trim((string) ($claims['givenname'] ?? $claims['given_name'] ?? $claims['first-name'] ?? ''));
        $last = trim((string) ($claims['surname'] ?? $claims['family_name'] ?? $claims['last-name'] ?? ''));

        $full = trim($first.' '.$last);

        return $full !== '' ? $full : null;
    }

    /**
     * POST form-encoded ไป token endpoint (client_secret_post — Keycloak รองรับ verified)
     *
     * @throws KuSsoUnavailableException network-level failure
     */
    private function httpFormCall(string $name, array $data): Response
    {
        try {
            return Http::asForm()
                ->timeout((int) config('ku_sso.timeout'))
                ->connectTimeout((int) config('ku_sso.connect_timeout'))
                ->post($this->endpoint($name), $data);
        } catch (ConnectionException $e) {
            Log::error('KU SSO: เชื่อมต่อ token endpoint ไม่สำเร็จ', ['error' => $e->getMessage()]);
            throw new KuSsoUnavailableException(previous: $e);
        }
    }

    private function endpoint(string $name): string
    {
        return rtrim((string) config('ku_sso.base_url'), '/').'/protocol/openid-connect/'.$name;
    }
}
