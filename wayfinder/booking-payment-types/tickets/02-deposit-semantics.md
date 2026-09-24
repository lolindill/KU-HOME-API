# 02: มัดจำ (deposit) semantics — %, ความหมายของ `paid`, เก็บส่วนที่เหลือเมื่อไหร่

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** [01-payment-type-enum-and-schema](./01-payment-type-enum-and-schema.md)
- **assignee:** (ว่าง)

## Question

Booking แบบมัดจำ — จ่ายบางส่วนตอนจอง ส่วนที่เหลือเก็บทีหลัง (excel-reports เคยพูดถึง "มัดจำ 50%") — ตัดสิน:

- **ยอดมัดจำ:** 50% ของ `total_amount` fixed? configurable (config/env แบบ `config/allocation.php`)? หรือยอดอิสระที่ admin กำหนดต่อ booking? — คำนวณที่ไหน (controller เดียวกันทุก write path ของ createBooking)
- **ความหมายของสถานะ:** สลิปมัดจำ verify ผ่าน → booking สถานะอะไร — `paid` ปัจจุบันแปลว่า "ชำระเต็ม" — จะเปลี่ยนความหมายเป็น "งวดที่ต้องชำระ ณ ตอนนี้ผ่านแล้ว" หรือคง `paid` ไว้กับเต็มจำนวนแล้วใช้ flag/สถานะอื่นแทนช่วง "มัดจำผ่านแล้ว รอเก็บส่วนที่เหลือ"
- **เก็บส่วนที่เหลือ เมื่อไหร่ ที่ไหน:** ตอน check-in (front desk เก็บสด)? ตอน check-out? หรือ user ส่งสลิปเพิ่มอีก row บน confirmations เดิม — ผลต่อ state machine หลัง `confirmed` (ปัจจุบันยังไม่มี transition ที่ "เก็บเงินเพิ่ม")
- **ผลต่อ state machine ของ BookingRoom:** check-in ก่อนจ่ายครบได้ไหม — เงื่อนไข guard ที่ front desk ต้องเจอ
- **`payment_deadline` 24 ชม. + `CleanupExpiredDrafts`:** booking มัดจำใช้ deadline เดียวกันไหม

⚠️ ยอดเงิน (ชั้น A/B) ตัดสินใน [ticket 04](./04-amounts-layer-a-and-b.md) — ticket นี้ล็อกแค่ "กลไกและเงื่อนไข" ให้ ticket 04 มีโจทย์
