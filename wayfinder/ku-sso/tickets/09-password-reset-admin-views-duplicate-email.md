# Flow password reset & admin view ภายใต้ email ซ้ำข้าม provider

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed *(2026-09-07 — owner sign-off ในแชท grilling)*
- **blocked-by:** —
- **assignee:** kevii (2026-09-07)
- **born:** 2026-09-04 — graduate จาก fog หลัง [ticket 02](./02-sso-identity-to-local-user-mapping.md) ปิดด้วย composite unique `(email, auth_provider)`

## Question

เมื่อ `users.email` ไม่ unique อีกต่อไป (เปลี่ยนเป็น composite `(email, auth_provider)` — ดู resolution ของ ticket 02) ประเด็นที่เคยอาศัย "email = user เดียว" ต้องตัดสินทั้งหมด:

- `password_reset_tokens` ใช้ **`email` เป็น PK** — flow reset จะระบุ/กรอง `auth_provider` ยังไง (form ให้เลือก provider? ส่งเฉพาะ `provider=password` เสมอเพราะ SSO user ไม่มี password ให้ reset?) เพื่อไม่ให้ reset ชี้ row ผิด
- admin views/lookup ที่ไล่ user ด้วย email อย่างเดียว (list users, assign role) จะโชว์/กรอง `auth_provider` ให้ admin เห็นไหม — หรือคงโชว์ duplicate email ตามจริง
- มี flow ไหนอีกที่เดา "email = user เดียว" บ้าง — implementation session ต้องไล่ตรวจตรงไหน (เช่น `AuthController`/`ForgotPassword`, notification ที่ส่งตาม email, lookup endpoints ที่ throttle `10,1`)

## ข้อเท็จจริงตั้งต้น (จาก ticket 02)

- SSO-created user มี password = สุ่มทิ้ง (`Str::random(64)`) → reset password สำหรับ `auth_provider=ku_sso` ไม่มีความหมาย (reset แล้วก็ยัง login ด้วย SSO อยู่ดี) — flow reset น่าจะ scope เฉพาะ `provider=password`
- `Auth::attempt` login เดิมปลอดภัยตามธรรมชาติ — ไม่ต้องแก้

## Resolution (2026-09-07 — owner sign-off ในแชท grilling)

1. **password reset — ไม่มีใน v1 (จด spec note):** ตรวจโค้ดจริงแล้วระบบ**ไม่มี flow reset เลย** (ไม่มี route/method, `password_reset_tokens` เป็นแค่ default migration ที่หลับอยู่) — ไม่ขยายขอบเขตสเปค SSO · 📌 **spec note: เมื่อสร้าง flow reset ในอนาคต ต้อง scope เฉพาะ `auth_provider=password`** (SSO user มี password แบบสุ่มทิ้ง — ไม่มีอะไรให้ reset)
2. **admin user list (GET /users) — โชว์ + filter:** `auth_provider` serialize ออกใน response ตาม schema ใหม่ของ [ticket 02](./02-sso-identity-to-local-user-mapping.md) + เพิ่ม query param `?auth_provider=` ให้ admin กรอง — เห็น email ซ้ำแล้วแยกกันได้ทันที
3. **จุดแก้บังคับที่ไล่เจอจากโค้ดจริง** (ตามมาจาก composite unique ของ (ก) โดยตรง — ไม่มีทางเลือกอื่น บันทึกเป็นส่วนหนึ่งของสเปค):
   - `AuthController::login` (AuthController.php:85) ใช้ `User::where('email')->first()` → ต้องเพิ่ม `where('auth_provider','password')` — ไม่งั้น password login อาจไปเจอ row ของ SSO account (password สุ่ม) แล้ว 401 ทั้งที่ password account มีอยู่จริง (กรณี SSO user เกิดก่อน)
   - `StoreUserRequest` ใช้ `unique:users,email` (ทั้งตาราง) → ต้อง scope unique เฉพาะ `auth_provider=password` เช่น `Rule::unique('users','email')->where(fn ($q) => $q->where('auth_provider','password'))` — ไม่งั้นคนที่มี SSO account อยู่แล้วจะ register password account ด้วย email เดียวกันไม่ได้ ทั้งที่ split-user อนุญาต
   - จุดอื่นที่ไล่ user ด้วย `where('email')` ทั่วระบบ — implementation session ต้อง grep ไล่ audit ทั้งหมดว่าควร scope provider หรือต้องการทุก provider จริง
