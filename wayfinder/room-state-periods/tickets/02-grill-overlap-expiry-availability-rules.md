---
label: wayfinder:grilling
type: HITL
status: closed
assignee: kevii (session 2026-09-24)
blocked-by: ["01-grill-period-model-vs-flag"]
---

# 02: Grill กฎ overlap/expiry กับ booking และการนับ availability ระหว่าง period

> 📌 **ฐานล็อกแล้วจาก ticket 01 (2026-09-24):** ตารางเดียว `room_state_periods` (kind `reserved`|`maintenance`) · derived ตอน query ไม่มี sweep · สถานะ `maintenance` ถูกถอดออกจาก machine · reserved period admin override ผ่าน `include_reserved` ได้ / maintenance ตัดเด็ดขาด — คำถามด้านล่างคือ**กฎที่เหลือ** ไม่รวมสิ่งที่ล็อกไปแล้ว

## Question

เมื่อโมเดล period ล็อกแล้ว (ticket 01) — กฎปฏิสัมพันธ์กับระบบจองคืออะไร?

1. **Overlap กับ booking จริง:** admin เปิด period ทับห้องที่มี BR ค้างคืน (draft/confirmed/checked_in) ได้ไหม — บล็อก, เตือน, หรือบังคับย้ายห้อง? ใช้ overlap query แบบเดียวกับ `booking_rooms` (`check_in < end AND check_out > start`)?
2. **Availability ระหว่าง period บางวัน:** ห้องสำรอง period กลางเดือน 3 วัน — per-day/ranges endpoints นับรายวันจริงไหม หรือ pool ถือว่าห้องนี้ "ออกจากขาย" ทั้งช่วงแบบเดิม?
3. **Check-in ทับ period:** แขกที่จองไว้ก่อน period เข้าพักวันที่ตรงกับ period — อนุญาต (period สำหรับห้องว่างเท่านั้น) หรือไม่?
4. **งาน HousekeepingTask ระหว่าง maintenance period:** สร้าง/รับงานเก็บ-ซ่อมบนห้องที่ติด period ได้ไหม (เดิมงานซ่อมผูกกับสถานะ `maintenance` ซึ่งกำลังจะถูกถอด — flow ต้องอ้าง period แทน)? *(ส่วน walk-in/include_reserved ตอบแล้วใน ticket 01 ข้อ 4 — reserved override ได้, maintenance เด็ดขาด)*

**Blocked by:** ~~ticket 01~~ ✅ ปิดแล้ว (2026-09-24) — **frontier ตัวจริงตอนนี้**

- [x] grill 4 คำถามกับ owner
- [x] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far

## Resolution

**CLOSED 2026-09-24 — owner grill HITL ครบ 4 คำถาม (ฐาน: โมเดล period จาก ticket 01):**

1. **สร้าง period ทับ booking ค้างได้เสมอ + รายงานผล** — ทุก kind สร้างทับ BR ที่ overlap ได้ (ห้องพัง/โดนอีเวนต์จริง ต้องบันทึกได้แม้มีแขกอยู่)
   - **draft** ที่ overlap → **ลบทันทีตอนสร้าง period** พร้อม audit log `draft → deleted` (กลไกเดียวกับ `DELETE /bookings/{id}` ที่มีอยู่)
   - **confirmed/checked_in** → ไม่แตะ แต่ response 201 ใส่ `affected_bookings` ให้ admin เก็บงานเอง (ระบบไม่มี re-assign ของ non-draft อยู่แล้ว — บังคับย้ายไม่ได้)
   - overlap query = half-open เดียวกับ `booking_rooms`: `start_date < BR.check_out AND end_date > BR.check_in` บน `holdingSlot()`
2. **Availability นับรายวันจริง + ตามช่วง** — per-day calendar ยัด period เข้า occupied matrix เดียวกับ BR (วันไหน period ครอบ วันนั้นห้องหาย วันอื่นขายปกติ) · range endpoint `GET /availability` ตัด**รายห้อง**ที่ period ของมัน overlap ช่วงที่ขอ (semantics "ว่างครบทุกคืนของช่วง" เดียวกับ createBooking) — สอง endpoint คือความจริงเดียวกันมองคนละมุม
3. **Check-in gate (FrontDeskController::checkIn):** reject เมื่อห้องปลายทางมี **maintenance period** overlap ช่วงพักของ BR (`[check_in, check_out)`) — ฟรอนต์ย้ายแขกด้วย `assigned_rooms` ที่มีอยู่แล้ว · **reserved period ไม่บล็อก** check-in (booking แบบ include_reserved ของ admin ถูกต้องแล้ว) · แถมปิดช่องเก่าที่พบระหว่าง grill: เช็คอินเข้าห้องสถานะ `maintenance` วันนี้หลุดผ่านได้ (`transitionStatusTo('occupied')` จาก maintenance = `*`)
4. **HousekeepingTask ไม่ผูกกับ period เลย** — สร้าง/รับ/ทำ task บนห้องติด period ได้ทุก kind · ไม่มี FK/field อ้าง period · task done → room `available` คงเดิม (period เป็นตัวกันขาย ไม่ใช่สถานะ — ตาม ticket 01) · ตรงกับโค้ดจริงที่ไม่มี repair `task_type` ให้ย้าย (มีแค่ `pre_checkin/checkout/checkout_then_in/daily/monthly/group`)

**Graduate จาก fog (ตามไปตั้งคำถามที่อื่น):** กฎ overlap period↔period → ย้ายเข้าคำถามของ ticket 04 · display/dashboard ที่อ่านสถานะ `maintenance` ตรง ๆ → checklist touchpoints ของ ticket 06
