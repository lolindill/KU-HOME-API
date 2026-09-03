---
label: wayfinder:grilling
type: grilling
status: closed
assignee: kevii
blocked-by: ["T1-amount-semantics"]
---

# T4 — API/docs contract: field ชื่ออะไร โผล่ตรงไหน อัปเดตเอกสารใดบ้าง?

## Question

1. **ชื่อ field:** `amount` ดีสุดไหม (ชนกับอะไรไหมใน response ของ BookingRoom — ปัจจุบันมี `room_amount`, `discount_amount`; `$hidden = ['roomType','room']`)
2. **การ expose:** ทุก endpoint ที่ serialize BookingRoom ตรงๆ (`GET /bookings/{id}`, list, addRooms/updateRoom/batch/destroy responses) จะได้ field ใหม่ ride along **อัตโนมัติ** เพราะ repo ไม่ใช้ API Resources — ยืนยันว่าพอ หรือต้องทำ `$appends`/accessor เพิ่ม (เช่นกรณี T1 เลือก (C))
3. **เอกสารที่ต้องอัปเดต:** `docs/api_guide.md` (booking schema + response examples + สูตร amount), `docs/database-er.md` (คอลัมน์ใหม่), `cline.md` (design decision ก่อนเขียนโค้ด ตามธรรมเนียม repo) — ระบุ section ให้ชัด
4. **Frontend `ku-home`:** additive field ประกาศผ่านช่องทางไหน (release note/changelog) — repo frontend ไม่อยู่บนเครื่องนี้ ทำได้แค่ document

## Evidence / Context

- Response contract เดิม: `addRooms` คืน `{"added_amount", "total_amount", ...}` (`BookingController.php:388-389`) — ควรเทียบเคียง naming (`added_amount` ปัจจุบันคือ gross ของห้องที่เพิ่ม)
- `DiscountTest.php:824-828` assert per-room `room_amount`/`discount_amount` ใน JSON — test ใหม่จะอ้าง pattern เดียวกัน
- ค่าเงินทุก field เป็น integer satang — `amount` ต้อง integer เหมือนกัน

## Recommendation

ชื่อ `amount` (integer, default 0, ride along อัตโนมัติทุก response ที่มี booking_room) + อัปเดตสามเอกสารตามข้อ 3 + เขียนเตือนใน `api_guide.md` ว่า `Σ(amount) == total_amount` คือ invariant ที่ frontend อ้างอิงได้

## Resolution

**ชื่อ field = `amount`** (integer satang, default 0) — ผู้ใช้ยืนยัน 2026-09-03

1. **ชื่อ:** `amount` — ตรวจแล้วไม่ชนอะไรใน BookingRoom (column มีแค่ `room_amount`/`discount_amount`, accessor มีแค่ `primary_guest*`/`total_guests`, `$hidden = ['roomType','room']` ไม่เกี่ยว) · ไม่เลือก `total_amount` เพราะสับสนกับ `bookings.total_amount` คนละระดับ
2. **การ expose:** ride along **อัตโนมัติ** ทุก response ที่ serialize BookingRoom — repo ไม่มี API Resources จึงเพิ่มแค่ `fillable` + integer cast ใน `BookingRoom` ก็พอ (ไม่ต้อง `$appends`)
3. **เอกสารที่อัปเดต (ตอน implement ตาม T5):** `docs/api_guide.md` (schema + สูตร amount + เขียนเตือน invariant `Σ(amount) == total_amount` ให้ frontend อ้างอิงได้) · `docs/database-er.md` (คอลัมน์ใหม่) · `cline.md` (design decision ก่อนโค้ด ตามธรรมเนียม repo)
4. **Frontend `ku-home`:** additive field — ประกาศผ่าน release note/changelog ฝั่ง backend เท่านั้น (repo frontend แยก ทำได้แค่ document)
5. **ตรวจแล้วจาก fog ของ map — `checkOut` pending-amount message ไม่ต้องแก้:** `FrontDeskController.php:274-276` อ้าง `$booking->total_amount` ระดับ booking ซึ่งถูกต้องอยู่แล้ว (ยอดค้างชำระเป็นของทั้งใบ ไม่ใช่รายห้อง) — naming ของ `added_amount` ใน addRooms ยังเป็น gross ของห้องที่เพิ่ม พิจารณาเทียบเคียงตอนเขียน api_guide (ไม่บังคับเปลี่ยน)
