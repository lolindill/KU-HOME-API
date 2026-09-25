# Admin จองแทน user — identity ของ user ปลายทาง และ audit ฝั่งผู้สร้าง

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** —
- **assignee:** kevii (session 2026-09-25)

## Question

Requirement จาก owner: "booking by admin → booking for user: add field string `user` — ตัว booking ต้อง link กับ user นั้น ไม่ใช่ admin ผู้สร้าง" — ปัจจุบัน `createBooking` ล็อก `user_id = คนล็อกอิน` เสมอ:

- **"string `user`" ระบุ user ปลายทางยังไง:** email (แล้ว lookup ยังไงภายใต้ split-user model — email เดียวมี account ได้หลาย provider ตาม `auth_provider`), UUID หรือ free-text? ถ้า user ยังไม่มี account ล่ะ — reject 422 หรือสร้าง account ให้?
- **Audit ฝั่งผู้สร้าง:** ตอนนี้ bookings ไม่มี `created_by` — เพิ่ม column เก็บ admin ผู้สร้างไหม? (`status_change_logs.causer_id` จับได้เฉพาะ transition ไม่ครอบ "ใครสร้างบิล")
- **ผลพวงที่ต้องย้ายฐานจาก auth user → target user** (ไล่ครบก่อนเขียนโค้ด):
  - เช็ค **1 draft ต่อ user** — นับที่ user ปลายทางหรือ admin?
  - **room cap** ของ non-admin (`BookingRule::roomCapRule`) — ใช้ role ของใคร
  - **ราคา daily_ku** (`GlobalRate::getEffectiveDailyRate` ดูจาก role ของคนล็อกอิน — admin จองแทนจะโดนเรท admin หรือเรทของ user ปลายทาง)
  - **ownership หลังจอง** — user ปลายทางต้องเห็น booking นี้ใน `GET /bookings` ของตัวเอง + ส่งสลิปเองได้
- **สิทธิ์:** เฉพาะ `role:admin` หรือรวม `staff`? และใช้ `source='admin'` ที่มีอยู่แล้วเป็น marker ของโหมดนี้เลยไหม

## Resolution (2026-09-25 — grilled กับ owner, รูปแบบเปลี่ยนจาก requirement เดิม)

> Owner ตัดสินใหม่: โหมด admin booking **ไม่บังคับ link กับ user account** — เป็น 2 โหมด + เฮดเปล่า โดย UI flow จริงคือ **สร้าง booking เฮดเปล่าก่อน แล้วค่อยเลือก user/organization ทีหลัง** (use case: group / monthly)

1. **สองโหมดบน `POST /bookings` (`source='admin'`, เฉพาะ `role:admin`):**
   - **โหมด A — จองแทน + link user account:** ส่ง field `user` = **UUID ของ user** → `bookings.user_id` = target user (ไม่ใช่ admin ผู้สร้าง) · ระบุด้วย UUID ไม่ใช่ email (ตัดปัญหา split-user model) · ไม่เจอ UUID → 422 · ไม่ auto-create account
   - **โหมด B — เฮดเปล่า/องค์กร:** ไม่ link account (`user_id = null`) — คนเข้าพักระบุด้วย string `customer_name` (ร่วม field set กับ org booking ของ ticket 03)
   - **เฮดเปล่าได้เต็มรูป:** admin สร้าง draft โดยไม่ส่งทั้ง `user` และ `organization`/`customer_name` ก็ผ่าน — การ attach org/user **ทีหลัง** = ticket ใหม่ [07-empty-head-attach-later](./07-empty-head-attach-later.md)
2. **ไม่เพิ่ม column `created_by`** — owner ยืนยันไม่ต้อง; ไม่กระทบส่วนอื่น (การสร้าง draft ไม่ได้เขียน status_change_log อยู่แล้ว เพราะ initial state ไม่ใช่ transition) — trace ผู้สร้างคร่าว ๆ จาก `source='admin'` + `causer_id` ของ transition ถัด ๆ ไป
3. **กฎผลพวงโหมด A (link user account):** draft-dedup **นับที่ target user** (admin มี draft ของตัวเองไม่ขวางการจองแทน) · **room cap** ใช้ role ของ target · **ราคา** ใช้ role ของ target — ⚠️ โค้ดปัจจุบัน `BookingController.php:1331` คิดจากคนล็อกอิน ต้องแก้เป็น target (จุดเสี่ยง daily_ku ที่แมป flag ไว้) · **ownership** = target user เห็นบิล + ส่งสลิปเองได้ + แก้ draft ได้
4. **กฎผลพวงโหมด B (เฮดเปล่า/องค์กร, `user_id=null`):** **ไม่มี draft-dedup** (org จองซ้อนหลายบิลได้ — group booking ปกติ) · ราคา `daily` ปกติเสมอ (ไม่มี role ให้ดู — ไม่มีทางโดน daily_ku) · admin ไม่โดน room cap · ownership เหลือแต่ admin ทุกจุด (โค้ด "เจ้าของหรือ admin" รองรับ null อยู่แล้ว)
5. **สิทธิ์:** เฉพาะ `role:admin` (ไม่รวม staff) + ใช้ `source='admin'` ที่มีอยู่แล้วเป็น marker ของโหมด — validate: ส่ง `user` ได้เฉพาะเมื่อ admin
