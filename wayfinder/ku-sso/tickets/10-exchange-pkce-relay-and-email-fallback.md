# Implement 2 จุดที่ live-verify ชี้ขาด: PKCE code_verifier relay + email fallback chain

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** — *(ปลดครบ 2026-09-08: [ticket 06](./06-register-ku-home-client-on-sso-dev.md) ปิด + live-verify กับ sso-dev จริงสำเร็จ)*
- **assignee:** (ว่าง) ← **session หน้า claim ตรงนี้ก่อนลงมือ**

## Context (handoff จาก live-verify session 2026-09-08)

Implementation `POST /auth/sso/exchange` ปัจจุบัน (branch `feature/ku-sso-login`) **ทดสอบกับ Keycloak จริงไม่ผ่าน** — live-verify session พิสูจน์ด้วย browser login จริง + curl ตรง token endpoint แล้วพบ 2 ช่องว่างที่ต้องแก้ก่อนขึ้น production:

1. **KU enforce PKCE S256 ฝั่ง server** (ขัดคาดเดิมของ ticket 04) — auth request ไม่มี `code_challenge` → 302 กลับ `invalid_request: Missing parameter: code_challenge_method` ทันที · token exchange ไม่มี `code_verifier` → `400 invalid_grant "PKCE verification failed: Code mismatch"` (หลักฐานเต็มใน Resolution ของ ticket 06)
2. **นิสิตไม่มี claim `email`** — live claims ยืนยัน (ticket 02 Amendment 2): บุคลากรมี `email` (ชื่อ claim จริงคือ `email` **ไม่ใช่ `mail` ตามคู่มือ!**) · นิสิตมีเฉพาะ `google-mail` (@ku.th) / `office365-mail` (@live.ku.th) → โค้ดที่อ่านแค่ `claims['email']` จะ 422 นิสิตทั้งกลุ่ม

ของที่ **live-verify ผ่านแล้ว ห้ามทำซ้ำ/สงสัย**: client_id/secret ใช้ได้จริง · redirect_uri wildcard `http://localhost:8000/*` ยอมรับ · server แถม scope `basic openid` → `basic openid profile email` · **ไม่มี refresh_token** มาให้ · userinfo คืน claims ชุดเดียวกับ id_token ทุก field (รวม `sub`) · มี standard claims `name`/`given_name`/`family_name` ให้จริง (nameFromClaims ทำงานถูกแล้ว — **ห้ามแตะ**) · `email_verified` มาเป็น `false` เสมอ (อย่าใช้ตัดสิน)

## Task 1 — `exchange` รับ + relay `code_verifier` (PKCE)

หลักการเดิมยังยืน: **SPA สร้าง code_verifier/code_challenge เอง** (state + PKCE generation ฝั่ง SPA) — backend เปลี่ยนจาก "ไม่ยุ่ง PKCE" เป็น "**relay verifier ตอน exchange**" (amendment ของ [ticket 03](./03-sso-token-and-session-contract.md))

- `app/Http/Controllers/Api/V1/SsoController.php` → `exchange()`:
  - validate เพิ่ม: `'code_verifier' => ['required', 'string', 'min:43', 'max:128', 'regex:/^[A-Za-z0-9\-._~]+$/']` (RFC 7636 §4.1 — verifier 43–128 ตัวอักษร unreserved) · ข้อความ error ไทย+emoji ตาม convention
  - ส่งต่อ: `$sso->exchangeCode($validated['code'], $validated['code_verifier'])`
  - แก้ docblock บรรทัด `state/PKCE validation เป็นหน้าที่ฝั่ง SPA — backend ไม่ยุ่ง` → `state ฝั่ง SPA · PKCE: SPA สร้าง verifier/challenge — backend แค่ relay code_verifier ตอน exchange (live-verify 2026-09-08: KU enforce S256)`
- `app/Services/Sso/KuSsoService.php` → `exchangeCode(string $code, string $codeVerifier)`: เพิ่ม `'code_verifier' => $codeVerifier` ใน form params (คู่กับ `redirect_uri`)
- `config/ku_sso.php` หัวไฟล์ comment บรรทัด `state/PKCE ฝั่ง browser` — อัปเดตให้ตรงความจริงใหม่
- **error map ไม่เปลี่ยน**: PKCE mismatch จาก Keycloak มาเป็น `invalid_grant` → ใช้ 422 message เดิม (`รหัสยืนยันหมดอายุ...`) ต่อได้เลย

## Task 2 — email fallback chain `email → google-mail → office365-mail`

- `app/Services/Sso/KuSsoService.php` → `findOrCreateUser()`: เปลี่ยนบรรทัด `$email = strtolower(trim((string) ($claims['email'] ?? '')));` เป็น chain แบบเดียวกับ `nameFromClaims()`:

```php
$email = '';
foreach (['email', 'google-mail', 'office365-mail'] as $claim) {
    $candidate = strtolower(trim((string) ($claims[$claim] ?? '')));
    if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
        $email = $candidate;
        break;
    }
}
```

- `MissingEmailException` (422 fail-closed) ยังใช้เมื่อ chain ไม่เจอ — คงเป็นเกราะสุดท้าย (decision ticket 02)
- อัปเดต comment `รอ live-verify` → verified แล้ว + อ้าง ticket 02 Amendment 2
- **ห้ามแตะ** `nameFromClaims()` — live-verify ยืนยัน `name` (standard) มีให้ทุกบัญชีแล้ว

