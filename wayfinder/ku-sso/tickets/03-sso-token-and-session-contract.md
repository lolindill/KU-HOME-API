# Contract ของ token & session หลัง SSO exchange

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed *(2026-09-07 — owner sign-off ในแชท grilling)*
- **blocked-by:** — *(ปลดบล็อก 2026-09-01: ticket 04 ปิดแล้ว — ข้อเท็จจริงที่ต้องใช้อยู่ใน [../research/keycloak-mechanics.md](../research/keycloak-mechanics.md))*
- **assignee:** kevii (2026-09-07)

## Question

- หลัง exchange สำเร็จ: ทิ้ง KU `access_token`/`refresh_token` ทิ้งเลย หรือเก็บ `refresh_token` ไว้ renew บางอย่าง? (Sanctum token ของเราไม่หมดอายุอยู่แล้ว — มีเหตุผลอะไรจะเก็บไหม)
- Sanctum token ที่ออกให้ SSO user ใช้นโยบายเดียวกับ password login (`expiration: null`, ไม่ revoke ของเดิม) ใช่ไหม?
- END_SESSION ฝั่ง Keycloak เป็น **required หรือ optional** ใน v1? (diagram วาดไว้เป็น dashed = optional)
- Response shape ของ endpoint exchange — ควร mirror login เดิม (`access_token` + `token_type`) หรือคืน `user` ด้วย?
- Error semantics: code หมดอายุ/ใช้ไปแล้ว/userinfo ล้ม → 401 หรือ 422 หรือ 502 (Keycloak ล่ม)?
- 🆕 **(graduate จาก fog 2026-09-04 — เดิมรอ ticket 01+04 ซึ่งปิดครบแล้ว):** contract ทั้ง request/response ของ endpoint exchange — field names จริง + error mapping จาก Keycloak (`invalid_grant`, `invalid_client` ฯลฯ — ดู [../research/keycloak-mechanics.md](../research/keycloak-mechanics.md)) ให้จบใน ticket นี้ ticket เดียว

## Resolution (2026-09-07 — owner sign-off ในแชท grilling)

1. **KU tokens — ทิ้งหมด ไม่เก็บอะไร:** ใช้ access_token ยิง userinfo ครั้งเดียวแล้ว discard · ไม่เก็บลง DB (สอดคล้อง "ไม่เก็บ `sub`" ของ [ticket 02](./02-sso-identity-to-local-user-mapping.md)) · **ไม่ขอ refresh_token** (scope `basic openid` ก็ไม่ได้ให้อยู่แล้ว) — เหตุผล: Sanctum token ไม่มีวันหมดอายุ (`expiration: null`) ไม่มีอะไรต้อง renew จากฝั่ง KU
2. **นโยบาย Sanctum token = เหมือน password login ทุกประการ:** `expiration: null` · `createToken()` ออกใหม่โดยไม่ revoke ของเดิม (multi-device by design) · logout ลบเฉพาะ `currentAccessToken()`
3. **END_SESSION — ยังไม่ทำใน v1 (คง optional ตาม diagram):** v1 logout = ฝั่ง Sanctum อย่างเดียว · session ที่ sso-dev ยังค้าง — กด login ใหม่จะผ่านเลยโดยไม่ถามรหัส (ยอมรับพฤติกรรมนี้ใน v1) · SPA ไม่ต้องเก็บ/redirect อะไรเพิ่มใน v1 · **`id_token` ยังคืนจาก exchange ตาม contract ข้อ 4** — คงไว้ใช้เป็น `id_token_hint` ตอน END_SESSION graduate มาทำภายหลัง · ผลต่อ [ticket 06](./06-register-ku-home-client-on-sso-dev.md): `post_logout_redirect_uri` **ไม่ต้อง register ใน v1** (แก้ ticket 06 แล้ว)
4. **Contract ของ endpoint exchange (ล็อคชุดนี้):**
   - `POST /api/v1/auth/sso/exchange` · body `{ code }` (required|string) · throttle `5,1` เทียบเท่า login · `Accept: application/json`
   - `200` → `{ "status": "success", "message": ..., "access_token": <sanctum>, "token_type": "Bearer", "user": <User model รวม role ku_member>, "id_token": <jwt จาก KU> }` — key ตรงกับ login เดิม + เพิ่ม `user` (SPA ต้องรู้ user ที่เพิ่ง find-or-create ทันที) + `id_token` · *(shape `{token, user}` ใน diagram ถือเป็นร่างเก่า — ชนะ naming ของ login เดิม)*
   - **Error mapping:** `422` `invalid_grant` (code หมดอายุ/ใช้แล้ว → SPA เริ่ม login flow ใหม่) · `422` validation (`code` หาย/ไม่ใช่ string) · `422` userinfo ไม่คืน email (fail-closed — ticket 02) · `500` `invalid_client` (config ฝั่งเราพัง + `Log::error` ห้าม expose) · `502` Keycloak ล่ม/timeout/network
   - state/PKCE validation เป็นหน้าที่ฝั่ง SPA — backend ไม่ยุ่ง
