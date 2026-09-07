# Flow password reset & admin view ภายใต้ email ซ้ำข้าม provider

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** —
- **assignee:** (ว่าง)
- **born:** 2026-09-04 — graduate จาก fog หลัง [ticket 02](./02-sso-identity-to-local-user-mapping.md) ปิดด้วย composite unique `(email, auth_provider)`

## Question

เมื่อ `users.email` ไม่ unique อีกต่อไป (เปลี่ยนเป็น composite `(email, auth_provider)` — ดู resolution ของ ticket 02) ประเด็นที่เคยอาศัย "email = user เดียว" ต้องตัดสินทั้งหมด:

- `password_reset_tokens` ใช้ **`email` เป็น PK** — flow reset จะระบุ/กรอง `auth_provider` ยังไง (form ให้เลือก provider? ส่งเฉพาะ `provider=password` เสมอเพราะ SSO user ไม่มี password ให้ reset?) เพื่อไม่ให้ reset ชี้ row ผิด
- admin views/lookup ที่ไล่ user ด้วย email อย่างเดียว (list users, assign role) จะโชว์/กรอง `auth_provider` ให้ admin เห็นไหม — หรือคงโชว์ duplicate email ตามจริง
- มี flow ไหนอีกที่เดา "email = user เดียว" บ้าง — implementation session ต้องไล่ตรวจตรงไหน (เช่น `AuthController`/`ForgotPassword`, notification ที่ส่งตาม email, lookup endpoints ที่ throttle `10,1`)

## ข้อเท็จจริงตั้งต้น (จาก ticket 02)

- SSO-created user มี password = สุ่มทิ้ง (`Str::random(64)`) → reset password สำหรับ `auth_provider=ku_sso` ไม่มีความหมาย (reset แล้วก็ยัง login ด้วย SSO อยู่ดี) — flow reset น่าจะ scope เฉพาะ `provider=password`
- `Auth::attempt` login เดิมปลอดภัยตามธรรมชาติ — ไม่ต้องแก้
