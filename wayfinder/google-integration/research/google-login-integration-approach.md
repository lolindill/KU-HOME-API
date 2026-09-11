# Research: Integration approach — Google login on top of the existing SSO implementation

- **Ticket:** `wayfinder/google-integration/tickets/02-google-login-integration-approach.md`
- **Date:** 2026-09-11
- **Status:** RESOLVED — **(a) sibling `GoogleSsoService` ชนะ** (duplicate แบบชัด ไม่ generalize) + **endpoint แยก `POST /api/v1/auth/sso/google/exchange`** (ไม่รวม provider-param บน route เดิม)
- **Scope checked (read-only):** worktree `.worktree/ku-sso-login` (branch `feature/ku-sso-login` — นำ `agust-11` อยู่ 12 commits): `app/Http/Controllers/Api/V1/SsoController.php`, `app/Services/Sso/KuSsoService.php`, `app/Services/Sso/Exceptions/*` (5 ไฟล์), `routes/api.php`, `AuthController.php`, `UserController.php`, `app/Http/Requests/StoreUserRequest.php` + `UpdateUserRequest.php`, `app/Models/User.php`, `config/ku_sso.php`, `.env.example`, `database/migrations/2026_09_07_120000_add_auth_provider_to_users_table.php` + `2026_09_08_120000_drop_is_ku_member_from_users_table.php`, `tests/Feature/SsoExchangeTest.php`, `tests/Feature/AuthTest.php`, `docs/api_guide.md`, `vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php` · wayfinder: `ku-sso/map.md` + tickets 02/03/05/07/09 + `ku-sso/research/integration-approach.md` · `google-integration/map.md` + ticket 01 — ไม่แก้อะไรทั้ง repo

---

## 1. โครงสร้างโค้ด SSO จริงใน worktree — ส่วนไหน generalize / ส่วนไหนผูก Keycloak

### 1.1 Controller — `.worktree/ku-sso-login/app/Http/Controllers/Api/V1/SsoController.php` (91 บรรทัด, 1 method)

- `exchange()` (SsoController.php:25) — **ไม่มี FormRequest** validation เป็น inline `$request->validate()` (SsoController.php:27-36): `code` required|string + `code_verifier` required|string|min:43|max:128 + regex RFC 7636 §4.1 (custom error messages ไทย+emoji 🔑)
- Flow ใน try-block: `exchangeCode → fetchUserinfo → findOrCreateUser` (SsoController.php:39-41) — access token ใช้ครั้งเดียวทิ้ง (`// 🗑️` comment SsoController.php:40)
- Error map ตรงตาม ticket 03: `InvalidGrantException`→422 · `MissingEmailException`→422 · `InvalidClientException`→500 (+Log ใน service แล้ว) · `KuSsoUnavailableException`→502 · `Throwable`→500 generic + `Log::error` ไม่ leak (SsoController.php:42-76)
- ออก Sanctum token แบบเดียวกับ login: `createToken('ku_home_auth_token')` ไม่ revoke ของเดิม (SsoController.php:79) · response `{status, message, access_token, token_type, user, id_token}` (SsoController.php:81-89) — `id_token` ส่งต่อเป็น opaque string เผื่อ `id_token_hint` (END_SESSION อนาคต — ticket 03)

### 1.2 Service — `.worktree/ku-sso-login/app/Services/Sso/KuSsoService.php` (207 บรรทัด, 3 public + 3 private)

