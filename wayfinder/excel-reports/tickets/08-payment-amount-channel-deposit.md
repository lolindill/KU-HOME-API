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

## ⏸️ Pause note (2026-09-22 — owner ขอพัก grilling ใบนี้)

Grilling เริ่มไปครึ่งทางแล้ว owner สั่งหยุด — เพราะ **เจอว่าใบนี้ผูกกับ design feature ฝั่ง payment type ที่ยังไม่ได้สร้างจริง** จะตัดสิน column รายงานก่อนไม่ได้:

- ตารางที่ admin ใช้ verify = **`booking_confirmations`** (slip flow; ไม่มี column `amount` · เดิมมี `payment_method` โดน drop `2026_08_19_100000` พร้อมกับของ `payments`) · owner เอียงจะ **เพิ่ม field ยอดเงินบนตารางนี้** แต่ยังไม่ปิดคำตอบ — ต้องกลับมาคุยร่วมกับ design ของ payment type ก่อน
- `payments` เขียนจริงแค่ 2 จุด (mock QR `PaymentController::requestPayment` + เงินสด `FrontDeskController::recordPayment`)
- ธงที่ยังค้าง: ยอดสลิป · payment_channel · มัดจำ 50% · receipt_ref (= เลขที่ใบเสร็จ — receipts FROZEN ไม่มีข้อมูลให้เติมแล้ว)

→ ถอน assignee ให้กลับไปเป็น frontier (can pick) — ใคร pick ใบนี้ครั้งหน้า ให้คุย **design payment type ให้จบก่อน** แล้วค่อยกลับมาตัดสิน column รายงาน (อาจแตก ticket design ใหม่ก็ได้)

## 🔓 Unblock note (2026-09-25 — เงื่อนไขใน pause note ครบแล้ว)

แมป [`booking-payment-types`](../../booking-payment-types/map.md) **ปิดสมบูรณ์แล้ว** (design + implement, suite 554 เขียว) — design payment ที่ใบนี้รอมีคำตอบใช้ได้แล้ว:

- **ยอดสลิป:** ชั้น A = `booking_confirmations.amount` (integer baht, required สลิปใหม่ / สลิปเก่า null) · ชั้น B derive ล้วนจาก `payments` ledger เดียว — `paid_amount` = SUM(payments), `outstanding` = total − paid
- **มัดจำ 50%:** `bookings.payment_type` = `full|deposit|deferred` + `deposit_amount` nullable (null = 50% จาก config)
- **ธงที่ยังเปิดให้ใบนี้ตัดสินเอง:** `payment_channel` — แมป payment-types ตัดสินว่า ledger แยกที่มาเงินได้จากจุดเขียน (verify=สลิป / recordPayment=เงินสด / QR) โดยไม่ต้องกลับ column แต่ "จะมี column channel ชัด ๆ ไหมในรายงาน" ยังเป็นคำถามของใบนี้ · `receipt_ref` ยังติด `receipts` FROZEN เหมือนเดิม

pick ใบนี้ได้ตามปกติ — grilling ต่อจากจุด pause โดยเทียบ design ข้างบนเป็น base ค่ะนะ ✨
