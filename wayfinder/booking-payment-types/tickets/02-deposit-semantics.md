# 02: มัดจำ (deposit) semantics — %, ความหมายของ `paid`, เก็บส่วนที่เหลือเมื่อไหร่

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** [01-payment-type-enum-and-schema](./01-payment-type-enum-and-schema.md)
- **assignee:** kevii (claimed 2026-09-25)

## Question

Booking แบบมัดจำ — จ่ายบางส่วนตอนจอง ส่วนที่เหลือเก็บทีหลัง (excel-reports เคยพูดถึง "มัดจำ 50%") — ตัดสิน:

- **ยอดมัดจำ:** 50% ของ `total_amount` fixed? configurable (config/env แบบ `config/allocation.php`)? หรือยอดอิสระที่ admin กำหนดต่อ booking? — คำนวณที่ไหน (controller เดียวกันทุก write path ของ createBooking)
- **ความหมายของสถานะ:** สลิปมัดจำ verify ผ่าน → booking สถานะอะไร — `paid` ปัจจุบันแปลว่า "ชำระเต็ม" — จะเปลี่ยนความหมายเป็น "งวดที่ต้องชำระ ณ ตอนนี้ผ่านแล้ว" หรือคง `paid` ไว้กับเต็มจำนวนแล้วใช้ flag/สถานะอื่นแทนช่วง "มัดจำผ่านแล้ว รอเก็บส่วนที่เหลือ"
- **เก็บส่วนที่เหลือ เมื่อไหร่ ที่ไหน:** ตอน check-in (front desk เก็บสด)? ตอน check-out? หรือ user ส่งสลิปเพิ่มอีก row บน confirmations เดิม — ผลต่อ state machine หลัง `confirmed` (ปัจจุบันยังไม่มี transition ที่ "เก็บเงินเพิ่ม")
- **ผลต่อ state machine ของ BookingRoom:** check-in ก่อนจ่ายครบได้ไหม — เงื่อนไข guard ที่ front desk ต้องเจอ
- **`payment_deadline` 24 ชม. + `CleanupExpiredDrafts`:** booking มัดจำใช้ deadline เดียวกันไหม

⚠️ ยอดเงิน (ชั้น A/B) ตัดสินใน [ticket 04](./04-amounts-layer-a-and-b.md) — ticket นี้ล็อกแค่ "กลไกและเงื่อนไข" ให้ ticket 04 มีโจทย์

## ✅ Resolution (2026-09-25 — grilling กับ owner ผ่าน AskUserQuestion)

1. **ยอดมัดจำ:** **admin กำหนดเองต่อ booking — default 50%** (ถ้า admin ไม่ระบุ) · รูปร่าง field (เก็บ % หรือยอดบาท, column ไหน, validate อย่างไร) = โจทย์ของ [ticket 04](./04-amounts-layer-a-and-b.md) — ทุก write path ของ `createBooking` คำนวณจากกลไกเดียวกัน
2. **ความหมายของ `paid`:** **`paid` = "งวดที่ต้องชำระ ณ ตอนนี้ผ่านแล้ว"** — สลิปมัดจำ verify ผ่าน → `pending → paid → confirmed` เหมือน flow เดิมทุกอย่าง (ไม่เพิ่ม state ใหม่) แต่ **`is_paid` ยังไม่ set** — `is_paid=true` สงวนไว้หมายถึง "จ่ายครบเต็มจำนวนแล้ว" เท่านั้น (booking เต็มจำนวนคง verify → is_paid=true ตามเดิม — regression 0%)
3. **เก็บส่วนที่เหลือ:** จุดตั้งใจ = **ตอน check-in หน้าเคาน์เตอร์** ผ่าน `recordPayment` เดิม (admin/system) — จ่ายครบ → `is_paid=true`, สถานะ booking **คง `confirmed`** ไม่มี transition ใหม่หลัง confirmed
4. **Check-in guard: ไม่บังคับจ่ายครบ** — check-in และ check-out ได้**แม้ยอดยังไม่ครบ** (owner: มีเคสองค์กรเบิกเงินหลังเข้าพักได้ถึง 1 เดือน) — front desk ไม่มี guard บล็อกเรื่องเงิน, ยอดค้างติดพวง booking จนกว่าจะเก็บครบ (is_paid ยัง false หลัง checked_out ได้) — การตามเก็บเป็นเรื่องกระบวนการ ไม่ใช่ระบบบล็อก
5. **payment_deadline:** ใช้ **เดียวกัน 15 นาทีทุก payment_type** (config `booking.payment_deadline_minutes`) — ไม่มี branch พิเศษ, design slot-holding ของ REQ-008 ยังสมบูรณ์

หมายเหตุ: ข้อความ "payment_deadline 24 ชม." ในตัว ticket เดิมเป็น fact ล้าสมัย (แก้ใน map Notes แล้ว — ปัจจุบัน 15 นาที ตาม REQ-008 2026-09-24)
