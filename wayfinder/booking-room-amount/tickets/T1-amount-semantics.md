---
label: wayfinder:grilling
type: grilling
status: closed
assignee: kevii
blocked-by: []
---

# T1 — `amount` บน booking_room หมายถึงอะไร (net / gross / ไม่เพิ่มคอลัมน์)?

## Question

ความหมายของ `amount` ต่อ booking_room คืออะไร — เป็น decision แม่ของทั้ง map (กำหนด schema, invariant และทุก ticket ถัดไป):

- **(A) Net total ต่อห้อง (Recommendation):** คอลัมน์ใหม่ `booking_rooms.amount` = `room_amount − discount_amount + extra_bed_price + breakfast_price + early_checkIn_price + late_checkOut_price` → **Σ(amount ทุกห้อง) == bookings.total_amount พอดี** — query/รายงานต่อห้องได้ทันที ไม่ต้อง join `addons`
- **(B) Gross total ต่อห้อง:** `amount` = `room_amount + addons` (ไม่หักส่วนลด) — ส่วนลดดูแยกผ่าน `discount_amount` เดิม; ข้อเสีย: Σ ไม่เท่า `total_amount` (ซึ่งเป็น net) ต้องจำ formula ต่างหูต่างทาง
- **(C) ไม่เพิ่มคอลัมน์:** การันตี `room_amount`/`discount_amount` ถูกกรอกทุก path + เพิ่ม computed accessor `amount` ตอน serialize — ไม่ต้อง backfill แต่ SQL-side report ยังต้อง join addons

## Evidence / Context

- `booking_rooms` มี `room_amount`, `discount_amount` แล้ว (migration `2026_08_27_110000_create_discount_system_tables.php:55-56`) — written เฉพาะโดย `DiscountService::reprice()` (`app/Services/Discount/DiscountService.php:173-176`)
- `bookings.total_amount` เป็น **net หลังหักส่วนลด** (`DiscountService.php:178-185`)
- addon prices อยู่ตาราง `addons` แยก (4 price columns) — per-room total ตอนนี้มีอยู่แค่ implicit
- ผู้ใช้ตั้งโจทย์มาว่า "booking total amount → add amount to each booking room" ซึ่งอ่านสอดคล้อง (A) มากที่สุด (denormalize เงินส่วนของแต่ละห้องออกจากยอดรวม)

## Recommendation

**(A)** — invariant ตรงไปตรงมา (`Σ amount == total_amount`) ทดสอบง่าย และเข้ากับ chokepoint `reprice()` เดิมเป๊ะ (เขียนต่อจาก `room_amount`/`discount_amount` ใน loop เดียวกัน)

## Resolution

**เลือก (A) Net ต่อห้อง** — ผู้ใช้ยืนยันผ่าน grilling session (2026-09-03)

- คอลัมน์ใหม่ `booking_rooms.amount` (integer satang, default 0) = **ยอดสุทธิของห้องนั้น**:
  `amount = room_amount − discount_amount + extra_bed_price + breakfast_price + early_checkIn_price + late_checkOut_price`
- Invariant ของ map คือ **Σ booking_rooms.amount == bookings.total_amount** (ทั้งสองฝั่ง net)
- จุดเขียน: chokepoint เดียว `DiscountService::reprice()` (loop เดียวกับ `room_amount`/`discount_amount`) — ทุก write path ต้องไหลผ่าน (walk-in คือ T2, รวม spec เป็น T5)
- ผลต่อ map: T2/T3/T4 ปลด block · fog "กลไกบังคับ invariant" graduate เป็น [T6 — บังคับ invariant Σ(amount) == total_amount ด้วยกลไกใด?](T6-invariant-enforcement.md) · T5 เพิ่ม blocked-by T6
