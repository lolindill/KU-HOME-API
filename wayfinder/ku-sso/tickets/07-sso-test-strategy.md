# กลยุทธ์ทดสอบ SSO โดยไม่มี browser

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed *(2026-09-07 — owner sign-off ในแชท grilling)*
- **blocked-by:** — *(ปลดบล็อก 2026-09-01: ticket 04 ปิดแล้ว — error shapes/params จริงสำหรับ fake อยู่ใน [../research/keycloak-mechanics.md](../research/keycloak-mechanics.md))*
- **assignee:** kevii (2026-09-07)

## Question

- PHPUnit feature tests ใช้ `Http::fake` จำลอง Keycloak (token endpoint + userinfo) — contract ที่ fake ต้องครอบ case ไหนบ้าง (สำเร็จ, code หมดอายุ, userinfo ไม่มี email, Keycloak 500)
- ต้องมี remote integration script ยิง sso-dev จริงไหม (pattern `test_scripts/test_*_remote.php` + env `KUHOME_*`) — หรือ fake พอ?
- ทดสอบ END_SESSION/logout 2 ชั้นยังไงโดยไม่มี browser session จริง
- ทดสอบ user-linkage (email ชนกัน, สร้างใหม่) ที่ระดับ feature test ได้เลยไหม

## Resolution (2026-09-07 — owner sign-off ในแชท grilling)

1. **Remote script — มีแบบ smoke** (`test_scripts/test_sso_remote.php` pattern + env `KUHOME_*` เดิม) — ยิงได้**โดยไม่ต้อง login จริง**:
   - discovery document 200 + endpoints ครบ 4 ตัว (ตรวจ config ฝั่ง Keycloak ไม่ได้เปลี่ยน)
   - token endpoint ตอบ `invalid_client` 401 เมื่อส่ง secret ผิด · userinfo 401 เมื่อไม่มี token
   - **ไม่ automate happy path** (ต้อง browser) — claims จริง (email จาก scope `basic`) รอ client + test account จาก [ticket 06](./06-register-ku-home-client-on-sso-dev.md) แล้ว manual ตรวจครั้งเดียว
   - pacing/retry แบบเดียวกับ script remote อื่นใน repo (เลี่ยง WAF/429 ของ domain KU)
2. **Feature tests — ยืนยันทั้งชุด** (`Http::fake` Keycloak ผ่าน `Http` facade ตาม [ticket 05](./05-integration-approach-package-or-hand-rolled.md)):
   - ✔ สำเร็จ: exchange สร้างใหม่ → role `ku_member` + password สุ่ม (`Hash::check` ต้อง fail) · SSO ครั้งที่ 2 → find ของเดิม (ไม่ duplicate, ไม่ overwrite name)
   - ✔ split user: email ชนกับ password account → ได้ user ใหม่แยกกัน **ไม่ error** (ตาม [ticket 02](./02-sso-identity-to-local-user-mapping.md))
   - ✔ multi-device: SSO login ซ้ำ → token เก่ายังใช้ได้ (ไม่ revoke — [ticket 03](./03-sso-token-and-session-contract.md))
   - ✔ error map: `invalid_grant` → 422 (ไม่ retry) · userinfo ไม่มี email → 422 · `invalid_client` → 500 + `Log::error` · Keycloak 500/timeout → 502 · validation `code` → 422
   - ✔ logout SSO user → `currentAccessToken` ถูกลบ (**v1 ไม่มี END_SESSION ให้ทดสอบ — defer โดย ticket 03 แล้ว**)
   - ✔ throttle `5,1`: request ที่ 6 → 429