| Method | บรรทัด | เนื้อหา | Keycloak-specific? |
|---|---|---|---|
| `exchangeCode($code, $codeVerifier)` | KuSsoService.php:37-83 | POST form → token endpoint, params `grant_type/client_id/client_secret/redirect_uri/code/code_verifier` | ⚠️ ก้ำกึ่ง — OAuth 2.0 standard ทั้งหมด แต่ error detect ผูก 401+`invalid_client` / `invalid_grant` (ซึ่ง Google ใช้ shape เดียวกัน) |
| `fetchUserinfo($accessToken)` | KuSsoService.php:92-111 | GET + Bearer, ตัดสินจาก status เท่านั้น (body ว่างได้), ConnectionException→502 | ✅ generic สมบูรณ์ |
| `findOrCreateUser($claims)` | KuSsoService.php:118-161 | email chain → fail-closed → find `(email,'ku_sso')` ไม่ overwrite → create `role ku_member` + `Str::random(64)` → race guard SQLState 23000/23505 | ❌ **แน่นมาก**: chain `email → google-mail → office365-mail` (KuSsoService.php:123), role `ku_member` (KuSsoService.php:147), provider `'ku_sso'` hardcode (KuSsoService.php:136/146/156) |
| `nameFromClaims()` (private) | KuSsoService.php:167-182 | chain `name → thainame → cn` + first/last จาก `givenname/given_name/first-name` ฯลฯ | ❌ KU claim ไม่ตาม OIDC standard — Google ใช้ standard claims ไม่ต้องมี chain นี้ |
| `httpFormCall()` (private) | KuSsoService.php:189-200 | `Http::asForm()` + timeout/connectTimeout จาก config + ConnectionException→502 | ✅ generic |
| `endpoint()` (private) | KuSsoService.php:202-205 | `rtrim(base_url).'/protocol/openid-connect/'.$name` | ❌ **Keycloak path format เฉพาะ** — Google เป็น URL เต็มต่าง domain (token/userinfo แยก host) |

- Config keys `config('ku_sso.*')` ทั้งหมด (KuSsoService.php:42-45, 96-97, 193-194, 204) — `.env.example:68-76` มีบล็อก `KU_SSO_*` ครบ 8 ค่า
- **จุดที่ generalize ได้ทันที (สำหรับคิด option b):** `fetchUserinfo`, `httpFormCall`, race guard (KuSsoService.php:151-160), fail-closed skeleton — รวมกัน ~60-80 บรรทัด · ที่เหลือต่างกันทุกขั้น (claims, role, endpoint, error nuance)

### 1.3 Exceptions — `.worktree/ku-sso-login/app/Services/Sso/Exceptions/` (5 ไฟล์)

- Base `KuSsoException extends \RuntimeException` (KuSsoException.php) + concrete 4 ตัว: `InvalidClientException` (→500) · `InvalidGrantException` (→422) · `KuSsoUnavailableException` (→502) · `MissingEmailException` (→422)
- **semantics ของ concrete 4 ตัวเป็น provider-neutral ตามดีไซน์แล้ว** (invalid_grant/invalid_client/IdP ล่ม/ไม่มี email = พฤติกรรมมาตรฐานของทุก OIDC provider) — แค่ชื่อคลาส/ดอกคีย์เวิร์ด Keycloak-flavored · folder `Exceptions/` เป็น shared namespace (`App\Services\Sso\Exceptions`) ไม่ได้ล็อก KU อยู่แล้ว

### 1.4 Route + tests

- `routes/api.php:44` — `Route::post('/auth/sso/exchange', [SsoController::class, 'exchange'])->middleware('throttle:5,1')` · public (นอก `auth:sanctum` group) · `RequireJsonAccept` ครอบอัตโนมัติผ่าน global api middleware (bootstrap/app.php:22 prepend)
- `tests/Feature/SsoExchangeTest.php` (368 บรรทัด) — **18 test methods ครอบครัว ticket 07 ครบ**: happy path สร้างใหม่ (role ku_member + password สุ่ม `Hash::check` fail), re-login ไม่ duplicate ไม่ overwrite + email uppercase normalize, split vs password account, multi-device token เก่ารอด, invalid_grant→422 ไม่ leak `error_description`, no-email→422, 3 เคส email chain (google-mail/office365-mail/prefer-email), invalid_client→500+`Log::spy`, Keycloak 500→502, ConnectionException→502, validation `code`→422+`Http::assertNothingSent`, require code_verifier, relay code_verifier (`Http::assertSent`), logout revoke (DB-level assert + `forgetGuards`), throttle ครั้งที่ 6→429, admin filter `?auth_provider=ku_sso`
- มี `docs/sso-frontend-guide.md` (TS guide ฝั่ง React) และ `docs/api_guide.md:187-227` (section exchange) — ทั้งคู่เขียน path `/auth/sso/exchange` ไว้แล้ว = **ชื่อ route ของ KU ห้ามเปลี่ยน** (มีผู้บริโภคทั้งเอกสารและ SPA)

