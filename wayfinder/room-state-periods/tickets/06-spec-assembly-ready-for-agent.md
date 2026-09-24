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

**Blocked by:** ticket 02, 04, 05 (ต้องปิดครบ — spec ห้ามมีช่องว่างตัดสินใจค้าง)

- [ ] เขียน `spec.md` + ผูก Decisions so far ของ map ให้ครบ
- [ ] ถ้าเขียนแล้วเจอคำถามค้าง — เปิด ticket grill ใหม่ อย่าตอบแทน owner
- [ ] ปิด ticket + อัปเดต map → map พร้อมปิด (destination บรรลุ) เมื่อ ticket 03 (งาน build REQ-039) ก็จบด้วย
