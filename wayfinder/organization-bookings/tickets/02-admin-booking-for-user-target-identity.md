# Admin จองแทน user — identity ของ user ปลายทาง และ audit ฝั่งผู้สร้าง

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** —
- **assignee:** (ว่าง)

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
