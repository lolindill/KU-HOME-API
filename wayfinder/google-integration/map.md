# 🗺️ Wayfinder Map — Google Integration (Google Login + Gmail Email)

- **label:** `wayfinder:map`
- **status:** active
- **tracker:** local-markdown — tickets อยู่ใน `tickets/` ของ directory นี้ (blocking ระบุใน field `blocked-by` ของแต่ละ ticket)
- **charted:** 2026-09-11

## Destination

Spec ที่ **decision-lock ครบ 2 ชิ้น** ต่อยอดจาก map ku-sso: (1) **Google SSO login** (split-user, `auth_provider=google`) บนฐาน pattern exchange เดิมของ KU SSO, (2) **การส่งอีเมลออกจาก API ผ่าน Gmail** — จบเมื่อ implementation session เขียนโค้ดทั้งสองส่วนได้โดยไม่ต้องถาม product/security question เพิ่ม

## Notes

- ปูทางจาก map [ku-sso](../ku-sso/map.md) — standing decisions ที่ห้าม re-litigate ไม่มีหลักฐานใหม่:
  - **Split-user rule** (req change 2026-09-01): แต่ละ provider (password / KU SSO / Google) = user แยกกัน ไม่มีการ link identity — schema ดีไซน์ไว้แล้ว: `auth_provider` รับค่า `google` + composite unique `(email, auth_provider)`
  - **Hand-rolled `Http` facade + Service class (0 package)** — precedent ticket 05 ของ ku-sso (Socialite / JWT lib ไม่จำเป็น เพราะ identity ยืนยันผ่าน userinfo บน TLS)
  - Sanctum token policy เหมือน password login ทุกประการ · `RequireJsonAccept` → SSO callback อยู่ฝั่ง React เสมอ
- ✅ **โค้ด KU SSO merge เข้า `agust-11` แล้ว (2026-09-23 — suite 433 passed)** — เดิมจดไว้ว่าอยู่ใน worktree `.worktree/ku-sso-login` (`SsoController` + `app/Services/Sso/KuSsoService.php` + route `POST /auth/sso/exchange`) → implementation ของ Google login ต่อยอดบนฐาน merged ได้เลย ไม่ต้อง merge/rebase ก่อนแล้ว
- ธรรมเนียมเดิม: error shape `{"status":"error","message":...}` · message ไทย + emoji · throttle exchange-level = `5,1`
- 🆕 **(2026-09-24) ticket 08 เพิ่มจาก gap ตรวจ SRS v2:** [password reset — admin ส่งลิงก์เปลี่ยนรหัส (REQ-001.3)](./tickets/08-password-reset-admin-link.md) — เดิมถูกตัดจาก v1 (ku-sso ticket 09) กลับมาเปิดใหม่เพราะ SRS ระบุชัด · blocked-by ticket 04 (ต้องมี email transport ก่อน)
- ทำงาน ticket ละ session — เริ่มจาก frontier (open, blocked-by ปลดครบ, ไม่มี assignee)

## Decisions so far

- [Google OIDC mechanics สำหรับ login](./tickets/01-google-oidc-mechanics.md): flow ย้ายจาก Keycloak ได้ **1:1** (discovery live-verified 4 endpoints + client_secret_post) · PKCE ไม่ enforce แต่ส่ง S256 เป็น hardening · `email_verified` fail-closed (422) ใช้ได้ · **logout purely local** (ไม่มี end_session_endpoint) · Testing-mode 7-day refresh-token gotcha ไม่กระทบ (ไม่ขอ offline access) — รายละเอียดใน [research/google-oidc-mechanics.md](./research/google-oidc-mechanics.md)
- [แนวทาง integrate Google login](./tickets/02-google-login-integration-approach.md): **sibling `GoogleSsoService`** คู่ขนาน `KuSsoService` (0 package, 0 blast radius — generalize premature ที่ N=2) · endpoint แยก `POST /auth/sso/google/exchange` (throttle `5,1`) · **schema พร้อมรับ `google` 100% แล้วใน worktree ไม่ต้องแก้ DB** · 🚨 ต้อง build บน `feature/ku-sso-login` (โค้ด SSO ยังไม่ merge) · role ของ Google-born user รอ sign-off → [ticket 06](./tickets/06-google-user-role-and-exchange-contract.md) — รายละเอียดใน [research/google-login-integration-approach.md](./research/google-login-integration-approach.md)
- [วิธีส่งอีเมลผ่าน Gmail](./tickets/03-gmail-transport-options.md): **Option A — Gmail SMTP + App Password** (`smtp.gmail.com:587`, 0 new package, `Mail::queue` บน database queue + `afterCommit` + tries=3) · consumer limit ~500 ใบ/วัน (เกิน = lockout 1–24 ชม.) · ❗ ส่ง `From: @ku.ac.th` ผ่าน Gmail ปกติโดน DMARC + Google จะ deprecate "Send as" third-party ม.ค. 2027 → **v1 ใช้ gmail.com sender + `Reply-To` KU** — รายละเอียดใน [research/gmail-transport-options.md](./research/gmail-transport-options.md)

## Not yet specified

- TS integration guide ฝั่ง React (redirect flow + PKCE + รายชื่อ redirect URI ที่จะ register ใน ticket 05) — รอ ticket 06 sign-off contract แล้วเขียนตอน implementation (เทียบได้กับ guide ของ KU SSO ใน worktree)
- ส่งเมลหา user ที่เกิดจาก KU SSO ฝั่งนิสิต (ไม่มี claim `email` มีแต่ `google-mail`) — เมลจะส่งไป address ไหน เมื่อ use case จาก ticket 04 ลงตัว

## Out of scope

- Link/merge identity ระหว่าง provider (Google ↔ KU SSO ↔ password) — ขัด split-user rule
- Email provider อื่นที่ไม่ใช่ Gmail (SES / Mailgun / Postmark / SMTP ของ KU) — จดเปรียบเทียบใน research ได้ แต่ถ้าจะใช้จริงต้อง redraw destination ก่อน
- Re-litigate standing decisions ของ auth (Sanctum cookie mode, verify Keycloak JWT ตรง, CORS `['*']`) — เหมือน map ku-sso
