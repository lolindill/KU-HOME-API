---
label: wayfinder:map
title: "include_reserved — ห้องสำรอง (reserved_closed) เข้า pool ฝั่ง admin"
status: open
---

# Wayfinder Map — `include_reserved` (ห้องสำรอง reserved_closed)

> **🧊 FROZEN (2026-09-24):** หยุดงานทุก ticket ของ map นี้ — req ใหม่ "ห้องสำรอง/ซ่อมแซมกำหนดระยะเวลาแบบ booking" ถูก chart เป็น map **`wayfinder/room-state-periods/`** แล้ว และอาจเปลี่ยนโมเดล `is_reserved` flag (decision ticket 90) เป็น period records · ticket 01 code landed แล้วบน `agust-11` (suite 446 เขียว) แต่**ไม่ปิด** — รอ map ใหม่จบแล้ว unfreeze ค่อยตรวจ/ปิด

## Destination

[spec.md](spec.md) (label `ready-for-agent`) — admin-only param `include_reserved` ครบ 5 surfaces (availability ×3, booking capacity checks ×3 จุด, assign-rooms → allocator, walk-in) + แก้ bug denominator (booking check นับ physical rooms เกิน capacity ขายได้) — implement ผ่าน **HTTP feature seam เดียว** (user-confirmed) — implementation tickets ถูก breakdown ครบถ้วนแล้ว พร้อมเริ่มที่ frontier ticket 01

## Notes

- **Domain:** KU HOME API — *(amend 2026-09-24 ตาม ticket 90)* "ห้องสำรอง" = `rooms.is_reserved = true` (pool membership, PgBoolean) — สถานะ `reserved_closed` ถูกถอดออกจาก machine แล้ว · กฎหัวใจ: **`maintenance` ถูกตัดเสมอ ทุกกรณี — flag `include_reserved` ขยาย pool เฉพาะกลุ่ม `is_reserved`**
- **Tracker = local-markdown:** map ที่ไฟล์นี้, tickets ใน `tickets/` · claim ticket = เติม `assignee:` ใน front-matter ก่อนลงมือ · blocking = front-matter `blocked-by` · ปิด ticket = `status: closed` + เขียน `## Resolution` ท้ายไฟล์
- **Decision ทั้งหมดอยู่ใน [spec.md](spec.md) § Implementation Decisions / Further Notes** — map เป็น index ไม่ restate
- Test seams ยืนยันโดยผู้ใช้แล้ว (2026-09-11): **HTTP feature seam เดียว** — allocator test ผ่าน assign-rooms แบบ indirect, ไม่เพิ่ม unit seam (prior art: RoomKingAvailabilityTest / BookingTest / FrontDeskTest)
- Migration เดียว (2026-09-24): เพิ่ม `rooms.is_reserved` + แปลงข้อมูล `reserved_closed → available + is_reserved=true` — หลังจุดนี้ไม่มี schema change เพิ่มใน effort นี้

## Decisions so far

- [spec § Implementation Decisions](spec.md): ขอบเขต = ดู + จองได้จริง (grill #1) · request-scoped param ไม่ persist (grill #2) · non-admin ส่ง flag = เมยายีเงียบ ๆ ไม่ 403 (grill #3) · response แทนที่ `available_rooms` + field โปร่งใส `sellable_rooms`/`reserved_rooms`, ไม่ส่ง flag = byte-identical (grill #4) · walk-in รวมใน scope (grill #7)
- [spec § Implementation Decisions](spec.md): align denominator ฝั่ง booking checks เป็น sellable pool แก้ bug overbooking-past-sellable ไปพร้อมกัน (grill #5)
- [ticket 90](tickets/90-reserved-room-checkin-lifecycle.md): **CLOSED 2026-09-24** — lifecycle ตัดสินด้วยโมเดล flag: `rooms.is_reserved` = pool membership ถาวร, ถอดสถานะ `reserved_closed` ออกจาก machine — check-in/checkout ไม่ต้อง flip อะไรเลย (หลัง checkout กลับ pool สำรองเองอัตโนมัติ) · spec ออก amendment, ticket 01 รับ model pivot รวม
- [Audit — assignAvailableRoom() เป็น dead code](spec.md): assignment จริงมี path เดียวคือ assign-rooms → allocator, flag จึงต้องถึงแค่ allocator + capacity checks

## Tickets

implementation tickets (breakdown จาก spec ด้วย to-tickets ครบ 6 tasks + 1 grilling ticket — 2026-09-16 · **amend 2026-09-24:** ตาม ticket 90 ใช้โมเดล `is_reserved` — ticket 01 รับ migration + สลับ filter ทุกจุด, tickets 02–05 คงขอบเขตเดิมแต่ query อ้าง flag ไม่ใช่สถานะ):

- `tickets/01-include-reserved-gate-and-availability.md` — 🧊 open (FROZEN — code landed ดู Progress ใน ticket) — tracer bullet: model pivot (migration + ถอดสถานะ) + gate helper + summary availability (+king counters) · blocked-by: []
- `tickets/02-calendar-availability-include-reserved.md` — ⚪ open — ปฏิทิน per-day + ranges · blocked-by: ["01-include-reserved-gate-and-availability"]
- `tickets/03-booking-capacity-sellable-pool.md` — ⚪ open — align denominator + flag (bug fix) · blocked-by: ["01-include-reserved-gate-and-availability"]
- `tickets/04-allocator-assign-rooms-include-reserved.md` — ⚪ open — allocator + assign-rooms + full-chain E2E · blocked-by: ["03-booking-capacity-sellable-pool"]
- `tickets/05-walk-in-include-reserved.md` — ⚪ open — walk-in guard + state machine compliance · blocked-by: ["01-include-reserved-gate-and-availability"]
- `tickets/06-docs-and-memory-closeout.md` — ⚪ open — docs (api_guide) + memory (cline.md) + tracker closeout · blocked-by: ["02-calendar-availability-include-reserved", "03-booking-capacity-sellable-pool", "04-allocator-assign-rooms-include-reserved", "05-walk-in-include-reserved"]
- `tickets/90-reserved-room-checkin-lifecycle.md` — ✅ closed (2026-09-24 — โมเดล flag ผ่าน HITL grill)

## Not yet specified

- ผลข้างเคียงระยะยาว: ถ้าห้อง reserved ถูก assign บ่อย ควรมี report/metric แยกหรือไม่ (ยังไม่อภิปราย)
- Admin endpoint toggle `is_reserved` (เพิ่ม/ถอดห้องเข้า pool สำรอง) — ยังไม่มี route; ระหว่างนี้ตั้งค่าผ่าน seed/DB ตรง · ค่อยตัดสินว่าเป็น `PUT /rooms/{id}` field, route แยก หรือเข้าคิว tickets ใหม่
