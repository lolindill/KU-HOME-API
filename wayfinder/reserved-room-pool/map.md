---
label: wayfinder:map
title: "include_reserved — ห้องสำรอง (reserved period) เข้า pool ฝั่ง admin"
status: open
---

# Wayfinder Map — `include_reserved` (ห้องสำรอง reserved period)

> **✅ UNFROZEN (2026-09-24):** เดิมถูก freeze รอ map `room-state-periods` — map นั้น **จบแล้ว (COMPLETED 2026-09-24)** และ build ลงแล้ว (commit `feat(rooms)`): โมเดลสุดท้ายคือ **period records** แทน `is_reserved` flag (decision ticket 90 ของ map นี้ถูก override — ดู Decisions so far) · งาน landed เดิมถูก rebase เป็น period model ครบ · session unfreeze ปิด tickets 01, 05 และ re-base tickets 02–04 + spec ให้คงเหลือเฉพาะงานจริง

## Destination

[spec.md](spec.md) (label `ready-for-agent`) — admin-only param `include_reserved` ครบ 5 surfaces (availability ×3, booking capacity checks ×3 จุด, assign-rooms → allocator, walk-in) + แก้ bug denominator (booking check นับ physical rooms เกิน capacity ขายได้) — implement ผ่าน **HTTP feature seam เดียว** (user-confirmed) — *(re-base 2026-09-24: พื้นฐานเป็น period model ของ `room-state-periods` แล้ว · summary+king+walk-in+calendar+booking capacity landed เสร็จ — เหลือ allocator และ docs closeout)*

## Notes

- **Domain:** KU HOME API — *(re-base 2026-09-24 ตาม map `room-state-periods`)* "ห้องสำรอง" = ห้องติด **reserved period** (`room_state_periods` kind `reserved`, start/end + เปิดปลายได้) — column `is_reserved` และสถานะ `reserved_closed`/`maintenance` ถูกถอดหมดแล้ว · กฎหัวใจคงเดิม: **maintenance period ถูกตัดเสมอ ทุกกรณี — flag `include_reserved` ขยาย pool เฉพาะกลุ่ม reserved period ที่ overlap ช่วงที่ขอ**
- **Tracker = local-markdown:** map ที่ไฟล์นี้, tickets ใน `tickets/` · claim ticket = เติม `assignee:` ใน front-matter ก่อนลงมือ · blocking = front-matter `blocked-by` · ปิด ticket = `status: closed` + เขียน `## Resolution` ท้ายไฟล์
- **Decision ทั้งหมดอยู่ใน [spec.md](spec.md) § Implementation Decisions / Further Notes** — map เป็น index ไม่ restate · **โมเดล period อยู่ใน `wayfinder/room-state-periods/spec.md`** (label `ready-for-agent`, build landed) — อ่านก่อนแตะ query ใด ๆ: shared scopes `freeOfPeriod`/`blockedByPeriod`/`overlapping` ห้ามเขียนเงื่อนไขเอง
- Test seams ยืนยันโดยผู้ใช้แล้ว (2026-09-11): **HTTP feature seam เดียว** — allocator test ผ่าน assign-rooms แบบ indirect, ไม่เพิ่ม unit seam (prior art: RoomKingAvailabilityTest / BookingTest / FrontDeskTest / RoomStatePeriodTest)
- **ไม่มี migration เพิ่มใน effort นี้** — ตาราง `room_state_periods` + scope ชุดเดียว landed แล้ว (migration `2026_09_24_170000` — เดิมมี `add_is_reserved` แต่ถูก drop ต่อใน migration เดียวกันตาม release เดียวของ map ใหม่)

## Decisions so far

