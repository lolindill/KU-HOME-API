---
label: wayfinder:map
title: "include_reserved — ห้องสำรอง (reserved_closed) เข้า pool ฝั่ง admin"
status: open
---

# Wayfinder Map — `include_reserved` (ห้องสำรอง reserved_closed)

## Destination

[spec.md](spec.md) (label `ready-for-agent`) — admin-only param `include_reserved` ครบ 5 surfaces (availability ×3, booking capacity checks ×3 จุด, assign-rooms → allocator, walk-in) + แก้ bug denominator (booking check นับ physical rooms เกิน capacity ขายได้) — implement ผ่าน **HTTP feature seam เดียว** (user-confirmed) — implementation tickets รอ breakdown ด้วย to-tickets

## Notes

- **Domain:** KU HOME API — Room state machine มีสถานะ `reserved_closed` (ห้องสำรอง) อยู่แล้ว, wildcard transitions เข้า/ออก · user pool ตัด `maintenance` + `reserved_closed` อยู่แล้วทั้ง display/allocator · กฎหัวใจ: **`maintenance` ถูกตัดเสมอ ทุกกรณี — flag ขยายเฉพาะ `reserved_closed`**
- **Tracker = local-markdown:** map ที่ไฟล์นี้, tickets ใน `tickets/` · claim ticket = เติม `assignee:` ใน front-matter ก่อนลงมือ · blocking = front-matter `blocked-by` · ปิด ticket = `status: closed` + เขียน `## Resolution` ท้ายไฟล์
- **Decision ทั้งหมดอยู่ใน [spec.md](spec.md) § Implementation Decisions / Further Notes** — map เป็น index ไม่ restate
- Test seams ยืนยันโดยผู้ใช้แล้ว (2026-09-11): **HTTP feature seam เดียว** — allocator test ผ่าน assign-rooms แบบ indirect, ไม่เพิ่ม unit seam (prior art: RoomKingAvailabilityTest / BookingTest / FrontDeskTest)
- ไม่มี migration / ไม่มี schema change / ไม่แตะ state machine — งานนี้ flag เป็น request-scoped ล้วน

## Decisions so far

- [spec § Implementation Decisions](spec.md): ขอบเขต = ดู + จองได้จริง (grill #1) · request-scoped param ไม่ persist (grill #2) · non-admin ส่ง flag = เมยายีเงียบ ๆ ไม่ 403 (grill #3) · response แทนที่ `available_rooms` + field โปร่งใส `sellable_rooms`/`reserved_rooms`, ไม่ส่ง flag = byte-identical (grill #4) · walk-in รวมใน scope (grill #7)
- [spec § Implementation Decisions](spec.md): align denominator ฝั่ง booking checks เป็น sellable pool แก้ bug overbooking-past-sellable ไปพร้อมกัน (grill #5)
- [ticket 90](tickets/90-reserved-room-checkin-lifecycle.md): ห้องที่ถูก assign คงสถานะ `reserved_closed` — ไม่ auto-flip (grill #6) · lifecycle ตอน check-in (flip มือ vs auto) เปิด ticket รอตัดสินใจ
- Audit: `BookingRoom::assignAvailableRoom()` เป็น dead code — assignment จริงมี path เดียวคือ assign-rooms → allocator, flag จึงต้องถึงแค่ allocator + capacity checks

## Tickets

implementation tickets (breakdown จาก spec ด้วย to-tickets, user-approved granularity — 2026-09-11):

- `tickets/01-include-reserved-gate-and-availability.md` — ⚪ open — tracer bullet: gate helper + summary availability (+king counters) · **frontier เริ่มได้เลย**
- `tickets/02-calendar-availability-include-reserved.md` — ⚪ open — ปฏิทิน per-day + ranges · blocked-by 01
- `tickets/03-booking-capacity-sellable-pool.md` — ⚪ open — align denominator + flag (bug fix) · blocked-by 01
- `tickets/04-allocator-assign-rooms-include-reserved.md` — ⚪ open — allocator + full-chain E2E · blocked-by 03
- `tickets/05-walk-in-include-reserved.md` — ⚪ open — walk-in guard · blocked-by 01
- `tickets/06-docs-and-memory-closeout.md` — ⚪ open — docs + memory · blocked-by 02,03,04,05
- `tickets/90-reserved-room-checkin-lifecycle.md` — 🟢 open (HITL, อิสระ ไม่ block implementation)

## Not yet specified

- ผลข้างเคียงระยะยาว: ถ้าห้อง reserved ถูก assign บ่อย ควรมี report/metric แยกหรือไม่ (ยังไม่อภิปราย)
- นโยบาย flip สถานะหลัง checkout ของห้อง reserved ที่เคยถูกใช้ (ให้กลับ `reserved_closed` เองไหม) — อยู่ใน ticket 90 ด้วย
