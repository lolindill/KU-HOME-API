# 01: payment_type enum + schema บน bookings — ชื่อค่า, default, ใครตั้งได้

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** —
- **assignee:** kevii (claimed 2026-09-25)

## Question

`payment_type` บน `bookings` (owner ยืนยันระดับ booking แล้ว — confirmation เป็นแค่ log) — ตัดสินก่อนเขียน migration:

- **ชื่อค่า enum:** `full | deposit | deferred` (เต็มจำนวน/มัดจำ/ค้างชำระ)? — string ธรรมดาแบบ status อื่นของระบบ หรือรูปแบบอื่น
- **Default + backfill:** booking เดิมทุก row และ booking ใหม่ที่ไม่ระบุ = `full` ใช่ไหม — nullable หรือ not-null + default
- **ใครตั้งค่าได้:** user ส่ง `payment_type` มาเองบน `POST /bookings` ได้ไหม หรือ default `full` เสมอแล้วให้ admin เปลี่ยนเท่านั้น — ถ้า user เลือกมัดจำเองได้ ต้องมีกติกากันยังไง (จ่ายน้อยกว่าเต็มโดยไม่มีใครตรวจ)
- **เปลี่ยนกลางทาง:** เปลี่ยน `payment_type` หลังจองได้ไหม (ตอน `draft`? หลังส่งสลิป? หลัง `paid`?) — endpoint เปลี่ยนหรือห้ามตลอดชีวิต booking
- **ผลต่อ response shape:** `GET /bookings` (show/index) ต้องส่ง `payment_type` กลับไปแค่ไหน — ยอดที่ต้องชำระตอนนี้รอ [ticket 04](./04-amounts-layer-a-and-b.md)

**Precondition:** อ่าน facts ใน [`../map.md`](../map.md) หัว Notes ก่อน (โครง confirmations log, state machine, สิทธิ์ flow เดิม)

## ✅ Resolution (2026-09-25 — grilling กับ owner ผ่าน AskUserQuestion)

`payment_type` = **column ใหม่บนตาราง `bookings`** (ยืนยัน standing decision ข้อ 3 ของ map — owner เช็กซ้ำระหว่าง grill แล้วโอเค: confirmation เป็นแค่ log การส่งสลิป ไม่ใช่ที่เก็บ type):

1. **ชื่อค่า enum:** `full | deposit | deferred` — string ธรรมดาแบบ status อื่นของระบบ (ไม่มีตาราง lookup)
2. **Schema:** `NOT NULL DEFAULT 'full'` + migration backfill booking เดิมทุก row เป็น `full` — query ไม่มีทางเจอ null, flow เดิม regression 0% (standing decision ข้อ 5)
3. **ใครตั้ง:** admin/system เท่านั้น — `POST /bookings` รับ optional `payment_type` แต่ **ถ้า input มี field นี้ → ต้องเป็น admin** (sanctum role check — non-admin ส่งมา → 403); user ทั่วไปไม่ส่ง field ได้ `full` เสมอ
4. **เปลี่ยนกลางทาง:** แก้ได้เฉพาะช่วง `draft` ผ่าน **endpoint ใหม่ `PUT /bookings/{id}`** (admin-only, draft-only, รับ `payment_type`) — หลังส่งสลิป/verify แล้ว frozen · ไม่มีช่องทางแก้อื่น (POST ตั้งได้ครั้งเดียวตอนสร้าง)
5. **Response:** `payment_type` ใส่ทุก response ของ booking (index/show/admin) — scalar เบา, frontend โชว์ badge ได้ทันที · ยอดที่ต้องชำระ (due/deposit) รอ [ticket 04](./04-amounts-layer-a-and-b.md)

หมายเหตุ: `PUT /bookings/{id}` เป็น **booking container endpoint ตัวแรกของระบบ** (ปัจจุบันมีแต่ PUT ระดับ booking_rooms) — ticket 05 ต้องรวม contract ของมันด้วย
