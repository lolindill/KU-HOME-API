---
label: wayfinder:grilling
type: HITL
title: Payment/deposit semantics — ยอดเงินของสลิป, channel, มัดจำ 50%, receipt_ref
status: open
assignee:
blocked-by: ["02-data-coverage-audit"]
---

# 08: Payment/deposit semantics สำหรับรายงานการเงิน

## Question

Audit พบว่ารายงานฝั่งการเงินคำนวณจริงไม่ได้ (gap กลุ่ม 1 ใน [`../research/data-coverage-audit.md`](../research/data-coverage-audit.md)) — ระบบจะเก็บข้อมูลการชำระเงินยังไงต่อ?

- **สลิปไม่มียอด:** `booking_confirmations` ไม่มี column amount (slip flow เขียน amount ที่ไหนเลย) — `received_amount`/`paid_amount`/`outstanding_amount` ของ daily-financial / check-in / check-out / deposit / erp จะมาจากไหน (เพิ่ม column บน confirmations? ใช้ `bookings.total_amount` + `is_paid` แบบยากต่อเมื่อจ่ายไม่เต็ม?)
- **`payment_channel` (QR/เงินสด/บัตร):** ถูก drop ไปแล้ว (`2026_08_19_100000`) — รายงานการเงินต้องการกลับมาไหม หรือตัด column นี้ออกจาก template?
- **มัดจำ 50% (`payment_status` = เต็มจำนวน/มัดจำ):** ไม่มี concept มัดจำในระบบ — จะออกแบบอย่างไร (section "deposit/ยังไม่ได้ชำระ" ของ check-in-report ผูกกับเรื่องนี้ด้วย)
- **`receipt_ref`:** `receipts` FROZEN — ตัด column ออกจาก template หรือรอระบบใบเสร็จใหม่?
- ข้อเสนอต้องไม่ผิด contract เดิม: slip-image-only flow, `PgBoolean`, money = integer บาท, state machine `pending → paid`

**Precondition:** อ่าน audit §3.1–3.4, §3.7 + audit §4 gap 1 ก่อน
