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

## Not yet specified

- Contract จริงของ endpoint exchange (request/response field names, error codes 401/422/502) — รอ ticket 01 + 04
- การแก้ users table ถ้าจำเป็น (เก็บ keycloak `sub`? `password` nullable สำหรับ SSO-created user?) — รอ ticket 02 *(มี proposal draft รอ owner sign-off ใน ticket แล้ว — 2026-09-04: (ก) auth_provider + composite unique · password สุ่มทิ้ง · ไม่มี email = 422 · ไม่เก็บ sub)*
- 🆕 ถ้า proposal (ก) ของ ticket 02 ผ่าน: flow **password reset** ต้องรองรับ email ซ้ำข้าม provider (`password_reset_tokens` ใช้ email เป็น PK) + admin views ที่ไล่ user ด้วย email อย่างเดียวอาจเห็น duplicate — graduate หลัง ticket 02 ปิด
- env/config naming (`KU_SSO_*`?) + production realm endpoint — รอ ticket 04 + 06
- การประสานกับ React (รายชื่อ redirect URI ที่อนุมัติ, env sharing) — รอ ticket 06
- Remote integration script สำหรับ SSO (pattern `test_scripts/*_remote.php`) ต้องมีไหม — รอ ticket 07
- ยืนยัน claims จริงจาก userinfo ด้วย test account (โดยเฉพาะ `email` จาก scope `basic`) — รอ ticket 06 (client + test account)
- ถ้า ticket 08 เลือกเปลี่ยนโมเดล ku_member → ต้องวางแผน migrate ผู้ใช้ `role=ku_member` เดิม + จุดเช็คส่วนลดทุกที่ — รอ ticket 08

## Out of scope

- **Implementation ของ Google login** — effort ต่อยอดหลัง map นี้จบ · ตาม req change 2026-09-01 Google = **split user แยกต่างหาก** (ไม่มีการ link กับ account อื่น จึงไม่ต้องแชร์ schema พิเศษกับทาง KU)
- เปลี่ยน API auth ไป verify Keycloak access_token ตรงๆ (แทน Sanctum) — ขัด contract multi-client + logout semantics
- งาน roadmap อื่น: static dashboard / report templates / digital signature / WebSocket Phase B
- keycloak-admin user management (สร้าง/จัดการ user ใน realm ฝั่งเรา) — KU IT เป็นเจ้าของ realm
