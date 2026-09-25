# Implement: org booking fields + consumer ที่รองรับ user_id = null

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **blocked-by:** [03-org-booking-shape-on-post-bookings](./03-org-booking-shape-on-post-bookings.md), [04-userless-booking-payment-and-front-desk](./04-userless-booking-payment-and-front-desk.md)
- **assignee:** kevii (implement 2026-09-25)

## Question

ลงมือเขียนโค้ดตาม resolution ของ ticket 03 + 04 — หนึ่ง session จบ:

- migration บน `bookings` — field `organize` (รูปแบบตาม replaceability contract ของ ticket 01) + `customer_name` + `customer_phone`/`customer_email` (nullable)
- validation ใน `StoreBookingRequest` (โหมด org vs จองแทน user — mutually exclusive ตามที่ ticket 03 ตัดสิน) + `Booking::$fillable` + จุดแก้บังคับ consumer ของ `user_id = null` ครบชุดที่ ticket 03 ไล่ไว้
- flow หลังจองตาม ticket 04 (สลิปแทน / deadline / front desk / billing)
- tests ครอบโหมด org ทั้ง lifecycle + อัปเดต `docs/api_guide.md` + `docs/database-er.md` · จด design decision ลง `cline.md` · ปิด ticket เมื่อ `php artisan test` เขียว

## ✅ Resolution (2026-09-25 — implemented, suite 588 เขียว)

**Migration `2026_09_25_120000_add_org_booking_fields_to_bookings_table`:** บน `bookings` — `organization_id` uuid nullable + **FK restrict** ไป organizations + snapshot 3 columns `customer_name` / `customer_phone` / `customer_email` (nullable) — ตาม ticket 03 (erp ไม่ถูกเก็บบน bookings — lookup เป็น FK ตาม replaceability contract ของ ticket 01)

**Validation + write path (`StoreBookingRequest` + `BookingController::createBooking`):**
- `organize` = erp code string (max 100) → lookup `organizations` — ไม่เจอ หรือ `is_active=false` → 422 · เก็บเป็น `organization_id`
- `user` × `organize` mutually exclusive 422 · `organize` บังคับ `customer_name` 422 · `organize` ต้องใช้ `source='admin'` 422 · non-admin ส่ง `organize`/`customer_name`/`customer_phone`/`customer_email` → 403
- `customer_name` โดยไม่มี `organize` = โหมด B เฮดเปล่าระบุผู้ติดต่อ — เก็บ snapshot ได้ (ไม่มี FK)
- `Booking::$fillable` + relation `Booking::organization()` + response ของ `POST /bookings` เพิ่ม 4 field (`organization_id` + snapshot)

**Consumer ของ `user_id = null` (ส่วนใหญ่ถูก implement ไว้แล้วใน ticket 05 — ยืนยันอีกชั้น):** ไม่มี draft-dedup · ราคา `daily` เสมอ (ผ่าน `$booking->user` = null) · ไม่โดน room cap · ownership admin · ชื่อ default — `Booking::getPrimaryGuestNameAttribute` ยืด fallback เป็น `user?->name` → `customer_name` → `'Customer'` (จุดที่ 2 ของ ticket 03)

**Flow หลังจอง (ticket 04) — ไม่ต้องเขียนโค้ดใหม่:** org booking = `deferred` เสมอ (สลิป block / อนุมัติ `draft → confirmed` / เก็บเงิน `recordPayment` — design ของแมป `booking-payment-types` implement จบแล้ว) · admin ส่งสลิปแทนบิลไร้ user (non-deferred) ผ่าน ownership guard เดิม · เฮดเปล่าโดน cleanup 15 นาที ขยายผ่าน `payment_deadline` บน `PUT /bookings/{id}` · front desk ใช้ term search เดิม · `customer_phone`/`email` เก็บติดต่อล้วน

**Docs + memory:** `docs/api_guide.md` (🏛️ Admin booking 3 โหมด + validation table + response shape) · `docs/database-er.md` (BOOKINGS block ครบ org + payment fields, เพิ่ม entity ORGANIZATIONS + relation) · design decision จด `cline.md` หัวข้อ "🏛️ Org booking fields"

**Tests:** `tests/Feature/OrgBookingTest.php` (12 เคส) — **suite เต็ม 588 passed (2190 assertions)** · Pint ผ่าน
