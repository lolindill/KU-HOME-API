# Google OIDC mechanics สำหรับ login (confidential client + SPA code flow)

- **label:** `wayfinder:research`
- **type:** AFK
- **status:** closed *(2026-09-11 — research subagent, discovery doc live-verified)*
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

ข้อเท็จจริงที่ decision อื่นรออยู่ (แนวเดียวกับ ticket 04 ของ map ku-sso แต่เป็นฝั่ง Google):

- Discovery document ของ Google (`https://accounts.google.com/.well-known/openid-configuration`) — endpoints: authorization / token / userinfo / revocation, issuer คืออะไร
- Authorization request: parameters ที่ต้องส่ง (`client_id`, `redirect_uri`, `response_type=code`, `scope`, `state`, `nonce`, `code_challenge`/`code_challenge_method`) — Google enforce PKCE แบบไหนบ้างสำหรับ Web application client
- Scope น้อยที่สุดที่พอ: `openid email profile` คืน claims อะไรบ้าง (`sub`, `email`, `email_verified`, `name`, `given_name`, `family_name`, `picture`)
- Token exchange: parameters ที่ต้องส่ง (`code`, `client_id`, `client_secret`, `code_verifier`, `redirect_uri`, `grant_type`), response fields (`access_token`, `id_token`, `expires_in`, `refresh_token`), อายุ access token, code ใช้ได้กี่ครั้ง/นานเท่าไร
- ความจำเป็นของ `email_verified` — policy fail-closed เหมือน ku-sso ticket 02 ทำได้ไหม (กรณี email ไม่ verified ควร reject ไหม)
- Userinfo endpoint: headers, response, error เมื่อ token หมดอายุ/ไม่ถูกต้อง
- Error shapes จาก Google (`invalid_grant`, `invalid_client`, `redirect_uri_mismatch`, `access_denied` ฯลฯ) — status codes เพื่อออกแบบ error map ฝั่งเรา
- Refresh token: เราไม่ต้องใช้ (ใช้ access token ทันทีแล้วทิ้ง) — แต่ต้องรู้ gotcha ไหม: **refresh token หมดอายุ 7 วันเมื่อ OAuth consent screen อยู่โหมด Testing** (กระทบเราไหมถ้าไม่ขอ refresh token), ข้อจำกัด 100 test users
- ฝั่ง SPA: Google Identity Services (GIS) โหมด code (`codeflow`) vs redirect ตรงไป authorize endpoint — redirect URI แบบไหน register ได้ (localhost http + port ตรง ได้ไหม), กฎของ Google เรื่อง authorized redirect URIs
- ขั้นตอนการขอ OAuth client บน Google Cloud Console (สำหรับ task ticket 05): consent screen (External/Internal), credentials → OAuth client ID (Web application) — ต้องอะไรบ้าง, ใช้เวลา/ขั้นตอน review ของ Google แค่ไหนเมื่อ scope เป็นแค่ openid/email/profile

## Resolution (2026-09-11)

ดูรายงานเต็ม: [../research/google-oidc-mechanics.md](../research/google-oidc-mechanics.md)

- **Discovery live-verified [VERIFIED-LIVE]** — authorize `https://accounts.google.com/o/oauth2/v2/auth` · token `https://oauth2.googleapis.com/token` (รับ `client_secret_post` เหมือน Keycloak — flow ย้ายมาได้ **1:1**) · userinfo `https://openidconnect.googleapis.com/v1/userinfo` · revoke `https://oauth2.googleapis.com/revoke` · issuer `https://accounts.google.com`
- **PKCE รับ `plain`+`S256` แต่ไม่ enforce** สำหรับ confidential web client (ต่างจาก sso-dev ที่ enforce) — ส่ง S256 ต่อเป็น hardening อยู่ดี
- **Claims:** `sub` มีเสมอ → ใช้เป็น key ของ find-or-create (Google เตือนห้ามใช้ email เป็น identifier) · `email`/`email_verified`/`name`/`picture` ถือเป็น nullable ตาม spec — **`email_verified` fail-closed (reject 422) ทำได้เต็มรูปแบบ** ต่างจาก KU ที่ห้ามใช้ claim นี้
- **อายุ:** access token ~1 ชม. · authorization code single-use (reuse → `invalid_grant`)
- **Error map:** authorize errors มาทาง redirect param (302) ไม่ใช่ HTTP status · token endpoint `invalid_client`→401, `invalid_grant`/`invalid_request`/`redirect_uri_mismatch`→400 · userinfo token หมดอายุ→401
- **Testing-mode gotchas ไม่กระทบเราสองชั้น:** ไม่ใส่ `access_type=offline` จึงไม่มี refresh token (กฎ 7 วันไม่เกี่ยว + basic scopes ยกเว้นอยู่แล้ว) · 100-user cap/unverified screen ผูกกับ sensitive/restricted scopes ซึ่ง `openid email profile` ไม่เข้าข่าย
- **Redirect URI:** https บังคับ "ยกเว้น localhost" (port ต้อง exact) · prod http ถูก block · match แบบ exact ห้าม wildcard · ⚠️ `client_secret` เห็นแค่ครั้งเดียวตอนสร้าง — ต้องเก็บให้จบในคลิกเดียว
- **SPA:** แนะนำ **plain redirect แบบ flow เดิมของ KU** (ไม่ใช้ GIS popup) เพื่อคุม PKCE เอง
- **Logout purely local** — Google ไม่มี `end_session_endpoint` (ต่างจาก Keycloak END_SESSION) → logout = revoke Sanctum token อย่างเดียวจบ
- Checklist ขึ้นทะเบียน → ใช้ต่อใน [ticket 05](./05-google-cloud-project-and-oauth-client.md): Project → Google Auth Platform (External) → Credentials → Web application client → redirect URIs (basic scopes ไม่ต้อง full verification, เหลือ brand verification)