## 2. Schema รองรับ `google` แล้วจริง — verification

- **Migration** `2026_09_07_120000_add_auth_provider_to_users_table.php:19-22` — `$table->string('auth_provider')->default('password')` + drop `users_email_unique` → `unique(['email','auth_provider'])` — **เป็น string ธรรมดา ไม่มี enum/check constraint** → `'google'` เป็นค่า legal ทันที ไม่ต้องแก้ DB
- **`User` model** (`.worktree/ku-sso-login/app/Models/User.php:22`) — `auth_provider` อยู่ใน `$fillable` พร้อม comment `// 🎫 password | ku_sso | google (อนาคต)` — รอ Google ไว้แล้ว
- **ไม่มี code path ใด block `google`:**
  - `AuthController::login` (AuthController.php:87) — filter `where('auth_provider','password')` แล้ว (ticket 09 implement แล้ว + regression test `AuthTest.php:132-141`) → password login ไม่มีทางชน row ของ google account
  - `StoreUserRequest.php:31` — unique ต่อ email scope `auth_provider=password` แล้ว → คนที่มี google account สมัคร password ด้วย email เดิมได้
  - `UpdateUserRequest.php:31-38` — unique scope ตาม provider ของ row ที่แก้ + `ignore` → ปลอดภัยกับทุก provider รวม google
  - `UserController::index` (UserController.php:15-21) — filter `?auth_provider=` รับ string ตรง ๆ (ไม่มี whitelist) → `?auth_provider=google` ใช้ได้เลย · serialize ออกทุก user object (`docs/api_guide.md:306`)
  - grep `where('email'` ทั้ง `app/` เจอแค่ 3 จุด — ถูก scope provider หมดทุกจุด ✅
- **สรุป: ชั้น schema/validation/admin-view เตรียมรับ Google ครบแล้ว — ช่องว่างเดียวคือชั้น service/route/config ซึ่งยังไม่มีของ Google เลย** (ตามที่ map คาด) · เพียงแต่ `docs/api_guide.md:304` ยังเขียน filter values เป็น `password | ku_sso` ต้องอัปเดตเติม `google` ตอน implement

## 3. ทางเลือกสถาปัตยกรรม — (a) sibling / (b) generalize / (c) package

| เกณฑ์ | (a) sibling `GoogleSsoService` | (b) base/interface `SsoProvider` contract | (c) package (Socialite / league/oauth2-client) |
|---|---|---|---|
| Deps เพิ่ม | 0 | 0 | 1-3+ (Guzzle ทรานซิทีฟ — `Http::fake` มองไม่เห็น) |
| Code size | ~140-160 บรรทัด (เล็กกว่า KU เพราะไม่มี claim chain — standard claims ตรง ๆ) | interface ~10 + base ~80 + ต่อ provider ~90-120 → **ประหยัดสุทธิ ~40-60 บรรทัด** แลก indirection | logic เขียนเองอยู่ดี + glue ของ package |
| **Blast radius ต่อ KU SSO (unmerged แต่ live-verify ผ่าน)** | **0 — ไม่แตะไฟล์ KU เลย** | ❌ ต้อง refactor `KuSsoService` ดึง logic ขึ้น base → แตะโค้ดที่ผ่าน live-verify + ต้อง re-run test ทั้งชุด 18 เคส | ❌ แตะ auth flow ทั้งระบบ |
| `Http::fake` testability | native ✅ (เทียบ `SsoExchangeTest.php` ได้เลย) | native ✅ | ❌ Guzzle/PSR-18 ตัดผ่าน facade ไม่ได้ — ต้อง `Socialite::fake()` อีก paradigm |
| Precedent ticket 05 (ku-sso) | สอดคล้อง ✅ | สอดคล้อง ✅ (แต่ไม่จำเป็น) | ❌ standing decision ตัดไปแล้ว — Socialite ออกแบบรอบ redirect flow ของมันเอง (callback ของเราอยู่ React) · `league/oauth2-client` wrap แค่ 2 HTTP calls |
| ความเสี่ยง abstraction ผิด | ไม่มี (rule of three: รอ provider ที่ 3 ค่อย extract) | **N=2 ยังน้อยไป** — จุดต่างจริง (claims/role/endpoint/error nuance) ทำให้ base ต้องมี hook เยอะ = premature abstraction | — |