## Tests — `tests/Feature/SsoExchangeTest.php`

- `postExchange()` helper: เพิ่ม `'code_verifier' => self::CODE_VERIFIER` (const ยาว 64 ตัวอักษร pattern ถูก) — แก้จุดเดียวครบทุกเคสเดิม
- เคสใหม่:
  - validation: ไม่ส่ง `code_verifier` → 422 `assertJsonValidationErrors(['code_verifier'])` + `Http::assertNothingSent()`
  - relay จริง: `Http::assertSent(fn ($request) => $request['code_verifier'] === self::CODE_VERIFIER)` ที่ token endpoint
  - fallback นิสิต: claims ไม่มี `email` มีแต่ `google-mail` → 200 และ `user.email` = ค่าจาก `google-mail` (lowercase)
  - fallback สุดท้าย: มีเฉพาะ `office365-mail` → 200 ผ่าน
  - เคสเดิม `test_exchange_fails_closed_when_userinfo_has_no_email` ยังผ่านอยู่แล้ว (claims ไม่มี email-ish เลย) — ปรับ comment ให้ตรงความจริงใหม่
- รัน: `php artisan test --filter=SsoExchangeTest` → แล้ว `php artisan test` เต็ม · ปิดท้าย `vendor/bin/pint --dirty`

## หลัง implement — live happy path ผ่าน backend เรา (เป้าหมายปิด ticket)

1. gen PKCE ถูกวิธี (⚠️ **กับดักที่เจอจริง**: openssl ใน Git Bash ปล่อย CRLF — `tr -d '=\n'` ไม่พอ ต้องตัด `\r` ด้วย ไม่งั้นโดน "PKCE verification failed: Code mismatch"):
   ```bash
   V=$(openssl rand -base64 48 | tr '+/' '-_' | tr -d '=\r\n'); C=$(printf %s "$V" | openssl dgst -sha256 -binary | openssl base64 | tr '+/' '-_' | tr -d '=\r\n')
   ```
2. เปิดใน browser (login ด้วย test account จาก `~/Downloads/SSO_Programer_Manual_DEV.docx` — **ห้าม commit credentials**; นิสิต/อาจารย์/บุคลากร อย่างละ 3):
   `https://sso-dev.ku.ac.th/realms/KU-Alllogin/protocol/openid-connect/auth?client_id=bkn-ocs-inf-kuhome&response_type=code&redirect_uri=http%3A%2F%2Flocalhost%3A8000%2Fsso-callback&scope=basic+openid&state=<random>&code_challenge=$C&code_challenge_method=S256`
3. redirect กลับมา `http://localhost:8000/sso-callback?...&code=...` (404 = ปกติ) — **ก๊อบ code ภายใน 60 วิ** แล้วยิงทันที:
   ```bash
   curl -s -X POST http://localhost:8000/api/v1/auth/sso/exchange -H "Accept: application/json" -H "Content-Type: application/json" -d '{"code":"...","code_verifier":"..."}'
   ```
4. expected 200: `access_token` (Sanctum) + `user.role=ku_member` + `user.auth_provider=ku_sso` + `id_token` · ตามด้วย `GET /api/v1/me` (Bearer) = 200 · re-login รอบสอง → user id เดิม · ส่ง code เดิมซ้ำ → 422 · สลับบัญชีต้อง logout KU ก่อน (`GET .../openid-connect/logout` — มีหน้ายืนยัน "Logout") เพราะ SSO cookie auto-login
5. ทดสอบ **ทั้งบัญชีนิสิต (email ต้องมาจาก google-mail) และบุคลากร (มาจาก email)** + throttle 5/นาที ระวังโดน 429 ระหว่างทดสอบ

## สภาพแวดล้อม (ณ จบ session 2026-09-08)

- worktree `.worktree/ku-sso-login` branch `feature/ku-sso-login` · `php artisan serve` รันค้าง port 8000 (โค้ด worktree) · `.env`: `KU_SSO_REDIRECT_URI="http://localhost:8000/sso-callback"` แก้แล้ว (wildcard เดิมใช้ไม่ได้กับ flow จริง — OCS register เป็น wildcard แต่ RFC 6749 ต้องตรงทุก byte ระหว่าง auth/exchange) · migrate เรียบร้อย
- wayfinder docs sync แล้วทั้งสอง branch (agust-11: `45d86b7`+`f08d59b` · branch นี้: cherry-pick `d0c9d7b`+`5ca7dfc`)
- อย่าไปพึ่งไฟล์ PKCE เก่าที่ `/tmp/ku_pkce_*.txt` — สร้างใหม่ทุกครั้ง (code ผูกกับ challenge ต่อ request)

## Definition of done

- [ ] ทั้ง 2 task + tests ใหม่ ผ่านหมด + full suite ผ่าน + pint สะอาด
- [ ] จด design decision ลง `cline.md` ตาม protocol ใน AGENTS.md (สั้นๆ: PKCE relay + email chain + อ้าง ticket)
- [ ] live happy path ผ่าน backend เราจริงทั้ง 2 ประเภทบัญชี (user เกิดใน DB ถูกช่อง + `/me` + re-login + replay 422)
- [ ] เขียน resolution ลง ticket นี้ → `status: closed` + append 1 บรรทัดใน "Decisions so far" ของ [map](../map.md) + commit บน branch นี้
