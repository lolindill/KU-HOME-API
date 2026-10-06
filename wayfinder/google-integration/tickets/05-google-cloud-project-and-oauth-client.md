# จดทะเบียน Google Cloud project + OAuth client (ฝีมือคน)

- **label:** `wayfinder:task`
- **type:** HITL
- **status:** closed *(2026-10-06 — credentials ใช้งานได้จริง verify live ผ่าน)*
- **blocked-by:** 01-google-oidc-mechanics
- **assignee:** kevii (2026-10-06 — เตรียม checklist ให้ owner กดบน Cloud Console)

## Question

งานมือที่ owner ต้องกดเองบน Google Cloud Console (แนวเดียวกับ ticket 06 ของ map ku-sso ที่ไปขอ client จาก OCS) — ticket นี้จบเมื่อ credentials ใช้งานได้จริง:

- Checklist ที่ research ticket 01 จะเขียนไว้ให้: สร้าง project, ตั้ง OAuth consent screen (External + test users), สร้าง OAuth client ID แบบ **Web application**, กรอก authorized redirect URI ฝั่ง SPA (localhost http + port ตรง / production https ของ React)
- นำ `client_id` / `client_secret` ลง `.env` ตาม naming ที่จะตกลง (แนว `GOOGLE_SSO_CLIENT_ID` / `GOOGLE_SSO_CLIENT_SECRET` — เทียบ `KU_SSO_*` เดิม)
- Verify live แบบเดียวกับ ticket 06: ยิง authorize request จริง (PKCE S256) ผ่านหน้า login ของ Google ได้, callback รับ `code` กลับมาจริง
- จดผลลัพธ์ + ปัญหาที่เจอ (เช่น กฎ redirect URI ของ Google, หน้า "unverified app" ตอน Testing) ลง Resolution ของ ticket นี้

## Resolution (2026-10-06 — owner กดเองบน Cloud Console ตาม checklist ที่ agent เตรียม)

- **OAuth client สร้างแล้ว:** Web application client บน Google Cloud project ของ owner — `client_id` = `278825012656-b6v09d9uqu1nui343c96s0d32itsn336.apps.googleusercontent.com` · secret ลง `.env` เท่านั้น (ไม่จดที่อื่น)
- **Env naming ล็อกแล้ว (mirror `KU_SSO_*`):** `GOOGLE_SSO_CLIENT_ID` / `GOOGLE_SSO_CLIENT_SECRET` / `GOOGLE_SSO_REDIRECT_URI` · scope `openid email profile` hardcode ใน `config/google_sso.php` (ไม่ใช้ env)
- **`GOOGLE_SSO_REDIRECT_URI` = `https://www.ku-home.ku.ac.th`** (prod origin ของ frontend — ยังไม่มี route callback เฉพาะ) · ⚠️ ค้างไว้ให้ implementation/หน้างานจริงเลือก: จะคง root ไปเลย (SPA ต้อง handle `?code=` ที่หน้าแรก) หรือเพิ่ม path เฉพาะ เช่น `/google-callback` (ต้องเพิ่มใน Authorized redirect URIs ของ Console ด้วย — การแก้ค่าใช้เวลา 5 นาที–ไม่กี่ชม.) · ยังไม่ได้ลงทะเบียน `http://localhost:*/...` สำหรับ dev ถ้าจะ dev บน localhost ต้องเพิ่มก่อน
- **Verify live ผ่าน (owner ยืนยัน "i test and it work"):** agent generate authorize URL จริง (PKCE S256 + state) → owner ล็อกอิน Google → consent → callback รับ `code` กลับมาจริง ✅ ไม่เจอ unverified screen ตามที่ research คาด (basic scopes)
- Implementation ของ exchange endpoint ทำต่อทันทีใน session เดียวกัน (`GoogleSsoService` + `POST /api/v1/auth/sso/google/exchange` + tests 19 เคส — suite 647 passed) · ไฟล์ PKCE ที่ใช้ verify (`storage/app/google-sso-verify.json`) เป็น throwaway ลบได้