**คำแนะนำ: (a) sibling — เขียน `app/Services/Sso/GoogleSsoService.php` คู่ขนาน `KuSsoService`**

เหตุผลชี้ขาด 3 ข้อ:

1. **Blast radius = 0** — KU SSO ผ่าน live-verify จริง (2026-09-08, ticket 02 Amendment 2 + ticket 10) และมี test suite 18 เคสคุมอยู่ · การดึง logic ขึ้น base class หมายถึงแตะโค้ดที่ทำงานอยู่เพื่อประหยัด ~50 บรรทัด — ไม่คุ้มความเสี่ยง regression เลย
2. **Google สั้นกว่า KU มาก** — ไม่ต้องมี email fallback chain (`email → google-mail → office365-mail`), ไม่ต้องมี `nameFromClaims` chain (`thainame`/`cn`/`givenname`) เพราะ Google คืน OIDC standard claims ครบ (`email`, `email_verified`, `name`, `given_name`, `family_name`) — สิ่งที่ generalize ได้จริงเหลือแค่ `httpFormCall` + race guard + skeleton ซึ่งเมื่อหักค่า induction แล้วประหยัดจริงไม่ถึง 60 บรรทัด
3. **Rule of three** — ถ้าวันหน้ามี provider ที่ 3 (Microsoft/line ฯลฯ) แล้ว pattern จริงปรากฏชัด ค่อย extract `SsoProvider` contract ตอนนั้น (ตอนนั้น concrete 4 exceptions + response contract ก็คือ de-facto interface อยู่แล้ว)

**ข้อสังเกตเรื่อง exceptions:** ให้ `GoogleSsoService` **throw concrete exception 4 ตัวเดิมซ้ำได้เลย** (namespace `App\Services\Sso\Exceptions` เป็น shared อยู่แล้ว, semantics provider-neutral) — controller catch map เดียวกันทำงานทันที · docblock ที่พูดถึง Keycloak ถือเป็น wording เก่า แก้ wording ทีหลังได้ (behavior-neutral, optional) · ไม่ต้อง rename `KuSsoException` base (แตะ live code ฟรี ๆ)

**Optional follow-up (ไม่ block):** หลัง Google ลงแล้วถ้ารู้สึก duplicate เจ็บ ค่อย extract trait `FindOrCreateSsoUser` (race guard + fail-closed skeleton) — เป็น refactor เล็กที่ test ทั้งสองชุดคุมอยู่ ทำทีหลังได้สบาย

## 4. Endpoint shape — แยก route vs provider-param

**คำแนะนำ: แยก route `POST /api/v1/auth/sso/google/exchange` + `throttle:5,1` — ห้ามรวมเป็น provider-param บน `POST /auth/sso/exchange`**

| ประเด็น | แยก route | รวม param `provider` |
|---|---|---|
| KU contract เดิม | ✅ `/auth/sso/exchange` คงเดิมทุก byte — `docs/api_guide.md:187` + `docs/sso-frontend-guide.md` + SPA ไม่กระทบ | ❌ แม้จะ backward-compatible (param optional) แต่ validation/branching เข้ามาอาศัยใน method เดิม = blast radius ต่อ KU |
| Validation ต่างกัน | ✅ แต่ละ method validate ของตัวเอง ตรงไปตรงมา | ❌ ต้อง branch เงื่อนไข: KU มี email fallback chain + fail-closed แบบ "ไม่มี email", Google มี `email_verified` fail-closed — exception เกิดคนละจุด คนละ message |
| Error message ผู้ใช้ | ✅ message อ้างชื่อ provider ถูกต้อง ("เข้าสู่ระบบผ่าน Google อีกครั้ง") | ❌ message ต้อง interpolate provider ทุก branch |
| Throttle `5,1` | ✅ parity ตาม convention (ดู nuance ข้างล่าง) | ✅ เท่ากัน |
| REST/RESTful clarity | ✅ resource ต่อ provider, docs แยก section ชัด | ⚠️ route เดียวสองบุคลิก |

