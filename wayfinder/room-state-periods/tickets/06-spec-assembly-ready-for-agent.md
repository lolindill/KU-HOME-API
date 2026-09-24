---
label: wayfinder:task
type: AFK
status: open
assignee:
blocked-by: ["02-grill-overlap-expiry-availability-rules", "04-grill-admin-period-crud-contract", "05-grill-migrate-legacy-pool-maintenance"]
---

# 06: รวม spec ready-for-agent (spec.md) จาก decision ครบทุก ticket

## Question / What to write

เมื่อ ticket 02 + 04 + 05 ปิดครบ — รวม decision ทั้งหมดเป็น **`spec.md` ready-for-agent** (pattern เดียวกับ `wayfinder/reserved-room-pool/spec.md`):

- โมเดล `room_state_periods` (schema เต็ม: columns, indexes, PgBoolean ไม่เกี่ยวแต่ enum ผูกตามธรรมเนียมโปรเจกต์)
- ถอด `is_reserved` + สถานะ `maintenance` — รายการจุดที่ต้องแตะทั้งหมด (state machine ใน `Room::transitionStatusTo()`, 3 จุด `whereNotIn('status', ['maintenance'])`: RoomController availability / BookingPriority / RoomAllocator, `IncludeReservedGate` ปรับความหมาย, seeders, tests)
- Shared scope ประเภท `holdingSlot()` สำหรับ period-check + กฎ overlap/availability จาก ticket 02
- Contract endpoint CRUD จาก ticket 04
- Migration ข้อมูลเดิมจาก ticket 05
- Display ที่อ่านสถานะ `maintenance` ตรง ๆ (dashboard/room board) — ดึงจาก fog ของ map ตอนเขียน
- จุดที่กระทบ map `reserved-room-pool` (frozen) — สิ่งที่ต้องรื้อตอน unfreeze รวมไว้ใน spec เลย

**Blocked by:** ticket 02 (✅ ปิดแล้ว 2026-09-24), 04, 05 (ต้องปิดครบ — spec ห้ามมีช่องว่างตัดสินใจค้าง)

> 📌 **Touchpoints เพิ่มจาก ticket 02 (ผ่านการ grill แล้ว — ใส่ตามนี้ใน spec ได้เลย):**
> - overlap query ของ period = half-open เดียวกับ booking_rooms (`start_date < BR.check_out AND end_date > BR.check_in`) บน `holdingSlot()`
> - POST period: ลบ draft booking ที่ overlap ทันที (audit `draft → deleted`) + response 201 มี `affected_bookings` (confirmed/checked_in ที่โดนทับ — ไม่แตะ แค่รายงาน)
> - per-day calendar: period เข้า occupied matrix เดียวกับ BR · `GET /availability` (range): ตัดรายห้องที่ period ของมัน overlap ช่วงที่ขอ
> - `FrontDeskController::checkIn`: reject เมื่อห้องมี maintenance period overlap `[check_in, check_out)` ของ BR (reserved ผ่าน) — และปิดช่องเช็คอินห้องสถานะ maintenance ที่หลุดอยู่ (จะหายไปเองเมื่อสถานะถูกถอด + gate ใหม่ครอบแทน)
> - walk-in gate (`FrontDeskController`): เพิ่ม period-check แทนเงื่อนไข `is_reserved`/สถานะเดิม (reserved period + flag → ผ่าน, maintenance → reject)
> - HousekeepingTask: **ไม่แตะอะไรเลย** — ไม่มี FK อ้าง period, done → available คงเดิม (จดไว้กัน agent ไปเพิ่มเอง)
> - `DailyRoomMaintenance` command: ตรวจ logic ที่อ้างสถานะ maintenance/ห้องว่างว่าต้องสลับเป็น period-check จุดไหนบ้าง
> - Display ที่อ่านสถานะ `maintenance` ตรง ๆ (dashboard/room board) — ดึงจาก fog เดิมของ map มาไว้ที่หัวข้อนี้แล้ว

- [ ] เขียน `spec.md` + ผูก Decisions so far ของ map ให้ครบ
- [ ] ถ้าเขียนแล้วเจอคำถามค้าง — เปิด ticket grill ใหม่ อย่าตอบแทน owner
- [ ] ปิด ticket + อัปเดต map → map พร้อมปิด (destination บรรลุ) เมื่อ ticket 03 (งาน build REQ-039) ก็จบด้วย
