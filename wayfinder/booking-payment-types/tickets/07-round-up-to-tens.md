# 07: ปัดเศษขึ้นหลักสิบ (round up to tens) — ใช้กับยอดไหน ตรงไหน (REQ-015/016 — SRS v2)

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **closed:** 2026-09-25
- **blocked-by:** ["04-amounts-layer-a-and-b"]
- **assignee:** ZCode (session 2026-09-25 — grilling)
- **born:** 2026-09-24 — graduate จาก gap ตรวจ SRS v2 (srs_room_booking_v2.pdf) ตามคำสั่ง owner "9 add in still going map"

## Question

REQ-015/016: "ระบบคำนวณราคารวมอัตโนมัติ **หากมีเศษให้ปัดเศษขึ้นหลักสิบ**" (จองรายเดือน/แบบกลุ่ม)

ปัจจุบันทุกยอดเป็น integer บาทตรง ๆ (rate × nights + addon) — **ไม่มี rounding ที่ไหนเลย** และเงินของ SRS เป็นราคาหลักร้อย ก็ไม่ชัดว่าเศษเกิดตรงไหน (เศษจากอะไร: ส่วนลด percent? เรทรายชั่วโมง? หาร per-person ในอนาคต?)

- ปัด "ยอดไหน": ต่อ booking_room (`amount`)? ยอดรวม booking (`total_amount`)? ชั้น A (`confirmations.amount` ต่อครั้งส่งสลิป)?
- ปัดเฉพาะ long stay/กลุ่ม (stay_type monthly/block) หรือทุก booking?
- invariant Σ booking_rooms.amount == bookings.total_amount ต้องคงอยู่ — ปัดที่ห้องแล้วให้ยอดรวมไหลตาม หรือปัดที่ยอดรวม (ห้องเป็นเศษได้)?
- ถ้าโค้ดส่วนลด percent ทำให้มีเศษ ลำดับ (ลดก่อนแล้วปัด / ปัดก่อนแล้วลด) — grill คู่กับ ticket 04 (ยอดเงิน 2 ชั้น)

## Resolution

**(2026-09-25 — grilling กับ owner ผ่าน AskUserQuestion, 4 คำถาม)**

**Decision (owner ตัดสิน):**

1. **ขอบเขต = ทุก booking ไม่แยก stay_type** — owner ตอบ "no decimal ปัดเศษขึ้น": กติกาเดียวทั้งระบบว่า **ยอดเงินลูกค้าไม่มีทศนิยม มีเศษให้ปัดขึ้นหลักสิบ** (`(int) ceil($amount / 10) * 10`) — เรทปัจจุบันเป็นร้อยตัวงั้น regression 0% อยู่แล้ว แต่กันเศษในอนาคตทุกทาง (REQ-015/016 ระบุแค่ monthly/group แต่ owner เลือกกติกาเดียว)
2. **ปัดที่ห้อง ยอดรวมไหลตาม** — ปัดที่ `booking_rooms.amount` ใน `DiscountService::reprice()` (chokepoint เดียวที่เขียนเงิน) แล้ว `total_amount = Σ ยอดห้องที่ปัดแล้ว` → invariant `Σ booking_rooms.amount == bookings.total_amount` คงอยู่เชิงโครงสร้าง
3. **ลำดับ = ลดก่อน ปัดท้ายครั้งเดียว** — `(เรท×คืน) − ส่วนลด + addon → ปัดขึ้นสิบท้ายสุด` · ส่วนลด `percent` ที่ใช้ `intdiv` ตัดทิ้ง (`DiscountService.php:135`) คงเดิม — การปัดท้ายครอบเศษที่เหลือทั้งหมด ไม่ต้องแตะ
4. **เศษจากเวลา = normalize เป็นคืนเต็มเสมอ** — จุดคำนวณทุกจุดต้อง `startOfDay()` ทั้ง check_in/check_out ก่อน `diffInDays()` (Carbon 3 คืน float เมื่อ input เป็น datetime เช่น 1.5 คืน — จุดเดียวที่เศษหลุดจริง): `DiscountService::reprice()` (:166) + `BookingController` 4 จุด (:341, :676, :938, :1248) — จองครึ่งคืนไม่มีในโดเมนนี้อยู่แล้ว

**Sub-decisions ที่ไหลตามกติกา no-decimal (agent สรุปตาม spirit ของ owner — flip ได้ก่อน implement):**

- **มัดจำ default 50%** (`Booking::expected_amount` — `ceil(total×50/100)`): ปัดขึ้นหลักสิบต่อ เพราะเป็นยอดที่ลูกต้องจ่าย — ส่วน `deposit_amount` ที่ admin ตั้งเองเป็น integer อยู่แล้ว admin รับผิดชอบตัวเลขเอง (ไม่ force ปัดทับ)
- **ชั้น A `booking_confirmations.amount`** (ยอดที่ user แจ้งต่อครั้งส่งสลิป): **ไม่บังคับปัด** — ยังเป็น soft check ให้ admin ตัดสินตาม ticket 04 (คนอาจพิมพ์ยอดไม่ตรงเป๊ะ — นั่นแหละที่ admin verify)

**Fact จากการสำรวจโค้ด (Explore agent 2026-09-25):** เศษเงินเกิดได้จริงแค่ 2 ที่ — (1) Carbon 3 `diffInDays()` float จาก datetime input, (2) ส่วนลด percent `intdiv` ตัดทิ้ง — ที่เหลือ int×int หมด · ไม่มี per-person/per-hour · `month`/`group` global rates เป็น dead code ไม่เคยเข้าการคิดเงิน · `stay_type` เป็น derived attribute (`monthly` ≥30 คืน / `block` ≥21 คืน) ไม่ได้ใช้ใน pricing

**ส่งมอบ:** งาน implement ปัดเศษ = [ticket 09](./09-implement-round-up-to-tens.md) (graduate ใหม่, blocked-by ticket 06 เพราะแตะ `reprice()` จุดเดียวกัน — ticket 06 ถูก claim ทำอยู่)