- **Request/response/error contract = mirror ticket 03 ทุกด้าน:** body `{ code, code_verifier }` (PKCE S256 — Google แนะนำ PKCE แม้ confidential client เสมอ; ส่ง `client_secret` คู่กันแบบ double protection เหมือนที่ KU enforce อยู่) · response `200 {status, message, access_token, token_type, user, id_token}` · error map `invalid_grant`→422 · `invalid_client`→500+Log · IdP ล่ม/timeout→502 · email ปัญหา→422 · validation→422
- **Google nuance ใน error map:** `redirect_uri_mismatch` / `invalid_request` ที่ token endpoint (HTTP 400) = **config ฝั่งเราพัง ไม่ใช่ความผิด user** → จัดกลุ่มเดียวกับ `invalid_client` → 500 + `Log::error` (KU ไม่มีเคสนี้เพราะ redirect_uri ผูกกับ OCS ไว้แล้ว)
- **Google nuance ใน validation:** กฎ `code_verifier` ใช้ชุดเดิมได้ 100% (RFC 7636 เหมือนกัน) — copy มาตรง ๆ
- **ที่ตั้งโค้ด:** เพิ่ม method `exchangeGoogle()` ใน `SsoController` เดิม (import/exception map ใช้ร่วมกัน, ไม่แตะ method `exchange()` เดิม) หรือแยก `GoogleSsoController` ก็ได้ — แนะนำ method ใน controller เดิมก่อน ถ้าโตเกิน ~150 บรรทัดค่อยแยกไฟล์
- ⏱️ **Throttle nuance (ค้นพบจาก vendor จริง):** `ThrottleRequests::resolveRequestSignature` (vendor `lumen`... ไฟล์ `Illuminate/Routing/Middleware/ThrottleRequests.php:224-234`) key ด้วย `domain|ip` **ไม่รวม route URI** → `throttle:5,1` ทุก route **แชร์ bucket เดียวต่อ IP** (login กับ KU exchange ก็แชร์กันอยู่แล้ววันนี้ — เป็นพฤติกรรมที่มีอยู่ก่อน Google) → ใช้ `throttle:5,1` ต่อไปเพื่อ parity ธรรมดา; ถ้าวันหน้าอยากแยก bucket ต่อ provider ค่อยทำ named `RateLimiter::for(...)` — ไม่ block และ**ห้าม**ไปแก้ limiter ของ route KU เดิม (YAGNI + blast radius)

## 5. ความต่างจาก KU SSO ที่ควร lock ก่อนเขียนโค้ด

