# Implement: org booking fields + consumer ที่รองรับ user_id = null

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** [03-org-booking-shape-on-post-bookings](./03-org-booking-shape-on-post-bookings.md), [04-userless-booking-payment-and-front-desk](./04-userless-booking-payment-and-front-desk.md)
- **assignee:** (ว่าง)

## Question

ลงมือเขียนโค้ดตาม resolution ของ ticket 03 + 04 — หนึ่ง session จบ:

- migration บน `bookings` — field `organize` (รูปแบบตาม replaceability contract ของ ticket 01) + `customer_name` + `customer_phone`/`customer_email` (nullable)
- validation ใน `StoreBookingRequest` (โหมด org vs จองแทน user — mutually exclusive ตามที่ ticket 03 ตัดสิน) + `Booking::$fillable` + จุดแก้บังคับ consumer ของ `user_id = null` ครบชุดที่ ticket 03 ไล่ไว้
- flow หลังจองตาม ticket 04 (สลิปแทน / deadline / front desk / billing)
- tests ครอบโหมด org ทั้ง lifecycle + อัปเดต `docs/api_guide.md` + `docs/database-er.md` · จด design decision ลง `cline.md` · ปิด ticket เมื่อ `php artisan test` เขียว
