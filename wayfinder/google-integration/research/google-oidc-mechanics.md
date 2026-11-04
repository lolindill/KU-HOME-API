# Research: Google OIDC mechanics for login (confidential client + SPA code flow)

- **Ticket:** `wayfinder/google-integration/tickets/01-google-oidc-mechanics.md`
- **Date:** 2026-09-11
- **Status:** RESOLVED — flow Keycloak เดิมย้ายมาใช้กับ Google ได้ 1:1 (confidential client + `client_secret_post` + PKCE S256 + code exchange + userinfo over TLS); ไม่ขอ refresh token = ไม่โดน gotcha 7 วันของ Testing mode; logout เป็น local (Sanctum) เพราะไม่มี END_SESSION endpoint
- **Sources:**
  - Discovery document — **live-verified 2026-09-11** จาก `https://accounts.google.com/.well-known/openid-configuration` [VERIFIED-LIVE]
  - https://developers.google.com/identity/openid-connect/openid-connect (OIDC guide)
  - https://developers.google.com/identity/protocols/oauth2 (OAuth2 หลัก)
  - https://developers.google.com/identity/protocols/oauth2/web-server (Web server / code flow)
  - https://developers.google.com/identity/protocols/oauth2/native-app (PKCE + loopback redirect)
  - https://docs.cloud.google.com/docs/authentication/token-types (อายุ access token)
  - https://developers.google.com/identity/oauth2/web/guides/use-code-model + https://developers.google.com/identity/oauth2/web/reference/js-reference (GIS code model)
  - https://support.google.com/cloud/answer/6158849 (สร้าง OAuth client + กฎ redirect URI)
  - https://support.google.com/cloud/answer/9110914 + https://support.google.com/cloud/answer/13464323 + https://support.google.com/cloud/answer/7454865 (verification / unverified apps)
  - https://openid.net/specs/openid-connect-core-1_0.html (OIDC Core — §5.3.x UserInfo) · https://www.rfc-editor.org/rfc/rfc6749.html (RFC 6749 — §4.1.2, §5.2)

---

## 1. Discovery document [VERIFIED-LIVE]

Fetch จริงเมื่อ 2026-09-11 (ค่าทั้งหมดด้านล่างคือ output ดิบ ไม่ใช่จากเอกสารประกอบ):

| Field | ค่า |
|---|---|
| `issuer` | `https://accounts.google.com` |
| `authorization_endpoint` | `https://accounts.google.com/o/oauth2/v2/auth` |
| `token_endpoint` | `https://oauth2.googleapis.com/token` |
| `userinfo_endpoint` | `https://openidconnect.googleapis.com/v1/userinfo` |
| `revocation_endpoint` | `https://oauth2.googleapis.com/revoke` |
| `jwks_uri` | `https://www.googleapis.com/oauth2/v3/certs` |
| `scopes_supported` | `["openid", "email", "profile"]` — พอดี 3 ตัวที่เราต้องการ |
| `claims_supported` | `aud, email, email_verified, exp, family_name, given_name, iat, iss, name, picture, sub` |
| `code_challenge_methods_supported` | `["plain", "S256"]` — เราใช้ **S256** |
| `token_endpoint_auth_methods_supported` | `["client_secret_post", "client_secret_basic"]` — ตรงกับแผน `client_secret_post` เป๊ะ |
| `grant_types_supported` | `authorization_code`, `refresh_token`, `urn:ietf:params:oauth:grant-type:device_code`, `urn:ietf:params:oauth:grant-type:jwt-bearer` |
| `response_types_supported` | `code` (นอกจากนี้มี token/id_token/ผสม/none) |
| `id_token_signing_alg_values_supported` | `RS256` |
| `subject_types_supported` | `public` |
| `authorization_response_iss_parameter_supported` | `true` |
| ⚠️ `end_session_endpoint` | **ไม่มีใน discovery document** — Google ไม่มี browser end-session แบบ Keycloak (ดู §10) |

ข้อสังเกต: ต่างจาก Keycloak ตรงที่ `scopes_supported` คืนแค่ OIDC basic scopes — scopes Google API อื่น (gmail, drive ฯลฯ) ใช้ได้แต่ไม่ถูกประกาศใน discovery ของ OIDC config นี้

## 2. Authorization request (ฝั่ง SPA → `authorization_endpoint`)