1. **📧 `email_verified` fail-closed — ใช้ และต่างจาก KU สิ้นเชิง:** KU ห้ามใช้ claim `email_verified` ตัดสินอะไร (live-verify 2026-09-08: เป็น `false` ทั้ง test accounts — ticket 02 Amendment 2) · แต่ของ **Google claim นี้เชื่อถือได้และมีความหมายจริง** → lock กฎ: `email` ต้องมี **และ** `email_verified === true` ไม่งั้น 422 fail-closed (ไม่สร้าง user) — ตรง spirit ของ ku-sso ticket 02 ที่ "ไม่เดา identity" · ⚠️ wire format: Google คืน JSON boolean (บาง edge เจอ string `"true"`) — เช็คแบบ tolerant เช่น `filter_var($v, FILTER_VALIDATE_BOOLEAN) === true` หรือรับทั้ง `true`/`"true"` แล้ว **fail-closed ทุกอย่างที่ไม่ใช่ true**
2. **🚪 ไม่มี END_SESSION:** Google ไม่มี browser-redirect logout แบบ OIDC RP-initiated (`id_token_hint` ใช้ไม่ได้กับ Google) · เพราะเราทิ้ง access token ทันทีหลัง exchange และไม่ขอ refresh_token → **ตอน logout ไม่มีอะไร Google-side ให้ revoke เลย** — logout เป็น local Sanctum ล้วน (เหมือน KU v1 ทุกประการ) · `https://oauth2.googleapis.com/revoke` มีประโยชน์เมื่อไหร่ที่เก็บ token (ยังไม่มีใน design) — จด fog ไว้
3. **🔖 `id_token` ใน response:** Google ไม่มีการใช้ `id_token_hint` → ค่านี้ **inert** สำหรับ Google · แนะนำ **คืน key นี้ต่อเพื่อ shape parity กับ ticket 03** (SPA จัดการ response สอง provider ด้วยโค้ดชุดเดียว) พร้อม comment ว่าเผื่ออนาคต/inert
4. **🧪 Consent screen โหมด Testing (UX จริง):** app external โหมด Testing = user ทุกคนเจอหน้า "Google hasn't verified this app" (ต้องกด Advanced → Go to app) + cap **100 test users** + refresh token อายุ 7 วัน (ข้อหลังกระทบเรา 0% เพราะไม่ขอ refresh token) · ผลจริงคือ dev/test phase เห็น warning screen เพิ่ม 1 หน้าก่อน consent · ตอนขึ้น prod ต้อง publish (scopes `openid email profile` เป็น non-sensitive การ review ปกติไม่โหด) — ผูกกับ ticket 01/05 ของ map นี้
5. **👤 Role ตอนสร้าง user ใหม่ — ⚠️ จุดเดียวที่ต้อง owner ยืนยันก่อนเขียน:** ku-sso ticket 08 ล็อกไว้ว่า "first login **KU SSO** = role `ku_member`" — เหตุผลเดิมคือ identity จาก Keycloak realm KU = พิสูจน์สถานะสมาชิก KU (ผูก `daily_ku` rate ผ่าน `GlobalRate::getEffectiveDailyRate()`) · **Google identity ไม่พิสูจน์ความเป็นสมาชิก KU เลย** → แนะนำ Google-born user = **role `user`** (เหมือน register) ไม่ใช่ `ku_member` — ไม่งั้นใครก็ได้ล็อกอินด้วย Gmail แล้วได้อัตราส่วนลด KU member · ห้าม copy `'ku_member'` จาก `KuSsoService.php:147` แบบมั่ว (ticket 02 ของ map นี้ไม่ได้ถามข้อนี้ — graduate เป็นคำถามเดียวให้ owner ตอบ ต้นทุน 1 บรรทัด)
6. **🧾 Claims — ไม่มี chain:** `email` / `name` / `given_name` / `family_name` / `picture` เป็น standard ตรง ๆ — `findOrCreateUser` เหลือแค่ normalize lowercase + ตรวจ `filter_var` (แต่คง fail-closed skeleton ไว้) · name = `name` ก่อน ไม่มีค่อย concat `given_name + family_name` ตาม convention เดิม (ticket 02 ku-sso) · `sub` ยัง**ไม่เก็บ** ตาม standing decision

## 6. Test matrix — `Http::fake` (mirror ticket 07, ปรับ 11 → 15 เคส)

Fake endpoints Google: `'oauth2.googleapis.com/token'` + `'openidconnect.googleapis.com/v1/userinfo'` (pattern เดียวกับ `SsoExchangeTest.php:36-48`)

