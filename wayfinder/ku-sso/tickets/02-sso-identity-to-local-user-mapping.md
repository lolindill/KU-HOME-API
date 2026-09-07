# จับคู่ SSO identity กับ local user account ยังไง

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed *(2026-09-04 — owner sign-off ครบทุกข้อ)*
- **blocked-by:** — *(ปลดบล็อก 2026-09-01: ticket 01 ปิดแล้ว — ผูกกับ ticket 08 ในเรื่องผลของ linkage ต่อ ku_member)*
- **assignee:** kevii (2026-09-04)

## Question

Diagram เสนอ "find-or-create User ด้วย email" — ต้องตัดสินให้รองครบ:

- จับคู่ด้วย **email** หรือด้วย keycloak **`sub`** (subject id)? ถ้าเก็บ sub ต้องมี column ใหม่ + migration
- ถ้า email ชนกับ password account เดิม — ~~link เข้า account เดิม (trust verified email)~~ *(❌ ตัดออกตาม req change 2026-09-01: ห้าม link)* เหลือทางเลือก: **ปฏิเสธ** ให้ใช้ password login แทน หรือ **อนุญาตให้ email ซ้ำต่อ provider** (ดูประเด็น unique ด้านล่าง)
- SSO-created user ควรมี `password` เป็นอะไร (nullable? สุ่มทิ้ง?) — กระทบ `Hash::check` ที่ login เดิม
- ถ้า userinfo ไม่คืน email จริง (diagram ตั้งคำถามไว้ใน card "ก่อน implement ต้องเช็ค") จะจับคู่ด้วยอะไรแทน — `preferred_username`? ปฏิเสธ login?
- ชื่อ-นามสกุลดึงจาก claims ไหน (`given_name`/`family_name`/`name`)? ใช้เติม field `name` เดิมอย่างเดียวหรือแยก?
- 🆕 **(req change 2026-09-01) split user ไม่มีการ link:** login ผ่านแต่ละ provider (password / KU SSO / Google อนาคต) = **คนละ user แยกกันโดยสิ้นเชิง** — ไม่ต้องมีตาราง `user_identities` แล้ว
- 🆕 **จุดชนที่ต้องตัดสิน (เช็ค schema จริงแล้ว):** `users.email` เป็น **unique** + `password` **NOT NULL** → split user เหลือทางเลือกหลัก:
  - **(ก) เพิ่ม `auth_provider` + composite unique** — column `auth_provider` (`password`|`ku_sso`|`google`) + เปลี่ยน unique เป็น `(email, auth_provider)` → email เดียวเป็นได้หลาย account ตาม provider · find-or-create ด้วย `(email, provider)`
  - **(ข) ไม่แต่ schema — ปฏิเสธเมื่อชน** — SSO เจอ email ที่มี password account อยู่แล้ว = 422 ให้ใช้ password login แทน (ง่ายสุด ไม่มี migration แต่คนที่เคยสมัครด้วย KU email จะใช้ SSOไม่ได้ตลอดชีวิต)
- 🆕 **(จาก ticket 01 + req change) ผลต่อสถานะสมาชิก:** owner โน้มว่า "เกิดจาก KU SSO = ได้สถานะ ku member (ส่วนลดอัตรา member)" — คำตอบของ [ticket 08](./08-ku-member-role-or-tag.md) กำหนดว่าตอน **สร้าง user จาก KU SSO** ต้องเขียนอะไรลง user (role? boolean `is_ku_member`? derive จาก `auth_provider`?)

## Resolution (2026-09-04 — owner sign-off ครบทุกข้อ)

> Proposal เดิม (agent draft) ได้รับการยืนยัน**ทีละข้อจาก owner ในแชท grilling** — ทุกข้อตรงกับตัวเลือกแนะนำ ไม่มีการแก้ รายละเอียดเหตุผลของแต่ละข้อคงไว้ด้านล่างตาม draft

- **สถานะ users schema:** **(ก) เพิ่ม `auth_provider`** (`string`, values `password`|`ku_sso`|`google`, default `password`, **users เดิมทุกคน = `password`**) + เปลี่ยน unique `email` → composite unique `(email, auth_provider)` · find-or-create ด้วย `(email, provider)`
  - เหตุผล: split-user เป็น standing model รวม Google อนาคต · (ข) ทำให้คนที่เคยสมัคร password ด้วยอีเมล KU ใช้ SSO ไม่ได้ตลอดชีวิต · migration เดียวจบ (SQLite local + PostgreSQL prod ทำ composite unique ได้เหมือนกัน)
- **password ของ SSO-created user:** **สุ่ม `Str::random(64)` ทิ้ง** (ไม่แก้ column NOT NULL)
  - เหตุผล: `Hash::check` ไม่มีทางผ่าน, login เดิมไม่ต้องแตะ, ไม่เกิด null-branch ใหม่ — ต่างจาก nullable ที่ต้องรีวิวทุกจุดที่แตะ password
- **userinfo ไม่คืน email:** **ปฏิเสธ login 422 fail-closed** (`{"status":"error", message ไทย+emoji}`) — ไม่เดา identity จาก `preferred_username` · รอ ticket 06 ได้ test account แล้วพิสูจน์ claims จริงของ scope `basic` ก่อน หากจริงๆ ไม่มี email ค่อยกลับมาตัดสินใหม่พร้อมหลักฐาน
- **เก็บ keycloak `sub`:** **ไม่เก็บ (YAGNI)** — split-user จับคู่ด้วย `(email, provider)` พอ · ถ้าอนาคตต้อง sync ข้อมูล KU ตรงๆ ค่อยเพิ่ม column ตอนนั้น (จด fog ไว้ใน map แล้ว)
- **Default เสริมที่เสนอ (low-stakes, ยังไม่เคยถาม):** `name` เติมจาก claim `name` เป็นอันดับแรก ไม่มีค่อย concat `given_name` + `family_name` · email normalize lowercase ก่อน find-or-create · `title`/`phone` เว้น null · ห้าม overwrite `name` ของ user เดิมตอน re-login (find path ไม่แตะ profile)
- **ผลต่อสถานะ ku member ตอนสร้าง user จาก KU SSO:** ✅ ตอบแล้วโดย [ticket 08](./08-ku-member-role-or-tag.md) (ปิด 2026-09-04) — สร้าง user ด้วย **role `ku_member`** (คู่กับ `auth_provider=ku_sso` ถ้า proposal (ก) ผ่าน sign-off)

**ประเด็นที่ proposal (ก) ทำให้ต้องจดต่อ (ตัดสินแล้ว — แตกเป็น ticket ใหม่):**
- `password_reset_tokens` ใช้ **`email` เป็น PK** → เมื่อ email ซ้ำได้ข้าม provider flow reset ต้องระบุ/กรอง `auth_provider` ด้วย ไม่งั้น reset ของ account password อาจไปชี้ row ที่ถูกต้องไม่ได้ → แตกเป็น ticket [09-password-reset-admin-views-duplicate-email](./09-password-reset-admin-views-duplicate-email.md) (รวมประเด็น admin view เห็น duplicate email ด้วย)
- `Auth::attempt` ที่ login เดิมยังปลอดภัยตามธรรมชาติ (SSO row มี password สุ่ม ไม่มีทาง match) — ไม่ต้องแก้อะไร
