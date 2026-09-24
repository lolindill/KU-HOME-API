# 01: payment_type enum + schema บน bookings — ชื่อค่า, default, ใครตั้งได้

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** —
- **assignee:** (ว่าง)

## Question

`payment_type` บน `bookings` (owner ยืนยันระดับ booking แล้ว — confirmation เป็นแค่ log) — ตัดสินก่อนเขียน migration:

- **ชื่อค่า enum:** `full | deposit | deferred` (เต็มจำนวน/มัดจำ/ค้างชำระ)? — string ธรรมดาแบบ status อื่นของระบบ หรือรูปแบบอื่น
- **Default + backfill:** booking เดิมทุก row และ booking ใหม่ที่ไม่ระบุ = `full` ใช่ไหม — nullable หรือ not-null + default
- **ใครตั้งค่าได้:** user ส่ง `payment_type` มาเองบน `POST /bookings` ได้ไหม หรือ default `full` เสมอแล้วให้ admin เปลี่ยนเท่านั้น — ถ้า user เลือกมัดจำเองได้ ต้องมีกติกากันยังไง (จ่ายน้อยกว่าเต็มโดยไม่มีใครตรวจ)
- **เปลี่ยนกลางทาง:** เปลี่ยน `payment_type` หลังจองได้ไหม (ตอน `draft`? หลังส่งสลิป? หลัง `paid`?) — endpoint เปลี่ยนหรือห้ามตลอดชีวิต booking
- **ผลต่อ response shape:** `GET /bookings` (show/index) ต้องส่ง `payment_type` กลับไปแค่ไหน — ยอดที่ต้องชำระตอนนี้รอ [ticket 04](./04-amounts-layer-a-and-b.md)

**Precondition:** อ่าน facts ใน [`../map.md`](../map.md) หัว Notes ก่อน (โครง confirmations log, state machine, สิทธิ์ flow เดิม)
