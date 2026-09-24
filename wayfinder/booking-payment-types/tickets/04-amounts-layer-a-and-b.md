# 04: ยอดเงิน 2 ชั้น — ชั้น A `booking_confirmations.amount` + ชั้น B ภาพรวมจ่ายแล้ว/ค้างบน bookings

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** [02-deposit-semantics](./02-deposit-semantics.md), [03-deferred-payment-and-permissions](./03-deferred-payment-and-permissions.md)
- **assignee:** (ว่าง)

## Question

Owner ล็อก scope ไว้แล้ว: **รวมทั้ง 2 ชั้น** — ตัดสินรายละเอียด (money = integer baht ทุก field):

- **ชั้น A — column `amount` บน `booking_confirmations`:** required ตอน `POST /bookings/{id}/confirm` หรือ nullable? — สลิปเก่าที่ส่งไปแล้ว (nullable ได้แค่ของเก่า?) · การเทียบยอดตอน verify: admin เห็น expected vs claimed แล้วตัดสินเอง หรือระบบ hard-reject ถ้าไม่ตรง (เผื่อสลิปมีค่าธรรมเนียม/ตัดเศส)
- **ชั้น B — ภาพรวมบน bookings:** เก็บ column (`paid_amount` / ยอดค้าง) หรือ derive จาก rows ลูก — ใคร update (hook ใน `transitionStatus()`? controller?) — ค่านี้ report การเงินจะหยิบไปใช้ตรง ๆ
- **`payments` table เดิม:** มี `amount` อยู่แล้ว แต่เขียนจริงแค่ 2 จุด (mock QR + เงินสด) — ใช้เป็น ledger ของ "การเงินจริง" ต่อ หรือยุบให้ confirmations เป็นแหล่งเดียว (อย่าลืม: `receipts` FROZEN, webhook `410 GONE`)
- **Backfill:** booking เดิมที่ `paid|confirmed` แล้ว = จ่ายเต็ม `total_amount`? (สมมติฐานนี้ถูกต้องหรือไม่ — ระบบเดิมไม่มีชำระบางส่วนอยู่แล้ว)
- **ส่งมอบให้ excel-reports ticket 08:** สรุป field ที่รายงานจะอ่านได้ (`received_amount` / `outstanding_amount` / ยอดต่อสลิป) — ปิด ticket นี้ = ticket 08 กลับมาทำงานได้
