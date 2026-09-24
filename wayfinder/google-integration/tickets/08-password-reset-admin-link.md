# 08: Password reset — admin ส่งลิงก์เปลี่ยนรหัส (REQ-001.3 — SRS v2)

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** ["04-email-use-cases-and-sender-identity"]
- **assignee:** (ว่าง)
- **born:** 2026-09-24 — graduate จาก gap ตรวจ SRS v2 (srs_room_booking_v2.pdf) ตามคำสั่ง owner "11 add in still going map" · เคยถูกตัดออกจาก v1 ใน [ku-sso ticket 09](../../ku-sso/tickets/09-password-reset-admin-views-duplicate-email.md) (2026-09-07: "ไม่มีใน v1") — มาเปิดใหม่เพราะ SRS ฉบับนี้ระบุชัด

## Question

REQ-001.3: "รองรับ Local user หากลืมรหัสผ่าน **แอดมินเป็นผู้ส่งลิงก์** สำหรับเปลี่ยนรหัสผ่าน"

ปัจจุบัน**ไม่มี flow reset เลย** (ไม่มี route/method — `password_reset_tokens` เป็น default migration ที่หลับอยู่) และมี spec note ค้างจาก ku-sso ticket 09:

- **scope ต่อ provider:** reset ต้องผูกเฉพาะ `auth_provider=password` เสมอ — SSO user (ku_sso/google) มี password สุ่มทิ้ง ไม่มีอะไรให้ reset · แต่ email เดียวมี account หลาย provider ได้ (split-user) — ลิงก์ reset ที่ส่งตาม email จะชี้ row ไหน (unique `(email, auth_provider)`)
- **"แอดมินเป็นผู้ส่งลิงก์"** แปลว่าอะไรใน API: admin สร้างลิงก์/token แล้วส่งเองนอกระบบ? หรือ admin trigger ให้ระบบเมลหา user (ผูกกับ Gmail transport ของแมปนี้ — ticket 04/07)? หรือมี flow self-serve ด้วย?
- **สิทธิ์:** admin เท่านั้น? staff ได้ไหม · user เปลี่ยนรหัสเองผ่านลิงก์โดยไม่ล็อกอิน (signed URL อายุเท่าไร)
- `password_reset_tokens` เป็น PK ด้วย email — ต้อง scope `auth_provider` ลงตาราง (คอลัมน์เพิ่ม) หรือใช้ token table แบบอื่น
- ใช้ Gmail transport ของแมปนี้ (ticket 03 — Option A) — จึง blocked-by ticket 04 (use cases + sender identity)

## Resolution

(ยังว่าง — รอ grilling คู่กับ owner)
