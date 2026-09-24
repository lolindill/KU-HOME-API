# 05: API contract รวมทุก flow — request/response, form requests, regression ของ flow เดิม

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** [01-payment-type-enum-and-schema](./01-payment-type-enum-and-schema.md), [02-deposit-semantics](./02-deposit-semantics.md), [03-deferred-payment-and-permissions](./03-deferred-payment-and-permissions.md), [04-amounts-layer-a-and-b](./04-amounts-layer-a-and-b.md)
- **assignee:** (ว่าง)

## Question

Design ล็อกครบจาก tickets 01–04 แล้ว — รวบเป็น contract ฉบับใช้ implement ก่อนลงมือ:

- **Write paths ที่โดน:** `POST /bookings` (ส่ง `payment_type`? ใครส่งได้), `POST /bookings/{id}/confirm` (ส่ง `amount` ชั้น A), `PUT booking-confirmations/{id}/verify|reject` (admin เห็น expected vs claimed แล้ว response รูปร่างไหน), flow เก็บส่วนที่เหลือตามที่ ticket 02/03 ตัดสิน (endpoint เดิมแก้ / endpoint ใหม่)
- **Read paths:** `GET /bookings` show/index ส่ง `payment_type` + ยอด (จ่ายแล้ว/ค้าง/ต้องชำระตอนนี้) กลับยังไง — booking ของคนอื่น (admin index) เห็นเท่ากันไหม
- **Form Requests:** `StoreBookingRequest` แก้ / `ConfirmBookingRequest` เพิ่ม rule ใหม่ — validation message ไทย+emoji ตามธรรมเนียม
- **Throttle + error shape เดิมคงไว้:** `5,1` login/booking/confirm — ไม่มีอะไรเปลี่ยน
- **Regression contract:** booking `full` ต้องวิ่ง flow เดิม 100% (สลิป → verify → paid → confirmed) — ระบุ explicit ว่าอะไรบ้างที่ "ห้ามเปลี่ยน" เพื่อให้ implement เขียน test กันพัง
- **Docs:** สรุปว่าต้องอัปเดต `docs/api_guide.md` ส่วนไหนบ้างตอน implement (ticket 06)
