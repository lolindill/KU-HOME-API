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
- [Role ของ Google-born user + contract ของ exchange endpoint](./tickets/06-google-user-role-and-exchange-contract.md): **owner sign-off ครบ 7 ข้อ (2026-10-06)** — contract อิง KU SSO ทุกอย่าง (`POST /api/v1/auth/sso/google/exchange` public + throttle `5,1`, body `code`+`code_verifier`, response 6 keys mirror, email fail-closed 422, error map 422/500/502) **ยกเว้น role = `user`** (ไม่ใช่ `ku_member` — Google ไม่พิสูจน์สถานะ มก. ป้องกันส่วนลด `daily_ku` หลุด) — รายละเอียดใน Resolution ของ ticket 06
- [จดทะเบียน Google Cloud project + OAuth client](./tickets/05-google-cloud-project-and-oauth-client.md): **credentials ใช้งานได้จริง (2026-10-06)** — env naming `GOOGLE_SSO_CLIENT_ID/SECRET/REDIRECT_URI` (mirror `KU_SSO_*`) · redirect_uri จดเป็น prod origin `https://www.ku-home.ku.ac.th` (ค้างเลือก: root หรือ path เฉพาะ `/google-callback` — ดู Resolution) · verify live ผ่าน (authorize PKCE S256 → consent → callback รับ `code`)
- **✅ Implementation Google login แล้ว (2026-10-06, session เดียวกับ ticket 05):** `GoogleSsoService` sibling `KuSsoService` + `config/google_sso.php` + `POST /api/v1/auth/sso/google/exchange` + `GoogleSsoExchangeTest` 19 เคส — suite 647 passed · docs/api_guide.md มี section แล้ว

## 🧊 Frozen (2026-10-06 — owner decision ใน session)

- **งานฝั่งเมลทั้งหมดพักไว้ก่อน** — frontend senior gen booking confirmation/slip ฝั่ง frontend อยู่แล้ว backend ส่งเมลไม่จำเป็นในตอนนี้ · ครอบคลุม tickets [04-email-use-cases](./tickets/04-email-use-cases-and-sender-identity.md), [07-gmail-sender-account](./tickets/07-gmail-sender-account-setup.md), [08-password-reset](./tickets/08-password-reset-admin-link.md) (status คง `open` แต่ไม่อยู่บน frontier ที่ต้องรีบ — กลับมาทำเมื่อ owner สั่ง) · fog หัวข้อ "ส่งเมลหา user ที่เกิดจาก KU SSO" หนาวไปด้วย

## Not yet specified

- TS integration guide ฝั่ง React (redirect flow + PKCE) — contract กับ credentials ครบแล้ว (ticket 06 sign-off + ticket 05 closed 2026-10-06): `client_id` จริงมีแล้ว, redirect_uri คือ `https://www.ku-home.ku.ac.th` (ค้างตัดสิน root vs path `/google-callback` — ดู Resolution ticket 05) · เขียนตอน frontend implementation (เทียบได้กับ guide ของ KU SSO ใน `docs/sso-frontend-guide.md`) · ต้องเพิ่ม dev `http://localhost:<port>/...` ใน Console ก่อน dev จริง
- 🧊 ส่งเมลหา user ที่เกิดจาก KU SSO ฝั่งนิสิต (ไม่มี claim `email` มีแต่ `google-mail`) — หนาวไปกับงานเมลทั้งชุด (ดู Frozen)

## Out of scope

- Link/merge identity ระหว่าง provider (Google ↔ KU SSO ↔ password) — ขัด split-user rule
- Email provider อื่นที่ไม่ใช่ Gmail (SES / Mailgun / Postmark / SMTP ของ KU) — จดเปรียบเทียบใน research ได้ แต่ถ้าจะใช้จริงต้อง redraw destination ก่อน
- Re-litigate standing decisions ของ auth (Sanctum cookie mode, verify Keycloak JWT ตรง, CORS `['*']`) — เหมือน map ku-sso
