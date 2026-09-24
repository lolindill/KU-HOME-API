# 06: Implement — migration + code + tests, suite เขียว

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** [05-api-contract-all-flows](./05-api-contract-all-flows.md)
- **assignee:** (ว่าง)

## Question

ลงมือเขียนโค้ดตาม resolution ของ tickets 01–05 — หนึ่ง session จบ:

- migration ตามที่ design ล็อก: `bookings.payment_type` (+ backfill `full` ให้ของเดิม), `booking_confirmations.amount` (integer baht), ชั้น B ตาม ticket 04 — boolean ใช้ `PgBoolean` เสมอ · ถ้า migration เก่ารูปทรงเปลี่ยนใหญ่ จด "Migration Required" ลง `cline.md`
- code: model casts + controllers (`BookingController`, `BookingConfirmationController`, front-desk ตาม design) + Form Requests + hook ยอดใน `transitionStatus()` (ห้าม bypass state machine / audit log)
- tests ครอบ: flow `full` regression ไม่พังเลย · `deposit` (ส่งสลิปมัดจำ → verify → เหลือยอดค้าง → เก็บส่วนที่เหลือ) · `deferred` (admin อนุมัติข้าม paid / guard สิทธิ์) · reject แล้วส่งสลิปใหม่ (row ใหม่ + ยอดค้างถูกต้อง) · backfill ของเดิม
- `php artisan test` เขียวทั้ง suite ก่อนปิด ticket
- จด design decision ลง `cline.md` (protocol ใน AGENTS.md) + อัปเดต `docs/api_guide.md` ตามที่ ticket 05 ระบุ + append บรรทัด decision ใน map