| # | เคส | assert หลัก |
|---|---|---|
| 1 | ✅ first login สร้างใหม่ | 200 shape ครบ `{access_token, token_type, user, id_token}` · `auth_provider=google` · role ตามที่ lock ข้อ 5 · `Hash::check('password123')` fail + password `$2y$` prefix · email lowercase |
| 2 | ✅ re-login (email uppercase + ชื่อใหม่จาก Google) | find ของเดิม — ไม่ duplicate ไม่ overwrite name (`Http::sequence()` pattern จาก SsoExchangeTest.php:86-95) |
| 3 | ✅ split vs password | email ชน password account → user ใหม่แยก, password row ไม่ถูกแตะ (`role=user`, `auth_provider=password`) |
| 4 | ✅ **split across SSO (ใหม่ — ไม่มีใน KU suite)** | มี user `ku_sso` email X อยู่แล้ว → Google login email X ต้องสร้าง row `google` แยก **ไม่ match row ku_sso** — `User::where('email',X)->count() === 2` คนละ id |
| 5 | ✅ `email_verified=false` (และแบบไม่มี claim) | 422 fail-closed · `User::count() === 0` · `Http::assertSent` ครบ 2 call |
| 6 | ✅ multi-device | exchange 2 ครั้ง → token เก่ายังยิง `/me` ได้ 200 |
| 7 | ✅ `invalid_grant` (400 + error JSON) | 422 · ไม่ leak `error_description` · `User::count() === 0` |
| 8 | ✅ `invalid_client` (401) | 500 + `Log::assertLogged('error')` (`Log::spy()` pattern SsoExchangeTest.php:242) |
| 9 | ✅ Google 500 / ConnectionException | 502 ทั้งสองเคส |
| 10 | ✅ **`redirect_uri_mismatch` (400) — เพิ่มของ Google** | 500 + Log (config เราพัง ไม่ใช่ user) |
| 11 | ✅ validation `code`/`code_verifier` หายหรือ regex ไม่ผ่าน | 422 `assertJsonValidationErrors` + `Http::assertNothingSent()` |
| 12 | ✅ PKCE relay | `Http::assertSent` — request ไป token endpoint มี `code_verifier` + `client_secret` + `redirect_uri` จาก config |
| 13 | ✅ throttle `5,1` | ครั้งที่ 6 → 429 |
| 14 | ✅ logout google user | token current ถูกลบ (DB-level assert + `forgetGuards` pattern SsoExchangeTest.php:320-338) |
| 15 | ✅ admin filter `?auth_provider=google` | โชว์ + กรองได้ (ต่อยอดเคส SsoExchangeTest.php:355-367) |

Remote smoke script (ถ้าต้องการ เช่น `test_scripts/test_google_sso_remote.php`) ทำได้แบบเดียวกับ ticket 07: ยิง discovery/token endpoint โดยไม่ต้อง login จริง — happy path claims จริงรอ OAuth client จาก ticket 05 แล้ว manual ตรวจครั้งเดียว

## 7. Risks / gaps — สิ่งที่ต้องรู้ก่อนลงมือ

