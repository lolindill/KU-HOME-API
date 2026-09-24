---
label: wayfinder:map
title: "room-state-periods — ห้องสำรอง/ซ่อมแซม กำหนดระยะเวลาแบบ booking"
status: open
---

# Wayfinder Map — `room-state-periods` (ห้องสำรอง/ซ่อมแซม มีระยะเวลา)

## Destination

ตัดสิน + spec (`ready-for-agent`) ของโมเดล **กำหนดระยะเวลา (period)** ให้สถานะ "ห้องสำรอง" และ "ซ่อมแซม (maintenance)" ทำงานเหมือน booking — เป็นช่วงวันที่ ไม่ใช่สถานะตายตัว — รวมผลที่ตามมาทั้งหมด: ผลต่อ decision `is_reserved` flag (ticket 90 ของ map `reserved-room-pool`), state machine, การนับ availability ทุก endpoint, และงาน freeze ค้างของ map เดิม

## Notes

- **Domain:** KU HOME API — ปัจจุบัน (2026-09-24) "ห้องสำรอง" = `rooms.is_reserved` boolean ถาวร (pool membership — decision ticket 90), "ซ่อมแซม" = สถานะ `maintenance` ไม่มีวันสิ้นสุด — req ใหม่อยากให้ทั้งคู่เป็น **ช่วงเวลา** (เริ่ม–สิ้นสุด) แบบ `booking_rooms` (check_in/check_out) · *(amend 2026-09-24: ticket 01 ปิดแล้ว — โมเดล period ผ่าน รายละเอียด is_reserved/maintenance ด้านบนเป็น historical จะถูกถอดตาม resolution)*
- **Map ที่เกี่ยว:** `wayfinder/reserved-room-pool/` 🧊 FROZEN รอ map นี้จบ — ticket 01 ของ map นั้น code landed แล้ว (migration `is_reserved` + ถอดสถานะ `reserved_closed` + gate helper + availability flag, suite 446 เขียว) — โมเดลที่ตัดสินที่นี่อาจให้ปรับ/ต่อยอดงานก้อนนั้น
- **Prior art ต้องอ่านก่อน grill:** overlap query ของ `booking_rooms` (`check_in < X AND check_out > X`, BR states draft/confirmed/checked_in), `CleanupExpiredDrafts` (scheduled sweep 02:00 — pattern สำหรับ period หมดอายุ), `Room::transitionStatusTo()` state machine, availability endpoints ทั้ง 3
- **Tracker = local-markdown:** map ที่ไฟล์นี้, tickets ใน `tickets/` · claim = เติม `assignee:` · blocking = `blocked-by` · ปิด = `status: closed` + `## Resolution`
- 🆕 **(2026-09-24) ticket 03 เพิ่มจาก gap ตรวจ SRS v2:** [ประวัติสถานะห้องย้อนหลัง 1 ปี — REQ-039](./tickets/03-room-status-history-log.md) (`Room::transitionStatusTo()` ปัจจุบันไม่มี audit log) — blocked-by grill 01/02 เพราะโมเดล period อาจเปลี่ยนรูปร่างของ "ประวัติ"
- ธรรมเนียมเดิมของโปรเจกต์: ตัดสินใจ lock ก่อนเขียนโค้ด — map นี้เริ่มด้วย grilling HITL สองรอบ (โมเดล → กฎ) ก่อนค่อย breakdown implementation

## Decisions so far

- [ticket 01 — Grill โมเดล period](tickets/01-grill-period-model-vs-flag.md): **CLOSED 2026-09-24** — **period แทน flag ทั้งก้อน:** ตารางเดียว `room_state_periods` (kind `reserved`|`maintenance`, start/end) + **derived ตอน query** ไม่มี sweep (philosophy `holdingSlot()`) · ถอด `is_reserved` **และ** สถานะ `maintenance` ออกจาก machine (single source of truth = period) · reserved period admin override ผ่าน `include_reserved` ได้ / maintenance period ตัดเด็ดขาด — decision ticket 90 ของ map `reserved-room-pool` ถูก override (map นั้นยัง FROZEN รอ map นี้จบ)

## Not yet specified

- Dashboard/display ที่อ่านสถานะ `maintenance` ตรง ๆ (room status board, มุมมอง housekeeping) — จะเข้า spec assembly หลังกฎ (ticket 02) ปิด
- กฎ overlap ระหว่าง period กับ period (สอง period ซ้อนกันได้ไหม ต่าง kind ล่ะ) — จะยกขึ้นใน ticket 04 (CRUD contract) หรือ spec

## Out of scope

- (ยังไม่มี)