ที่ `https://accounts.google.com/o/oauth2/v2/auth` — **HTTPS เท่านั้น ("Plain HTTP connections are refused")** (https://developers.google.com/identity/protocols/oauth2/web-server)

Parameters (https://developers.google.com/identity/protocols/oauth2/web-server):

| Param | สถานะ | หมายเหตุ |
|---|---|---|
| `client_id` | Required | จาก Cloud Console Clients page |
| `redirect_uri` | Required | ต้อง **exact match** กับ authorized redirect URI ที่ลงทะเบียน — "must exactly match" ไม่งั้น error `redirect_uri_mismatch` |
| `response_type` | Required | ใส่ `code` |
| `scope` | Required | ของเรา: `openid email profile` (space-delimited, ต้องขึ้นต้น `openid`) |
| `state` | Recommended | CSRF — "The OAuth client must prevent CSRF as called out in the OAuth2 Specification" |
| `nonce` | แนะนำ (OIDC guide ระบุเป็น required ในตาราง) | replay protection — แต่จะเห็นผลก็ต่อเมื่อ **อ่าน/ตรวจ `id_token`** (nonce ถูก echo กลับใน id_token payload) · flow เราไม่ parse id_token (ใช้ userinfo) → nonce มีค่าน้อยมาก; `state` + PKCE + `client_secret` + single-use `code` ครอบคลุมแล้ว (แนวเดียวกับ `wayfinder/ku-sso/research/integration-approach.md` §2) |
| `code_challenge` / `code_challenge_method` | Supported, ไม่ enforce สำหรับ web client | ดูด้านล่าง |
| `access_type` | Optional | `online` (default) / `offline` — **ไม่ใส่ = ไม่ได้ refresh token** (เป้าหมายเรา) |
| `prompt` | Optional | `none` / `consent` / `select_account` |
| `login_hint`, `hd`, `include_granted_scopes`, `enable_granular_consent` | Optional | `hd` สำหรับ lock องค์กร; `enable_granular_consent` ตาม GIS reference แล้ว "Obsoleto" (deprecated) |

**PKCE สำหรับ Web application client — ข้อสรุป:**
- Discovery ประกาศรับ `plain` + `S256` [VERIFIED-LIVE] → authorization endpoint รับ `code_challenge` กับ web client แน่นอน
- เอกสาร web-server flow (confidential client) **ไม่บังคับและไม่พูดถึง PKCE เลย** — ตาราง param ไม่มี `code_challenge`
- เอกสาร installed-app (https://developers.google.com/identity/protocols/oauth2/native-app) ระบุ `code_challenge`/`code_challenge_method` เป็น **"Recommended"** (ไม่ใช่ Required): "Google supports the Proof Key for Code Exchange (PKCE) protocol to make the installed app flow more secure" — ถ้าไม่ใส่ method "The value of the code_challenge_method defaults to plain if not present"
- → **Google ไม่ enforce PKCE** สำหรับ confidential web client (ต่างจากบาง provider) แต่รองรับเต็มรูปแบบ เราจะส่ง `code_challenge_method=S256` ต่อไปเพื่อ harden (defense-in-depth ครอบ code ที่โดน intercept กลางทางจาก browser)

## 3. Scopes `openid email profile` → claims อะไรบ้าง

จาก https://developers.google.com/identity/openid-connect/openid-connect (OIDC guide):

| Claim | เงื่อนไข | ความหมาย |
|---|---|---|
| `sub` | **มีเสมอ** | ตัวตนถาวรของ Google account — "unique... and never reused", case-sensitive ≤255 chars. ⚠️ Google เตือนซ้ำ 2 รอบในหน้าเดียว: **ห้ามใช้ `email` เป็น identifier — ใช้ `sub` เท่านั้น** (`email` เปลี่ยนได้/ไม่ unique) → find-or-create ของเรา key ที่ `sub` |
| `email` | เมื่อขอ `email` scope | "not guaranteed unique and may change over time" |
| `email_verified` | เมื่อขอ `email` scope | `true` ถ้า email นั้นถูก verify กับ Google แล้ว — ดู policy ด้านล่าง |
| `name` | เมื่อขอ `profile` scope (หรือจาก refresh) | "possible but not guaranteed" — **ไม่การันตีว่าจะมี** |
| `given_name` / `family_name` | มาได้เมื่อมี `name` | อาจไม่มี (บัญชีบาง culture ไม่แตกชื่อ) |
| `picture` | เงื่อนไขเดียวกับ `name` | URL รูปโปรไฟล์ |
| `profile`, `locale` | เงื่อนไขเดียวกับ `name` | profile page URL / BCP-47 |

ข้อความสำคัญของ spec: OIDC Core §5.3.2 — "The sub (subject) Claim MUST always be returned in the UserInfo Response" และ OP "MAY elect to not return values for some requested Claims... It is not an error condition to not return a requested Claim" (https://openid.net/specs/openid-connect-core-1_0.html) → **ทุก claim ยกเว้น `sub` ต้องถือว่า nullable ฝั่ง backend**

### `email_verified` + fail-closed policy

- Google ให้คำนิยามแค่สั้นๆ: `email_verified` = "true if the user's e-mail address has been verified" — เอกสารไม่ได้ไล่รายละเอียดว่าเคสไหนเป็น false บ้าง (เช่น account ใหม่ยังไม่ยืนยันอีเมล, alias บางแบบ) [UNVERIFIED ในรายละเอียดเคส]
- **Fail-closed ทำได้เต็มรูปแบบ** เหมือน ku-sso ticket 02: ใน exchange endpoint หลังได้ userinfo → ถ้า `email` หาย หรือ `email_verified !== true` → reject `422` ด้วย error response ตาม contract ของ repo (`{"status":"error","message":...}`)
- ผลข้างเคียงที่ยอมรับได้: user กลุ่มเล็กที่ Google account ยังไม่ยืนยันอีเมลจะ login ไม่ได้ — เป็น trade-off ที่ตั้งใจของ policy ปิด (ระบบขายที่พัก ต้องการ email จริงสำหรับ booking confirmation)
- ตัวเลือกถ้าอยาก soft: บันทึก user แต่ flag ไว้ — **ไม่แนะนำ** เพื่อให้เหมือน Keycloak policy เดียวกัน (fail-closed สอง provider ใช้โค้ดเดียวกัน)

## 4. Token exchange (ฝั่ง backend → `token_endpoint`)

`POST https://oauth2.googleapis.com/token` — `application/x-www-form-urlencoded` (https://developers.google.com/identity/protocols/oauth2/web-server)

Request params:

| Param | หมายเหตุ |
|---|---|
| `grant_type=authorization_code` | บังคับตาม RFC 6749 §4.1.3 |
| `code` | authorization code จาก redirect |
| `client_id` + `client_secret` | Web application client เป็น confidential — `client_secret_post` ตาม discovery [VERIFIED-LIVE]; เอกสารแสดง client_secret เป็น "Optional" เพราะยอมรับ `client_secret_basic` ด้วย — เราเลือก **post** ให้เหมือน Keycloak flow เดิม |
| `redirect_uri` | **ต้องส่งซ้ำและตรงกับที่ใช้ตอน authorize** |
| `code_verifier` | คู่กับ `code_challenge` ที่ส่งไปตอนแรก (RFC 7636) — เอกสารหน้า web-server ไม่พูดถึง แต่ discovery ประกาศ PKCE support [VERIFIED-LIVE] |

Response fields (จากเอกสาร OIDC guide + web-server):

```json
{
  "access_token": "ya29...",
  "expires_in": 3599,
  "token_type": "Bearer",
  "scope": "openid https://www.googleapis.com/auth/userinfo.email profile",
  "id_token": "eyJhbGciOiJSUzI1NiIs..."
}
```

- `token_type` — "always `Bearer`"
- `refresh_token` — **จะมีก็ต่อเมื่อ request แรกใส่ `access_type=offline` เท่านั้น**: "this field is only present in this response if you set the access_type parameter to offline" — เราไม่ใส่ → ไม่มี refresh token ใน response เลย (ตัด gotcha ทั้งหมดตั้งแต่ต้นทาง)
- `id_token` — JWT ที่ Google sign; flow เราไม่ parse (identity มาจาก userinfo over TLS — เหตุผลฉบับเต็มใน `wayfinder/ku-sso/research/integration-approach.md` §2, OIDC Core §3.1.3.7 อนุญาต)

**อายุ access token:**
- คำพูดรวมจาก https://docs.cloud.google.com/docs/authentication/token-types: "They're short-lived and expire after at most a few hours" และ **"User access tokens automatically expire after one hour"**
- ทางปฏิบัติ: `expires_in` ≈ 3599–3600 → ออกแบบโดย "อ่าน `expires_in` จาก response เสมอ" เป็น contract หลัก, ยึด ~1 ชั่วโมงเป็นค่า typical (ตัวเลขเดี่ยวๆ ของ Google API ทุกตัวไม่เคยถูกการันตีในเอกสาร code-flow หลัก)
- เนื่องจากเรา **ใช้ token ทันทีแล้วทิ้ง** (ยิง userinfo รอบเดียว) — อายุ 1 ชม. ไม่มีผลต่อ design

**อายุ/การใช้ซ้ำของ authorization code:**
- Google ไม่ publish ตัวเลขอายุ code ไว้ในเอกสาร [UNVERIFIED — ตัวเลขเจาะจง] — ใช้ RFC 6749 §4.1.2 เป็นเพดาน: "A maximum authorization code lifetime of 10 minutes is RECOMMENDED"
- **Single-use เป็น MUST ของ spec** (RFC 6749 §4.1.2: "The client MUST NOT use the authorization code more than once... If an authorization code is used more than once, the authorization server MUST deny the request") และฝั่ง Google เองเขียนไว้ในตาราง error ของ device flow: `invalid_grant` = "code param ค่า invalid, **previously claimed (ถูกใช้ไปแล้ว)**, หรือ parse ไม่ได้" (https://developers.google.com/identity/protocols/oauth2/limited-input-device) → ใช้ซ้ำได้จริง = `invalid_grant`
- โค้ด exchange ต้อง tolerate replay: ถ้า user กด back/refresh ที่ callback อาจยิง code ซ้ำ → map `invalid_grant` เป็น 401/422 ให้ SPA กลับหน้า login

ขนาด token (https://developers.google.com/identity/protocols/oauth2): code ≤256 bytes, access_token ≤2048 bytes, refresh_token ≤512 bytes — "your application must support variable token sizes"

## 5. UserInfo endpoint

- `GET https://openidconnect.googleapis.com/v1/userinfo` พร้อม header `Authorization: Bearer <access_token>` — "Add your access token to the authorization header and make an HTTPS GET request to the userinfo endpoint" (https://developers.google.com/identity/openid-connect/openid-connect)
- Success: `200 OK` + `application/json` — claims ตาม §3; **`sub` มีเสมอ** (OIDC Core §5.3.2 "The sub (subject) Claim MUST always be returned"); Google อาจกด claim อื่นทิ้งได้ (ตามที่ผู้ใช้/องค์กรตั้ง)
- Error เมื่อ token หมดอายุ/ไม่ถูกต้อง: OIDC Core §5.3.3 กำหนดเป็น Bearer error ตาม RFC 6750 §3 —

  ```
  HTTP/1.1 401 Unauthorized
  WWW-Authenticate: Bearer error="invalid_token",
   error_description="The Access Token expired"
  ```

  (https://openid.net/specs/openid-connect-core-1_0.html §5.3.3) → เช็ค HTTP status `!= 200` แล้ว fail ทั้ง exchange พร้อม generic message ตาม repo convention (ห้าม leak `$e->getMessage()` ออกนอก) — body เฉพาะตัวที่ Google คืนจริงในเคส invalid token ไม่มีการ document ไว้ [UNVERIFIED — รูปร่าง body เจาะจงของ Google]

## 6. Error shapes → HTTP status (สำหรับ error map ฝั่งเรา)

**(a) Authorization endpoint errors → มาเป็น redirect กลับ SPA (HTTP 302) ไม่ใช่ status ที่ backend เห็น:** ตาม RFC 6749 §4.1.2.1 + OIDC Core §3.1.2.6 — error เดินทางเป็น query param `?error=...&error_description=...` ที่ `redirect_uri` (เช่น `?error=access_denied` เมื่อ user กดปฏิเสธ — ตัวอย่างจริงในเอกสาร web-server) · โค้ดมาตรฐาน: `invalid_request`, `unauthorized_client`, `access_denied`, `unsupported_response_type`, `invalid_scope`, `server_error`, `temporarily_unavailable` · เคส `redirect_uri_mismatch`/client ไม่ถูกต้อง Google **ไม่ redirect** — แสดง error page ตรงหน้า browser (RFC: "MUST NOT automatically redirect the user-agent to the invalid redirection URI") ฝั่ง SPA ต้องมี timeout/empty-callback handling
- Google-specific บน authorize: `admin_policy_enforced` (Workspace admin ห้าม scope), `org_internal` (client จำกัดเฉพาะ org), `disallowed_useragent` (embedded webview) (https://developers.google.com/identity/protocols/oauth2/web-server)

**(b) Token endpoint errors → JSON body + HTTP status ตรงๆ:**

| error | HTTP | เมื่อไหร่ | จัดการฝั่งเรา |
|---|---|---|---|
| `invalid_client` | **401** | client_id/secret ผิด (Google ระบุในตาราง device flow: 401 Unauthorized) | 500-level config bug — log error, คืน 500 generic |
| `invalid_grant` | **400** | code หมดอายุ/ใช้แล้ว/redirect_uri ไม่ตรงตอน exchange | คืน **401** ให้ SPA → กลับไป authorize ใหม่ ("Request a new code by restarting the OAuth process") |
| `unsupported_grant_type` | **400** | `grant_type` ผิด | 500 (code bug ของเรา) |
| `invalid_request` | 400 | param ขาด/ผิด format | 500 |
| `redirect_uri_mismatch` | 400 | redirect_uri ตอน exchange ไม่ match ที่ลงทะเบียน | 500 (config ไม่ตรงกันระหว่าง SPA/API) |

Baseline ของ status: RFC 6749 §5.2 — "The authorization server responds with an HTTP 400 (Bad Request) status code (unless specified otherwise)"; `invalid_client` "MUST respond with an HTTP 401 (Unauthorized)... include the WWW-Authenticate response header field" เมื่อ auth ผ่าน header — Google ตามนี้ (ยืนยันในตารางของ https://developers.google.com/identity/protocols/oauth2/limited-input-device: `invalid_client`→401, `invalid_grant`→400, `unsupported_grant_type`→400; `org_internal`→403)

**(c) UserInfo error:** 401 + `WWW-Authenticate: Bearer error="invalid_token"` (§5) → คืน 401/422 ให้ SPA กลับหน้า login

**(d) ฝั่ง API เรา (`POST /auth/sso/exchange`) — error map ที่แนะนำ:** ค่าที่ user แก้ไม่ได้ (email ไม่ verified, access_denied, invalid_grant) → **401/422 ให้ SPA กลับไปเริ่ม flow ใหม่**; ค่าที่เป็น config/bug ของระบบ (invalid_client, redirect_uri_mismatch ที่ token endpoint) → **500** + `Log::error()` (ไม่ leak detail ตาม repo convention)

## 7. Refresh token gotchas + Testing mode

คำถาม ticket: "refresh token หมดอายุ 7 วันเมื่อ consent screen อยู่โหมด Testing — กระทบเราไหม?"

**ไม่กระทบ — double-protected:**
1. เราไม่ใส่ `access_type=offline` → Google **ไม่ออก refresh token ให้เลย** ("the refresh token is only returned if your application set the access_type parameter to offline" — https://developers.google.com/identity/protocols/oauth2/web-server) — ไม่มี token ก็ไม่มีอายุให้ยุ่ง
2. แม้จะโดน 7 วัน ก็ไม่มีผล เพราะเราใช้ access token ทันทีแล้วทิ้ง (มีชีวิต ~1 นาทีใน request เดียว)

ข้อจำกัดของ consent screen โหมด **Testing** (External user type) ที่ควรรู้ — quote ตรงจาก https://developers.google.com/identity/protocols/oauth2:

> "A Google Cloud Platform project with an OAuth consent screen configured for an external user type and a publishing status of 'Testing' is issued a refresh token expiring in 7 days, unless the only OAuth scopes requested are a subset of name, email address, and user profile through the userinfo.email, userinfo.profile, openid scopes, or their OpenID Connect equivalents"

- 🌟 อ่านจนจบ: scope ของเรา (`openid email profile`) อยู่ใน **exception ของประโยคเดียวกัน** — แม้จะไปขอ refresh token ใน Testing mode ก็ไม่โดน 7 วัน เพราะเป็น basic scopes (แต่เราไม่ขออยู่ดี)
- **100 test users** (จริงๆ เป็น cap ของ unverified app): "your app will be limited to 100 new users until it is verified" (https://support.google.com/cloud/answer/7454865) — เกี่ยวกับเราแค่ช่วง UAT ถ้า user มากว่า 100 คนก่อนเปลี่ยน status เป็น Production; และดู §9 — screen + cap นี้ **ใช้กับ app ที่ขอ sensitive/restricted scopes** เท่านั้น (ของเรา basic → ไม่เข้าข่าย unverified app แต่การขึ้น Production ยังควรทำเพื่อไม่ติดลิมิต)
- **"Unverified app" warning screen**: แสดงเฉพาะ "an app... that requests a sensitive or restricted OAuth scope, but hasn't gone through the Google verification process" (https://support.google.com/cloud/answer/7454865) — `openid email profile` เป็น non-sensitive → ไม่เห็น screen นี้; ตอน dev ถ้า app ยังไม่ผ่าน brand verification อาจเห็นข้อความ branding แต่ไม่ใช่ unverified blocking screen [UNVERIFIED — รูปแบบข้อความที่เห็นจริงตอน dev]
- Refresh token gotchas อื่น (จาก https://developers.google.com/identity/protocols/oauth2 — เพื่อ reference ไม่กระทบเรา): หมดอายุเมื่อ user revoke / ไม่ใช้ 6 เดือน / user เปลี่ยน password แล้ว scope มี Gmail / เกินลิมิต "**100 refresh tokens per Google Account per OAuth 2.0 client ID**"

## 8. ฝั่ง SPA: GIS code mode vs plain redirect + กฎ redirect URI

### GIS (`google.accounts.oauth2.initCodeClient`) vs redirect ตรง

จาก https://developers.google.com/identity/oauth2/web/guides/use-code-model + https://developers.google.com/identity/oauth2/web/reference/js-reference:

- `initCodeClient` + `requestCode()`; `ux_mode`: **`popup` (default)** หรือ `redirect`
  - popup: code มาใน JS callback — "a JavaScript callback handler, which sends the authorization code to your server" → SPA ยิง POST ไป backend เอง; เอกสารแนะนำเช็ค `X-Requested-With: XmlHttpRequest` ที่ backend สำหรับ popup mode
  - redirect: browser ถูกพาไป `redirect_uri` พร้อม code ใน query — แบบเดียวกับ Keycloak flow ปัจจุบันของเราเป๊ะ
- แบบ popup: `redirect_uri` ถูก ignore; "The value of redirect_uri defaults to the origin of the page that calls initCodeClient" และตอน exchange "the redirect_uri value you use... is the origin of the calling page" → origin ของ SPA ต้องเป็น authorized redirect URI ด้วย
- **PKCE:** เอกสาร GIS ทั้งสองหน้า **ไม่พูดถึง PKCE/`code_verifier` เลย** — ไม่สามารถยืนยันได้จาก official docs ว่า GIS จัดการ code_verifier ให้และจะส่งคืนให้ backend อย่างไร [UNVERIFIED] — ถ้าใช้ GIS popup แล้วเราต้องส่ง `code_verifier` จาก SPA ไป backend เอง เอกสารก็ไม่ได้บอกว่าประกบคู่กันอย่างไร
- **PKCE ตอน exchange ต้องทำที่ backend เสมอ** — "On your backend server, you exchange an authorization code for refresh tokens and access tokens" (client_secret ห้ามอยู่ browser อยู่แล้ว)

**ข้อเสนอตามบริบท repo:** ใช้ **plain redirect แบบเดียวกับ Keycloak flow ปัจจุบัน** (React สร้าง URL เอง ต่อ `authorization_endpoint` จาก discovery, เก็บ `state`+`code_verifier` ใน sessionStorage, รับ code ที่ route ของ SPA แล้ว POST `code`+`code_verifier` มา `POST /api/v1/auth/sso/exchange`) — เหตุผล: (1) โค้ด path เดียวกับ SSO เดิมทั้ง SPA และ backend, (2) เราควบคุม PKCE เองครบวงจร ไม่ต้องเดาพฤติกรรม GIS, (3) ไม่เพิ่ม dependency GIS script (~100KB) ทั้งที่ไม่ใช้ One Tap/implicit อยู่แล้ว — GIS จึงเป็นทางเลือก ไม่ใช่ความจำเป็น

### กฎ authorized redirect URIs (https://support.google.com/cloud/answer/6158849)

- **https บังคับ ยกเว้น localhost:** "Redirect URIs must use the HTTPS scheme, not plain HTTP." + "Localhost URIs (including localhost IP address URIs) are exempt from this rule." → ✅ **`http://localhost:5173` ใช้ได้สำหรับ dev** (port ต้องเป็นค่าที่ลงทะเบียนไว้ — match แบบ exact); ❌ **http บน domain จริงถูก block** ตามกฎข้อแรก (ยืนยันตามคำถามใน ticket)
- **Exact match:** "If the redirect_uri passed in the authorization request does not match an authorized redirect URI for the OAuth client ID" → `redirect_uri_mismatch` — scheme/host/port/path ตรงกันเป๊ะ ไม่มี wildcard
- ห้าม: raw IP (ยกเว้น loopback), URL shortener domain, userinfo subcomponent (`user:pass@`), path traversal (`/..`), open redirect, **fragment (`#`)**, wildcard `*`, non-printable/invalid percent-encoding/null byte
- TLD ต้องอยู่ public suffix list; การแก้ค่า "may take 5 minutes to a few hours for changes... to take effect" — วาง buffer ตอน setup
- Native-app เท่านั้น: loopback `http://127.0.0.1:port` ใช้ **random available port ได้โดยไม่ต้องลงทะเบียน port ตายตัว** ("start an HTTP listener on a random available port... Substitute port with the actual port number") — กลไกนี้เป็นของ installed-app flow (PKCE ทำที่ client เอง) **ไม่ใช่** Web application client ของเรา; และ `localhost` hostname แม้จะใช้ได้แต่ "may cause issues with client firewalls"

## 9. ขั้นตอนขอ OAuth client บน Google Cloud Console (สำหรับ task ticket)

ลำดับจริงจาก https://support.google.com/cloud/answer/6158849:

1. **สร้าง Project** ใน Google Cloud Console (ถ้ายังไม่มี)
2. **Google Auth Platform → เลือก project → register app** ("Register your application for Google Auth first... This is required before creating a client") — ตรงนี้คือ consent screen เดิม:
   - **User type: `External`** ใช้กับ user ทุกคน (gmail + Workspace) · **`Internal`** ใช้ได้เฉพาะ "only used by people in your Google Workspace or Cloud Identity organization" (KU มี Workspace → Internal เป็นทางเลือกถ้าอยากจำกัด @ku.ac.th แต่แลกกับไม่รับบัญชีอื่น; และ Internal "will not be subject to the unverified app screen or the 100-user cap" — https://support.google.com/cloud/answer/13464323) — decision นี้ไม่อยู่ใน ticket นี้ แต่ External + ไม่ lock `hd` คือ default ที่ครอบคลุม guest ด้วย
   - กรอก app name, support email, developer contact
3. **Credentials → CREATE CLIENT → Application type: `Web application`** → ใส่ Authorized redirect URIs (exact: เช่น `https://ku-home.ku.ac.th/callback/google` + `http://localhost:5173/callback/google` สำหรับ dev)
4. **รับ `client_id` + `client_secret`** — กฎใหม่สำคัญมาก: "you will receive a client ID and sometimes, a client secret... Your application's client secret will only be shown after you create the client... it will not be visible or accessible again" และ "For clients created after June 2025, secrets are only visible and downloadable from the Google Cloud Console at the time of their creation; afterward... only display the last four characters" → **เก็บ secret ให้จบในคลิกเดียว** (Secret Manager/.env ของ server); ลืมต้อง rotate ไม่ใช่ดูซ้ำ
5. **Verification ต้องไหม?** — scope `openid email profile` เป็น non-sensitive: "If your app utilizes only non-sensitive scopes, it is not mandatory for your app to complete the app verification process" (https://support.google.com/cloud/answer/9110914) — เหลือแค่ **brand verification** (lightweight: homepage + domain ownership) เพื่อโชว์ app name/logo บน consent screen; ไม่มี security assessment (เฉพาะ restricted scopes) · เวลา process: ไม่มีตัวเลขทางการ [UNVERIFIED] — ประเมินเป็นวัน~สัปดาห์สำหรับ brand review, ตั้งค่าอื่นทั้งหมดทำเสร็จใน session เดียว
6. **Publishing status → In production** เมื่อขึ้นจริง (กัน 100-user cap / พฤติกรรม Testing ที่เหลือ) — สำหรับ basic scopes ไม่ต้องรอ verification เพื่อ publish

Config ฝั่ง repo ที่ต้องเก็บ: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` (หรือส่งมาจาก SPA) — client_id ตัวเดียวกันใช้ได้ทั้ง dev/prod ถ้าลงทะเบียน redirect URI ครบทั้งสอง

## 10. Logout ต่างจาก Keycloak — ยืนยัน

- **Confirmed:** discovery document ของ Google **ไม่มี** `end_session_endpoint` [VERIFIED-LIVE — ไม่ปรากฏ field นี้] → ไม่มี browser end-session redirect แบบ Keycloak `END_SESSION` ที่รับ `id_token_hint` — จบ session ที่ Google ให้ทางเดียวคือ user ไป revoke เองที่ https://myaccount.google.com/permissions ("A user can revoke access by visiting Account Settings" — https://developers.google.com/identity/protocols/oauth2/web-server)
- มี **programmatic revocation**: `POST https://oauth2.googleapis.com/revoke` body `token=<access_token หรือ refresh_token>` (`application/x-www-form-urlencoded`), success = **HTTP 200**, ล้มเหลว = 400; revocation endpoint ประกาศใน discovery [VERIFIED-LIVE]; ตัวอย่างทางการใช้แบบไม่แนบ client_secret (https://developers.google.com/identity/protocols/oauth2/web-server ตัวอย่าง Python/Node) · revoking refresh token จะ cascade ไป access token ที่เกี่ยวข้อง ("If you revoke a token that represents a combined authorization, access to all of that authorization's scopes... are revoked simultaneously") [VERIFIED จากตัวอย่าง/ข้อความบนหน้าเดียวกัน]
- **Flow เรา: logout เป็น purely local** — `POST /api/v1/logout` revoke Sanctum token พร้อมเดิม เพราะ Google access token ถูกใช้ทันทีแล้วทิ้งภายใน exchange request เดียว ไม่มีอะไรค้างให้ revoke ที่ Google; ถ้าวันหน้าอยาก revoke จริง ก็แค่ POST /revoke ด้วย token ที่เก็บไว้ชั่วคราว (ไม่จำเป็นใน design นี้) — ไม่ต้องมี redirect ไปไหน หน้า SPA แค่ clear state ตัวเอง

## 11. TL;DR — สรุปรวมสำหรับ implementer

**Endpoints [VERIFIED-LIVE 2026-09-11]:**

| สิ่ง | ค่า |
|---|---|
| Discovery | `https://accounts.google.com/.well-known/openid-configuration` |
| Issuer | `https://accounts.google.com` |
| Authorize | `https://accounts.google.com/o/oauth2/v2/auth` |
| Token | `https://oauth2.googleapis.com/token` |
| Userinfo | `https://openidconnect.googleapis.com/v1/userinfo` |
| Revoke | `https://oauth2.googleapis.com/revoke` |
| End session | **ไม่มี** — logout เราเป็น local (Sanctum) |
| Auth methods | `client_secret_post`, `client_secret_basic` |
| PKCE | `plain`, `S256` (เราใช้ S256) |

**Parameters:**

| ขั้น | ต้องส่ง | Response |
|---|---|---|
| Authorize | `client_id`, `redirect_uri`, `response_type=code`, `scope=openid email profile`, `state`, `code_challenge`, `code_challenge_method=S256` (PKCE แนะนำ ไม่ enforce สำหรับ web client) · ไม่ใส่ `access_type=offline` ไม่ `nonce`-dependent | 302 กลับ `redirect_uri?code=...&state=...` (หรือ `?error=...`) |
| Token | `grant_type=authorization_code`, `code`, `client_id`, `client_secret`, `redirect_uri`, `code_verifier` | `access_token` (~1 ชม.), `expires_in` ≈3599, `token_type=Bearer`, `scope`, `id_token` (ไม่ parse) — **ไม่มี `refresh_token` เพราะไม่ขอ offline** |
| Userinfo | `Authorization: Bearer <access_token>` | 200 + JSON: `sub` (มีเสมอ, **key หลัก find-or-create**), `email`, `email_verified`, `name`, `given_name`, `family_name`, `picture` (ตัวอื่น nullable ได้) |
| Revoke | `POST token=<token>` | 200 / 400 |

**Error → HTTP status:**

| ที่เจอ | จาก | HTTP | การจัดการฝั่งเรา |
|---|---|---|---|
| `access_denied` | authorize (redirect param) | 302 (user กดปฏิเสธ) | SPA แจ้ง user, ไม่ยิง exchange |
| `redirect_uri_mismatch` | authorize (error page) / token | — / 400 | 500 + Log::error (config) |
| `invalid_grant` | token | 400 (code หมดอายุ/**ใช้ซ้ำ**) | 401 → SPA กลับไป authorize ใหม่ |
| `invalid_client` | token | 401 | 500 + Log::error (secret/config) |
| `invalid_request` / `unsupported_grant_type` | token | 400 | 500 |
| token หมดอายุที่ userinfo | userinfo | **401** + `WWW-Authenticate: Bearer error="invalid_token"` | 401 → กลับหน้า login |
| email หาย/`email_verified=false` | userinfo | (ไม่ใช่ HTTP error) | **422 fail-closed** (policy เดียวกับ ku-sso) |

**Verdicts เร็ว:** PKCE = supported ไม่ enforce (ส่ง S256 ต่อ) · basic scopes = ไม่ต้อง full verification (แค่ brand) · Testing 7-day refresh gotcha = **ไม่กระทบ** (ไม่ขอ refresh token + scope เราอยู่ใน exception ด้วย) · 100 users/unverified screen = เรื่องของ sensitive/restricted scopes + ช่วง pre-production · `http://localhost:exact-port` dev ได้, http prod ถูก block · GIS ไม่จำเป็น — plain redirect เหมือน Keycloak flow เดิมตรงสุด

### Sources
- Discovery: https://accounts.google.com/.well-known/openid-configuration (fetched live 2026-09-11)
- https://developers.google.com/identity/openid-connect/openid-connect
- https://developers.google.com/identity/protocols/oauth2
- https://developers.google.com/identity/protocols/oauth2/web-server
- https://developers.google.com/identity/protocols/oauth2/native-app
- https://developers.google.com/identity/protocols/oauth2/limited-input-device
- https://docs.cloud.google.com/docs/authentication/token-types
- https://developers.google.com/identity/oauth2/web/guides/use-code-model
- https://developers.google.com/identity/oauth2/web/reference/js-reference
- https://support.google.com/cloud/answer/6158849
- https://support.google.com/cloud/answer/9110914
- https://support.google.com/cloud/answer/13464323
- https://support.google.com/cloud/answer/7454865
- https://openid.net/specs/openid-connect-core-1_0.html (§4.1.2.1 อ้างผ่าน RFC 6749, §5.3.2, §5.3.3)
- https://www.rfc-editor.org/rfc/rfc6749.html (§4.1.2, §4.1.2.1, §5.2)
