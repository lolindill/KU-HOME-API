# 04: ยอดเงิน 2 ชั้น — ชั้น A `booking_confirmations.amount` + ชั้น B ภาพรวมจ่ายแล้ว/ค้างบน bookings

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** [02-deposit-semantics](./02-deposit-semantics.md), [03-deferred-payment-and-permissions](./03-deferred-payment-and-permissions.md)
- **assignee:** kevii (claimed 2026-09-25)

## Question

Owner ล็อก scope ไว้แล้ว: **รวมทั้ง 2 ชั้น** — ตัดสินรายละเอียด (money = integer baht ทุก field):

- **ชั้น A — column `amount` บน `booking_confirmations`:** required ตอน `POST /bookings/{id}/confirm` หรือ nullable? — สลิปเก่าที่ส่งไปแล้ว (nullable ได้แค่ของเก่า?) · การเทียบยอดตอน verify: admin เห็น expected vs claimed แล้วตัดสินเอง หรือระบบ hard-reject ถ้าไม่ตรง (เผื่อสลิปมีค่าธรรมเนียม/ตัดเศส)
- **ชั้น B — ภาพรวมบน bookings:** เก็บ column (`paid_amount` / ยอดค้าง) หรือ derive จาก rows ลูก — ใคร update (hook ใน `transitionStatus()`? controller?) — ค่านี้ report การเงินจะหยิบไปใช้ตรง ๆ
- **`payments` table เดิม:** มี `amount` อยู่แล้ว แต่เขียนจริงแค่ 2 จุด (mock QR + เงินสด) — ใช้เป็น ledger ของ "การเงินจริง" ต่อ หรือยุบให้ confirmations เป็นแหล่งเดียว (อย่าลืม: `receipts` FROZEN, webhook `410 GONE`)
- **Backfill:** booking เดิมที่ `paid|confirmed` แล้ว = จ่ายเต็ม `total_amount`? (สมมติฐานนี้ถูกต้องหรือไม่ — ระบบเดิมไม่มีชำระบางส่วนอยู่แล้ว)
- **ส่งมอบให้ excel-reports ticket 08:** สรุป field ที่รายงานจะอ่านได้ (`received_amount` / `outstanding_amount` / ยอดต่อสลิป) — ปิด ticket นี้ = ticket 08 กลับมาทำงานได้

## ✅ Resolution (2026-09-25 — grilling กับ owner ผ่าน AskUserQuestion · money = integer baht ทุก field)

1. **ชั้น A — `booking_confirmations.amount`:** integer baht **nullable** (สลิปเดิมก่อน deploy คง `null` ตามจริง) · ตั้งแต่ implement `POST /bookings/{id}/confirm` **required** (integer ≥ 1) — สลิปใหม่มีการแจ้งยอดเสมอ
2. **เทียบยอดตอน verify = soft:** ระบบแสดง **expected** (ยอดต้องชำระ ณ ปัจจุบัน — full = `outstanding`, deposit งวดแรก = `deposit_amount`, งวดถัดไป = `outstanding` คงเหลือ) เทียบ **claimed** (`amount` ที่ user แจ้ง) แล้ว **admin ตัดสินเอง** — ระบบไม่ hard-reject (เผื่อค่าโอน/เศษจาก mobile banking)
3. **`payments` = ledger เดียวของ "เงินที่เข้าจริง" ทุกช่องทาง:** สลิป verify ผ่าน → **เขียน payments row ใหม่** ด้วย `confirmation.amount` (เพิ่มจากเดิมที่มีแค่เงินสด `recordPayment` + mock QR) · 1 row = 1 เหตุการณ์เงิน · ความสัมพันธ์กับสลิปใช้ `reference_number` = confirmation UUID (ไม่เพิ่ม column) — จัด contract สุดท้ายใน ticket 05 · **ห้ามเขียน payments นอก 3 จุดนี้** (จด cline.md ตอน implement)
4. **ชั้น B = derive จาก ledger ล้วน — ไม่มี column ยอดบน bookings:** `paid_amount` = `SUM(payments.amount)` · `outstanding_amount` = `total_amount − paid_amount` · คำนวณตอนอ่าน/serialize · ไม่มีทาง drift เพราะ ledger คือแหล่งเดียว
5. **field มัดจำ (ส่งต่อจาก ticket 02):** **`deposit_amount` integer baht nullable บน bookings** — admin ตั้งได้ (ช่องทางเดียวกับ `payment_type`: POST โหมด admin + PUT draft) · **null = ระบบคิด 50%** ของ `total_amount` (default จาก config `booking.deposit_percent` = 50) · ตัวเลขตายตัว: **reprice แล้วยอดเก่าค้าง admin ต้องแก้เอง** (owner เลือกรับ trade-off นี้เอง)
6. **`is_paid` = "จ่ายครบ" ตาม ledger:** เซ็ต true เมื่อ `SUM(payments) ≥ total_amount` — ตอน verify (จ่ายเต็ม → เหมือน flow เดิม regression 0%) และตอน recordPayment (มัดจำ/ค้างชำระจ่ายครบจุดไหนจุดนั้น)
7. **Backfill migration:** booking เดิม `paid|confirmed` → สร้าง payments row ย้อนหลัง 1 row ต่อ booking = `total_amount` (reference ระบุ legacy) — ledger สมบูรณ์ตั้งแต่วัน deploy · booking `draft|pending|verify_error` ไม่มี row (ยังไม่จ่ายจริง)
8. **Response:** ทุก booking response ทุก role ส่ง `payment_type`, `paid_amount`, `outstanding_amount`, `deposit_amount` (ยอดมัดจำที่ต้องชำระ — เฉพาะ type deposit) — derive สดจาก ledger ทั้งหมด

**🎁 ส่งมอบให้ [excel-reports ticket 08](../../excel-reports/tickets/08-payment-amount-channel-deposit.md) (ปลดล็อกแล้ว):** รายงานการเงินอ่านได้จาก (ก) `payments` ledger ต่อรายการ (amount · เวลา · received_by · reference ชี้ confirmation — ช่องทางรู้จากที่มาของ row: verify=สลิป / recordPayment=เงินสด / QR) · (ข) `bookings.total_amount` + `payment_type` + `deposit_amount` · (ค) derived `paid_amount`/`outstanding_amount` — **ไม่ต้องออกแบบ column ใหม่ในแมป excel-reports**

**หมายเหตุ (แทน fog "เก็บเงินหลายงวด" ที่ปิดไปโดย design นี้):** ledger รองรับเก็บหลายงวดตามธรรมชาติ — `recordPayment` เรียกซ้ำได้จนครบ (แต่ละครั้ง 1 row) · การส่งสลิปออนไลน์หลัง `confirmed` ไม่รองรับ (มัดจำ/ค้างชำระเก็บส่วนที่เหลือที่เคาน์เตอร์เท่านั้น — ticket 02/03)
