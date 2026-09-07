# 🗺️ Wayfinder Map — KU SSO (Keycloak) Integration

- **label:** `wayfinder:map`
- **status:** active
- **tracker:** local-markdown — tickets อยู่ใน `tickets/` ของ directory นี้ (blocking ระบุใน field `blocked-by` ของแต่ละ ticket)
- **charted:** 2026-09-01

## Destination

Spec ที่ **decision-lock ครบ** สำหรับการต่อ KU SSO (Keycloak, realm `KU-Alllogin` บน `sso-dev.ku.ac.th`) เข้ากับระบบ auth แบบ Sanctum token ของ KU HOME API — จบเมื่อ implementation session ลงมือเขียนโค้ดได้โดยไม่ต้องถาม product/security question เพิ่มอีก

## Notes

- อ่าน [`ku-sso-sanctum-flow.json`](./ku-sso-sanctum-flow.json) ก่อนทุก session — design artifact ตั้งต้น (browser รับ code จาก Keycloak → backend exchange → Sanctum token → logout 2 ชั้น) *(2026-09-04 ย้ายจาก `docs/` มาอยู่รวมกับ map — ดู convention ใน AGENTS.md หัวข้อ "Wayfinder maps")*
- **Standing decisions (ห้าม re-litigate ไม่มีหลักฐานใหม่):**
  - API auth = **Sanctum token mode stateless** — ไม่เปลี่ยนไป verify Keycloak JWT ตรงๆ (ขัด multi-client contract ใน AGENTS.md)
  - `RequireJsonAccept` เป็น global middleware → **SSO callback ต้องอยู่บน React** ไม่ใช่ route ของ API (browser ส่ง `Accept: text/html` โดน 406)
  - Sanctum token **ไม่หมดอายุ** (`expiration: null`) + `createToken()` ออก token ใหม่เสมอ ไม่ revoke ของเดิม (multi-device by design)
  - `logout` ลบเฉพาะ `currentAccessToken()` เท่านั้น
  - CORS = `allowed_origins: ['*']`, `supports_credentials: false` — ห้ามเปลี่ยนเป็น cookie mode
- ก่อนเขียนโค้ดจริง: จด design decision + lib choice ลง `cline.md` ตาม protocol ใน AGENTS.md
- ธรรมเนียม: error shape `{"status":"error","message":...}` · message ไทย + emoji · throttle login-level = `5,1`
- ทำงาน ticket ละ session — เริ่มจาก frontier (ticket open, ไม่มี blocked-by ค้าง, ยังไม่มี assignee)

## Decisions so far