- [spec § Implementation Decisions](spec.md): ขอบเขต = ดู + จองได้จริง (grill #1) · request-scoped param ไม่ persist (grill #2) · non-admin ส่ง flag = เมยายีเงียบ ๆ ไม่ 403 (grill #3) · response แทนที่ `available_rooms` + field โปร่งใส `sellable_rooms`/`reserved_rooms`, ไม่ส่ง flag = byte-identical (grill #4) · walk-in รวมใน scope (grill #7)
- [spec § Implementation Decisions](spec.md): align denominator ฝั่ง booking checks เป็น sellable pool แก้ bug overbooking-past-sellable ไปพร้อมกัน (grill #5) — ⚠️ *ถูกท้าทายโดย non-goal ของ map `room-state-periods` (คงนับห้องกายภาพ) — ticket 03 ต้อง grill ยืนยันกับ owner ก่อน*
- [ticket 90](tickets/90-reserved-room-checkin-lifecycle.md): **CLOSED 2026-09-24** — lifecycle ตัดสินด้วยโมเดล flag: `rooms.is_reserved` = pool membership ถาวร, ถอดสถานะ `reserved_closed` ออกจาก machine — **⚠️ ถูก override ทั้งก้อนโดย map `room-state-periods` (2026-09-24):** period แทน flag — `rooms.is_reserved` drop พร้อม release เดียว, "ห้องสำรอง" = reserved period ของตาราง `room_state_periods`
- [Audit — assignAvailableRoom() เป็น dead code](spec.md): assignment จริงมี path เดียวคือ assign-rooms → allocator, flag จึงต้องถึงแค่ allocator + capacity checks
- [ticket 01](tickets/01-include-reserved-gate-and-availability.md): **CLOSED 2026-09-24** — tracer bullet จบบน period model: โค้ด rebase โดย build ของ map `room-state-periods` (gate + summary availability + king counters ผ่าน `freeOfPeriod`/`blockedByPeriod`) + session unfreeze เขียน `RoomAvailabilityIncludeReservedTest` 4 tests — suite 501 เขียว + pint
- [ticket 05](tickets/05-walk-in-include-reserved.md): **CLOSED 2026-09-24** — walk-in landed ครบผ่าน build ของ map `room-state-periods` (touchpoint 11): reserved period ผ่านเฉพาะ admin flag / maintenance period reject เสมอ / bonus checkIn gate — tests ใน `RoomStatePeriodTest` ครบ
- [ticket 02](tickets/02-calendar-availability-include-reserved.md): **CLOSED 2026-09-24** — calendar 4 endpoints รับ flag: `addPeriodsToOccupied()` กรอง kind (flag → matrix นับเฉพาะ maintenance period, reserved ปล่อยวันเป็นว่าง) + `search_criteria.include_reserved` top-level เฉพาะเมื่อ flag มีผล · `RoomCalendarIncludeReservedTest` 11 tests — suite 512 เขียว + pint
- [ticket 03](tickets/03-booking-capacity-sellable-pool.md): **CLOSED 2026-09-24** — grill ยืนยันกับ owner: **คง bug fix ตาม grill #5** (non-goal "คงนับ physical" ของ map `room-state-periods` ถูกยกเลิก — spec ทั้งสอง sync แล้ว) · denominator 4 จุด = `sellableCapacity()` ต่อช่วงเข้าพัก (freeOfPeriod maintenance เสมอ + reserved ใต้ flag admin) · `BookingCapacitySellablePoolTest` 8 tests — suite 520 เขียว + pint

## Tickets

implementation tickets (breakdown จาก spec ด้วย to-tickets ครบ 6 tasks + 1 grilling ticket — 2026-09-16 · **re-base 2026-09-24:** บน period model — tickets 02–04 มี amendment ในตัว, 01/05 ปิดแล้ว):

- `tickets/01-include-reserved-gate-and-availability.md` — ✅ closed (2026-09-24 — rebase period model + test ครบ suite 501)
- `tickets/02-calendar-availability-include-reserved.md` — ✅ closed (2026-09-24 — calendar 4 endpoints รับ flag บน period matrix + tests 11)
- `tickets/03-booking-capacity-sellable-pool.md` — ✅ closed (2026-09-24 — grill ยืนยันคง bug fix; denominator 4 จุด = sellableCapacity + tests 8, suite 520)
- `tickets/04-allocator-assign-rooms-include-reserved.md` — ⚪ open — allocator + assign-rooms รับ flag บน period-check scopes + full-chain E2E · blocked-by: [] ← frontier
- `tickets/06-docs-and-memory-closeout.md` — ⚪ open — docs (api_guide) + memory (cline.md) + tracker closeout (บางส่วน landed จาก map ใหม่ — เหลือเอกสาร flag) · blocked-by: ["02-calendar-availability-include-reserved", "03-booking-capacity-sellable-pool", "04-allocator-assign-rooms-include-reserved"]
- `tickets/90-reserved-room-checkin-lifecycle.md` — ✅ closed (2026-09-24 — โมเดล flag ผ่าน HITL grill · **ถูก override โดย period model ของ map `room-state-periods`**)

## Not yet specified

- ผลข้างเคียงระยะยาว: ถ้าห้อง reserved ถูก assign บ่อย ควรมี report/metric แยกหรือไม่ (ยังไม่อภิปราย — ข้อมูลพร้อมอยู่แล้วในตาราง `room_state_periods` และ audit log)
- ~~Admin endpoint toggle `is_reserved`~~ — **resolve ไปแล้วโดย map `room-state-periods`:** CRUD periods endpoint (`POST/PATCH/DELETE /rooms/{roomId}/periods` + auto-merge + audit) landed แล้ว — การเพิ่ม/ถอดห้องเข้า pool สำรอง = จัดการ reserved period ผ่าน endpoint นั้น
