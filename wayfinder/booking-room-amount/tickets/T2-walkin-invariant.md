---
label: wayfinder:grilling
type: grilling
status: closed
assignee: kevii
blocked-by: ["T1-amount-semantics"]
---

# T2 — walkIn path เข้า invariant ไหม และด้วยกลไกใด?

## Question

`FrontDeskController::walkIn` (`app/Http/Controllers/Api/V1/FrontDeskController.php:61-73`) เป็น write path เดียวที่ **bypass `DiscountService::reprice()` ทั้งก้อน** — เขียน `bookings.total_amount` เอง (`rate × nights`) และสร้าง BookingRoom โดยไม่มี `room_amount`/amounts (คง default 0) และไม่สร้าง addon row → ถ้าเพิ่ม `amount` โดยไม่แตะ walk-in invariant จะพังทันทีสำหรับทุก walk-in booking

ตัดสินใจ:
1. walk-in ต้อง conform invariant หรือไม่ (Recommendation: **ต้อง**)
2. กลไก: เรียก `DiscountService::reprice()` หลังสร้าง booking+room / เขียน amounts เองใน controller / document ว่ายอมรับไม่สอดคล้อง
3. ถ้าเรียก `reprice()` — เช็คว่า `reprice()`/`applyToDraft()` มี guard สถานะ (draft-only?) ที่จะโดน walk-in booking (สถานะไม่ใช่ draft) หรือไม่ — ถ้ามี ต้องปรับอย่างไรให้ไม่แตะ discount math ของ draft (Out of scope ตาม map)

## Evidence / Context

- walk-in ไม่มีโค้ดส่วนลด → `reprice()` ธรรมดาจะคิด gross ให้เอง: `amount = room_amount` (ไม่มี addon row ทุก price = 0)
- walk-in booking เกิดที่สถานะ non-draft — ต้องยืนยันว่าการเรียก reprice ไม่กระทบ state machine และไม่เขียน audit log แปลกๆ
- โจทย์ map: "แก้เฉพาะให้ amounts สอดคล้อง invariant" — ห้ามเปลี่ยนนโยบายราคา walk-in

## Recommendation

ให้ walk-in conform โดยเรียก `reprice()` หลังสร้าง (หรือ refactor จุด recompute ให้เป็น helper ที่เรียกได้ทั้งสอง flow) — และเพิ่ม test ใน `FrontDeskTest` ว่า walk-in booking มี `room_amount`/`amount` ครบ

## Resolution

**walk-in ต้อง conform invariant — กลไกคือสั่ง `DiscountService::reprice($booking)` ตอน booking ยัง `draft`** (ผู้ใช้ยืนยัน 2026-09-03)

- จุดสอด: ใน `walkIn()` หลัง `BookingRoom::create()` และ**ก่อน** transition ทั้งหมด — ตอนนั้น booking ยังเป็น `draft` ตามโค้ดจริง (`status => 'draft'` แล้วค่อย transition เป็น `confirmed`)
- พิสูจน์แล้วว่าเรียกได้: `reprice()` ไม่มี guard สถานะ (guard draft-only มีแค่ใน `applyToDraft`/`removeFromDraft`) — walk-in ไม่มีโค้ดส่วนลด จึงได้ `amount = room_amount = GlobalRate × nights`, `discount_amount = 0`, ไม่มี addon row → Σ == total_amount ที่เดิมเขียนมือ ทุกอย่างอยู่ใน transaction เดิม
- ไม่เปลี่ยนนโยบายราคา walk-in (ยังไม่มี addon ให้ walk-in — out of scope ตาม map)
- **ตามไปที่ spec (T5):** เพิ่ม test ใน `FrontDeskTest` ว่า walk-in booking มี `room_amount`/`amount` ครบและ Σ(amount) == total_amount; คำนวณ `total_amount` เบื้องต้นใน walkIn จะถูก `reprice()` overwrite ด้วยค่าเดียวกัน (พิจารณาตอน implement ว่าจะตัดการเขียนมือทิ้งได้ไหม)