- [Keycloak mechanics ของ realm KU-Alllogin](./tickets/04-keycloak-mechanics-for-ku-alllogin.md): discovery จริง verify live แล้ว (4 endpoints + issuer), code ใช้ครั้งเดียว ~60 วิ, END_SESSION เป็น browser redirect + ต้อง register post_logout_redirect_uri — รายละเอียดใน [research/keycloak-mechanics.md](./research/keycloak-mechanics.md)
- [แนวทาง integrate: hand-rolled หรือ package](./tickets/05-integration-approach-package-or-hand-rolled.md): **hand-rolled `Http` facade + Service class (0 package)** — ไม่ต้อง verify JWT เอง (userinfo บน TLS พอ) + `Http::fake` ทดสอบได้น่าแท้ — รายละเอียดใน [research/integration-approach.md](./research/integration-approach.md)
- [ใคร login ผ่าน KU SSO บ้างใน v1](./tickets/01-users-who-signs-in-via-ku-sso.md): **โหมดคู่ขนาน** (SSO เสริม ไม่ปิด password) · `POST /register` **เปิดต่อ** · default role ตอนแรกเข้า **ยกไปตัดสินใน ticket 08** · ⚠️ **req change ภายหลัง (2026-09-01): ไม่มีการ link identity — แต่ละ provider (password/KU SSO/Google) = split user คนละ account** (ดู Amendment ใน ticket)
- [ku_member ควรเป็น role, tag/boolean หรือ derive จาก KU linkage](./tickets/08-ku-member-role-or-tag.md): **ยืนเป็น role** — source of truth เดียวของสถานะสมาชิก · **first login KU SSO = สร้าง user role `ku_member`** (ปิดปม default role จาก ticket 01) · boolean `is_ku_member` เป็น legacy ไม่เขียนเพิ่ม (owner decision 2026-09-04)
- [จับคู่ SSO identity กับ local user account](./tickets/02-sso-identity-to-local-user-mapping.md): **(ก) เพิ่ม `auth_provider` (`password`\|`ku_sso`\|`google`) + composite unique `(email, auth_provider)`** · password ของ SSO user = สุ่ม `Str::random(64)` ทิ้ง · userinfo ไม่คืน email = 422 fail-closed · **ไม่เก็บ** keycloak `sub` · name จาก claim `name`→concat · email lowercase · re-login ไม่ overwrite · SSO-born user = role `ku_member` (owner sign-off ครบทุกข้อ 2026-09-04)
- [Contract ของ token & session หลัง SSO exchange](./tickets/03-sso-token-and-session-contract.md): KU tokens **ทิ้งหมดไม่เก็บ** (ไม่ขอ refresh_token) · Sanctum นโยบายเดียวกับ password login ทุกประการ · **END_SESSION ยังไม่ทำใน v1** (logout แค่ฝั่ง Sanctum — post_logout_redirect_uri ไม่ต้อง register) · exchange = `POST /auth/sso/exchange` คืน `{status, message, access_token, token_type, user, id_token}` + error map (invalid_grant→422 · invalid_client→500 · Keycloak ล่ม→502) (owner sign-off 2026-09-07)
- [กลยุทธ์ทดสอบ SSO โดยไม่มี browser](./tickets/07-sso-test-strategy.md): PHPUnit `Http::fake` ครอบชุดเคส 11 ข้อ (สำเร็จ/สร้างใหม่ vs find/split-user/multi-device/error map/throttle) · มี remote script **แบบ smoke** เฉพาะส่วนที่ไม่ต้อง login จริง — happy path/claims จริงรอ ticket 06 แล้ว manual ตรวจ (owner sign-off 2026-09-07)
- [Flow password reset & admin view ภายใต้ email ซ้ำข้าม provider](./tickets/09-password-reset-admin-views-duplicate-email.md): reset **ไม่มีใน v1** (ระบบไม่มี flow อยู่แล้ว — จด spec note ว่าอนาคตต้อง scope `auth_provider=password`) · admin list **โชว์ `auth_provider` + filter `?auth_provider=`** · จุดแก้บังคับที่ไล่เจอ: `login` ต้องกรอง `auth_provider=password` · `register` unique ต้อง scope ต่อ provider (owner sign-off 2026-09-07)

## Not yet specified

- env/config naming (`KU_SSO_*`?) + production realm endpoint — รอ ticket 04 + 06 *(04 ปิดแล้ว — เหลือรอ ticket 06)*
- การประสานกับ React (รายชื่อ redirect URI ที่อนุมัติ, env sharing) — รอ ticket 06
- ยืนยัน claims จริงจาก userinfo ด้วย test account (โดยเฉพาะ `email` จาก scope `basic`) — รอ ticket 06 (client + test account)
- ชะตา column `users.is_ku_member` หลัง ticket 08 ปิด (role เป็น source of truth): drop หรือ freeze ไม่เขียน — ตัดสินตอนเขียน spec implementation

## Out of scope

- **Implementation ของ Google login** — effort ต่อยอดหลัง map นี้จบ · ตาม req change 2026-09-01 Google = **split user แยกต่างหาก** (ไม่มีการ link กับ account อื่น จึงไม่ต้องแชร์ schema พิเศษกับทาง KU)
- เปลี่ยน API auth ไป verify Keycloak access_token ตรงๆ (แทน Sanctum) — ขัด contract multi-client + logout semantics
- งาน roadmap อื่น: static dashboard / report templates / digital signature / WebSocket Phase B
- keycloak-admin user management (สร้าง/จัดการ user ใน realm ฝั่งเรา) — KU IT เป็นเจ้าของ realm
