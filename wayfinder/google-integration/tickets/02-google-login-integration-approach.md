# แนวทาง integrate Google login — reuse/generalize จาก KuSsoService ได้แค่ไหน

- **label:** `wayfinder:research`
- **type:** AFK
- **status:** closed *(2026-09-11 — research subagent, อ่านโค้ดจริงจาก worktree)*
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

ตั้งต้นจาก standing decisions ของ map ku-sso (ticket 01/02/03/05 — split-user, `auth_provider` รวม `google`, token contract, hand-rolled 0-package) และโค้ดจริงที่ implemented แล้วใน worktree `.worktree/ku-sso-login` (branch `feature/ku-sso-login`, **ยังไม่ merge**):

- โครงสร้างโค้ดจริงตอนนี้: `SsoController` + `app/Services/Sso/KuSsoService.php` + `app/Services/Sso/Exceptions/` + route `POST /auth/sso/exchange` (throttle `5,1`) — อ่านแล้วสรุปว่าส่วนไหน generalize ตรงไหนที่ผูกกับ Keycloak แน่น
- ทางเลือกทางสถาปัตยกรรม: (a) `GoogleSsoService` เป็น sibling class เลียนรูปแบบ KuSsoService (duplicate แต่ชัด), (b) generalize เป็น base/abstract (interface `SsoProvider`), (c) ใช้ package เช่น Socialite — ชั่งน้ำหนักตาม precedent ticket 05 (0-package, `Http::fake` ทดสอบได้)
- Schema: ยืนยันใน worktree ว่า `users.auth_provider` + composite unique `(email, auth_provider)` รองรับ `google` อยู่แล้วจริง — ถ้ามีช่องว่าง (เช่น validation ที่ hardcode `ku_sso`) ให้จดจุดที่ต้องแก้
- `email_verified` claim ของ Google — ควรใช้เป็นเงื่อนไข fail-closed (เหมือน ku-sso ticket 02 ที่ userinfo ไม่คืน email = 422) หรือไม่
- Endpoint ฝั่งเรา: รูปทรงที่เสนอได้ (เช่น `POST /auth/sso/google/exchange` แยก หรือ รวม `POST /auth/sso/exchange` รับ `provider`), SPA ต้องส่ง `code_verifier` มาเหมือน KU (PKCE S256 enforced ฝั่ง Google เหมือนกันไหม — ผูกกับ ticket 01 ของ map นี้)
- Flow ความต่างจาก KU SSO: Google ไม่มี END_SESSION แบบ browser redirect แบบ Keycloak (แค่ revoke token), consent screen โหมด Testing กระทบ UX แค่ไหน (หน้า "unverified app" warning)
- กลยุทธ์ทดสอบ: `Http::fake` ครอบชุดเคสไหนบ้าง (เหมือน ku-sso ticket 07 — สร้างใหม่/split-user/multi-device/error map/throttle)
- ความเสี่ยง: จุดที่ duplicate user ข้าม provider (Google email เดียวกับ KU `google-mail` claim = split-user 2 account โดยดีไซน์ — ผลกระทบอะไรบ้าง, ผูกกับ fog ของ map)

## Resolution (2026-09-11)

ดูรายงานเต็ม: [../research/google-login-integration-approach.md](../research/google-login-integration-approach.md)

- **สถาปัตยกรรม: sibling `GoogleSsoService` คู่ขนาน `KuSsoService` (ทางเลือก a)** — 0 package, 0 blast radius กับ KU SSO ที่ live-verify ผ่านแล้ว · generalize เป็น base/interface premature ที่ N=2 (rule of three) · Google สั้นกว่า KU (~140–160 บรรทัด) เพราะไม่ต้องมี email/name fallback chain (standard claims ตรง) · reuse exception 4 ตัวเดิมได้เลย
- **Endpoint: แยก route `POST /api/v1/auth/sso/google/exchange` + `throttle:5,1`** — ไม่รวม provider-param บน route เดิม (KU contract ใน api_guide ห้ามแตะ) · response/error map mirror ku-sso ticket 03 + เพิ่ม `redirect_uri_mismatch`→500 · SPA ส่ง `code_verifier` มาเหมือนเดิม (PKCE S256 relay)
- **ข่าวดี: schema/validation/admin views พร้อมรับ `google` 100% แล้วใน worktree** — composite unique `(email, auth_provider)` + ticket 09 fixes ครบ ไม่ต้องแก้ DB เลย
- **`email_verified` fail-closed:** Google ใช้ได้และควรใช้ (422 เมื่อไม่ verified) — เช็ค boolean แบบ tolerant
- 🚨 **Merge order:** โค้ด SSO ทั้งชั้นอยู่แค่ใน `feature/ku-sso-login` (นำ main 12 commits) → Google ต้อง **build บนฐาน branch นั้นเสมอ**
- ⚠️ **Open decision → graduate เป็น [ticket 06](./06-google-user-role-and-exchange-contract.md):** role ของ Google-born user — research แนะ `user` **ไม่ใช่** `ku_member` (Google ไม่พิสูจน์สถานะ KU · ku_member ผูกส่วนลด `daily_ku`) — ต่างจาก KU SSO ที่ได้ `ku_member` ตาม ku-sso ticket 08 · รอ owner sign-off ก่อนเขียนโค้ด
- Throttle nuance: `throttle:5,1` key = `domain|ip` → login/KU/Google exchange แชร์ bucket ต่อ IP (เป็นพฤติกรรมเดิมอยู่แล้ว ไม่ใช่ regression)