1. **🚨 Merge order (ความเสี่ยงอันดับ 1):** โค้ด SSO ทั้งชั้น (controller/service/exceptions/config/migration/tests/docs) **อยู่แค่ใน `feature/ku-sso-login` (12 commits ข้างหน้า `agust-11`) — main tree ยังไม่มี SSO เลย** (`app/Services/` มีแค่ `Discount`, `RoomAllocator`) · ไฟล์ที่ Google ต้องแตะชนกับ KU แทบทั้งหมด: `routes/api.php`, `AuthController`, `StoreUserRequest`, `UpdateUserRequest`, `UserController`, `User.php`, `.env.example`, `docs/api_guide.md` → **Google ต้องเริ่มจากฐาน `feature/ku-sso-login` เสมอ** (แผน A: merge feature branch เข้า main ก่อนแล้ว Google ต่อทับ / แผน B: แตก branch google จาก feature branch แล้ว merge ทั้ง chain) — ห้ามเริ่มจาก `agust-11` เด็ดขาด (conflict แน่ + ทดสอบไม่ได้เพราะ schema `auth_provider` ก็อยู่ใน feature branch นี้เอง)
2. **⚠️ Role ตอนสร้าง Google user** — ตามข้อ 5: แนะนำ `user` ต้อง owner sign-off ก่อน (ผลถึงเงิน — `daily_ku` rate)
3. **Throttle bucket รวมต่อ IP** — `throttle:5,1` key = `domain|ip` (ThrottleRequests.php:224-234) → login + KU exchange + google exchange แชร์โควตา 5/นาที ต่อ IP — เป็นสถานะเดิมก่อน Google อยู่แล้ว ยอมรับต่อไป; อย่าแก้ route KU เพื่อ "แก้" อันนี้
4. **`.env.example` + config ใหม่** — เพิ่มบล็อก `GOOGLE_SSO_*` (แนะนำชื่อเดียวกันทั้งชุดเพื่อ symmetry: `GOOGLE_SSO_CLIENT_ID/CLIENT_SECRET/REDIRECT_URI/TIMEOUT/CONNECT_TIMEOUT` + endpoint คงที่ของ Google เป็นค่า default ใน `config/google_sso.php`: token `https://oauth2.googleapis.com/token`, userinfo `https://openidconnect.googleapis.com/v1/userinfo`, revoke `https://oauth2.googleapis.com/revoke` — ยืนยันจาก discovery doc เป็นงาน ticket 01) · ห้ามแตะ `config/ku_sso.php`
5. **Docs ต้องอัปเดต 3 จุด** — `docs/api_guide.md` เพิ่ม section google exchange + แก้ filter values ที่บรรทัด :304 เติม `google` · frontend guide แยกไฟล์ของ Google (GIS code mode vs redirect ตรง + redirect URI rules ของ Cloud Console — ผูก ticket 01/03) · จด design decision ลง `cline.md` ก่อนเขียนโค้ดตาม protocol ของ AGENTS.md
6. **`email_verified` wire format** — เช็ค tolerant boolean (ข้อ 5.1) ให้เป็น test ครอบ (matrix #5)
7. **ระวัง copy trap ใน `findOrCreateUser`** — สิ่งที่ต้อง copy: fail-closed skeleton + race guard SQLState `23000/23505` + ห้าม overwrite · สิ่งที่**ห้าม** copy: email chain, `nameFromClaims` KU chain, `role => 'ku_member'`, Keycloak `endpoint()` path building
8. **Exceptions reuse** — throw concrete 4 ตัวเดิมได้เลย (ข้อ 3) — docblock Keycloak wording แก้ทีหลัง optional
9. **Consent Testing-mode UX** — 100 test users + unverified warning (ข้อ 5.4) — แจ้ง owner/frontend ล่วงหน้าว่า dev phase จะเจอ warning screen ไม่ใช่ bug

---

## TL;DR

> ```
> ┌─────────────────────────────────────────────────────────────────────────────┐
> │ RECOMMENDATION — Google login integration approach                          │
> │                                                                             │
> │ สถาปัตยกรรม:  (a) sibling `GoogleSsoService` คู่ขนาน KuSsoService           │
> │    • 0 package, 0 blast radius ต่อ KU SSO ที่ live-verify ผ่านแล้ว          │
> │    • Http::fake ทดสอบ native เหมือนเดิม (18 เคส KU เป็นแม่แบบ)              │
> │    • throw concrete exception 4 ตัวเดิม reuse ได้ทันที                      │
> │    • generalize เป็น SsoProvider contract = รอ provider ที่ 3 (rule of 3)   │
> │                                                                             │
> │ Endpoint:  POST /api/v1/auth/sso/google/exchange · throttle:5,1 · public    │
> │    • body { code, code_verifier } (PKCE S256 relay — เหมือน KU ทุกอย่าง)    │
> │    • response/error map mirror ticket 03 (+redirect_uri_mismatch → 500)     │
> │    • ไม่รวม provider-param บน route เดิม (KU contract ห้ามแตะ)              │
> │                                                                             │
> │ ล็อคก่อนเขียน:  email_verified fail-closed (ต่างจาก KU!) · logout local      │
> │    ล้วน (ไม่มี END_SESSION, id_token คืนแบบ inert เพื่อ shape parity) ·      │
> │    role ตอนสร้าง = user (แนะนำ — ห้าม ku_member อัตโนมัติ เพราะ Google       │
> │    ไม่พิสูจน์สถานะ KU · ⚠️ รอ owner sign-off) · ไม่เก็บ sub                 │
> │                                                                             │
> │ ความเสี่ยงอันดับ 1:  merge order — ต้อง build บนฐาน feature/ku-sso-login     │
> │    (main tree ไม่มี SSO + schema auth_provider อยู่ใน branch นั้น)           │
> │                                                                             │
> │ Schema/validation/admin-view:  พร้อมรับ google แล้ว 100% ไม่ต้องแก้ DB       │
> │    (composite unique + ticket 09 fixes ครบใน worktree) — gap เดียวคือ       │
> │    ชั้น service/route/config และ docs                                        │
> └─────────────────────────────────────────────────────────────────────────────┘
> ```
