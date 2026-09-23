# จดทะเบียน Google Cloud project + OAuth client (ฝีมือคน)

- **label:** `wayfinder:task`
- **type:** HITL
- **status:** open
- **blocked-by:** 01-google-oidc-mechanics
- **assignee:** (ว่าง)

## Question

งานมือที่ owner ต้องกดเองบน Google Cloud Console (แนวเดียวกับ ticket 06 ของ map ku-sso ที่ไปขอ client จาก OCS) — ticket นี้จบเมื่อ credentials ใช้งานได้จริง:

- Checklist ที่ research ticket 01 จะเขียนไว้ให้: สร้าง project, ตั้ง OAuth consent screen (External + test users), สร้าง OAuth client ID แบบ **Web application**, กรอก authorized redirect URI ฝั่ง SPA (localhost http + port ตรง / production https ของ React)
- นำ `client_id` / `client_secret` ลง `.env` ตาม naming ที่จะตกลง (แนว `GOOGLE_SSO_CLIENT_ID` / `GOOGLE_SSO_CLIENT_SECRET` — เทียบ `KU_SSO_*` เดิม)
- Verify live แบบเดียวกับ ticket 06: ยิง authorize request จริง (PKCE S256) ผ่านหน้า login ของ Google ได้, callback รับ `code` กลับมาจริง
- จดผลลัพธ์ + ปัญหาที่เจอ (เช่น กฎ redirect URI ของ Google, หน้า "unverified app" ตอน Testing) ลง Resolution ของ ticket นี้
