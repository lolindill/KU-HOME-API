# ใคร login ผ่าน KU SSO บ้างใน v1 — และชะตากรรมของ password login + /register

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed *(2026-09-01 — grilling กับ owner)*
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

SSO เปิดให้ใครใช้ใน v1?

- ทุก role หรือเฉพาะ `user`/`ku_member`/`guest` — ขณะที่ `admin`/`staff`/`housekeeping`/`system` ใช้ password login ต่อไป?
- ผู้ใช้ KU ที่ SSO login ครั้งแรก (ยังไม่มี account) ควรได้ default role อะไร — `user` หรือ `ku_member`? (diagram บอก "role default" + admin assign เพิ่มทีหลังด้วย endpoint ที่มีอยู่ — commit `06e6c43`)
- public `POST /register` ยังเปิดสำหรับ guest คนนอก KU อยู่ไหม หรือปิดทิ้ง?
- Frontend มีทั้งปุ่ม "login ด้วย KU Account" และฟอร์ม email/password คู่กันไหม?

## Resolution (2026-09-01 — grilling กับ owner)

- **โหมดคู่ขนาน:** SSO เป็นทางเลือกเสริมคู่กับ email/password เดิม — ไม่บังคับ ไม่ปิด flow เดิม · 🆕 **แผนต่อยอดของ owner: 1 account ผูกได้หลาย provider (KU ตอนนี้ + Google/Gmail ในอนาคต)** ~~→ identity model ต้อง provider-agnostic ตั้งแต่ต้น~~ *(⚠️ ถูกแทนที่โดย Amendment ด้านล่าง)*
- **Default role ตอน SSO ครั้งแรก — ยังไม่ lock:** คำตอบตั้งต้นคือ `ku_member` แต่ owner ตั้งคำถามกับดีไซน์เองว่า *"ku_member มีผลจริงแค่กับส่วนลดอัตรา member — จำเป็นต้องเป็น role ไหม? user เป็น ku_member ได้ถ้า link account กับ KU login"* → แตกเป็น ticket [08-ku-member-role-or-tag](./08-ku-member-role-or-tag.md) ตัดสินก่อน *(🔁 ปิดแล้ว 2026-09-04: ticket 08 ตัดสิน — first login KU SSO = สร้าง user role `ku_member`)*
- **`POST /register`: เปิดต่อ** สำหรับ guest คนนอก — ไม่เปลี่ยน

### 📋 Amendment (2026-09-01 — requirement change จาก owner)

- ❌ ยกเลิกแนวคิด "1 account ผูกหลาย provider" — **ไม่มีการ link identity ข้าม provider (no link id)**
- ✅ ใหม่: login ด้วย **KU SSO หรือ Google แต่ละทาง = นับเป็นคนละ user (split user)** — SSO-KU user, Google user (อนาคต), และ password user เป็น account แยกกันสนิท
- ผลกระทบ: ไม่ต้องมีตาราง `user_identities` เลย (ง่ายขึ้น) · แต่เปิดคำถามใหม่หนักใน [ticket 02](./02-sso-identity-to-local-user-mapping.md): ปัจจุบัน `users.email` เป็น **unique** แล้ว split user จะอยู่ร่วมกันยังไง
