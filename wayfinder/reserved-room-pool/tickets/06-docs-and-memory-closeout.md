---
label: wayfinder:task
type: AFK
title: "Docs + memory closeout (api_guide / cline.md / tracker)"
status: closed
assignee: kevii
blocked-by: ["02-calendar-availability-include-reserved", "03-booking-capacity-sellable-pool", "04-allocator-assign-rooms-include-reserved"]
---

# 06: Docs + memory closeout (api_guide / cline.md / tracker)

**What to build:** สรุปและบันทึกเอกสารระบบเมื่อ implementation ครบถ้วนทุก surface ให้สะท้อนความจริงในโค้ด:
1. **API Guide (`docs/api_guide.md`):**
   - บันทึก contract ของ `include_reserved` ครบทั้ง 5 surfaces (summary availability, per-day calendar, ranges, booking create/edit capacity, auto-assign, walk-in)
   - บันทึกพฤติกรรม response payload ภายใต้ flag: การแทนที่ `available_rooms` ด้วย extended pool + ฟิลด์โปร่งใส `sellable_rooms` และ `reserved_rooms`
   - บันทึกพฤติกรรม Ignore Contract: Non-admin ส่ง flag จะถูกเมินเฉยเงียบ ๆ ไม่เกิด 403 และได้ผลลัพธ์เหมือนเดิม
   - บันทึก **Bug Fix Denominator**: อธิบายว่า booking capacity checks เปลี่ยนมานับจาก sellable pool (ตัด maintenance + reserved) ป้องกัน overbooking
   - บันทึก **Runbook การใช้งานห้องสำรอง (Operations)**:
     - ทางเลือกที่ 1: Flip สถานะด้วยมือ (`reserved_closed` → `available`) ผ่าน `PUT /api/v1/rooms/{id}/status` ก่อน check-in
     - ทางเลือกที่ 2: ใช้ `include_reserved` ในการ walk-in หรือ auto-assign
     - ชี้ reference ไปยัง Ticket 90 สำหรับประเด็น check-in lifecycle ที่ยังเปิดอยู่
2. **Project Memory (`cline.md`):**
   - บันทึก Decision Log จาก grill-me (7 grilled decisions)
   - บันทึกการเพิ่ม `include_reserved` param และการ align capacity denominator
3. **Wayfinder Tracker (`map.md` & `tickets/*.md`):**
   - อัปเดต `wayfinder/reserved-room-pool/map.md` สถานะ Destination เป็น implemented/closed
   - ตรวจสอบว่า tickets 01–05 ถูกบันทึก `status: closed` พร้อมหัวข้อ `## Resolution` และ commit hash ครบถ้วน
   - ปิด ticket 06 นี้เป็นใบสุดท้าย

**Files / Surfaces touched:**
- `docs/api_guide.md`
- `cline.md`
- `wayfinder/reserved-room-pool/map.md`
- `wayfinder/reserved-room-pool/tickets/*.md`

**Blocked by:** 02-calendar-availability-include-reserved, 03-booking-capacity-sellable-pool, 04-allocator-assign-rooms-include-reserved, 05-walk-in-include-reserved (ต้องรอทุกใบ implementation land ก่อน)

**Status:** ready-for-agent

- [x] `docs/api_guide.md`: บันทึก contract ของ `include_reserved` ครบ 5 surfaces + ตัวอย่าง payload + กติกา ignore
- [x] `docs/api_guide.md`: บันทึก bug fix การ align denominator ฝั่ง booking capacity check
- [x] `docs/api_guide.md`: บันทึก runbook ห้องสำรองสำหรับ front desk / operations + ชี้ map `room-state-periods`
- [x] `cline.md`: บันทึก refactor/decision changelog ครบถ้วน
- [x] `wayfinder/reserved-room-pool/map.md`: อัปเดต Destination + ปิด tickets 01–06 พร้อม `## Resolution` และ commit hashes
- [x] ตรวจสอบว่าไม่มี lint error หรือ format เสียหาย

**Spec Reference:**
- [spec.md § Further Notes](../spec.md#further-notes)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions)
- [spec.md § Out of Scope](../spec.md#out-of-scope)

## 🗓️ Amendment (2026-09-24 — unfreeze, re-base ตาม period model)

- **blocked-by ถอด ticket 05 ออก** — walk-in landed ครบแล้วผ่าน build ของ map `room-state-periods` (ปิดแล้ว)
- **บางส่วนของ docs landed ไปแล้ว:** `docs/api_guide.md` + `cline.md` มีหัวข้อ `room_state_periods` (state machine 5 สถานะ, CRUD periods, breaking change `is_reserved → active_periods`) จาก build ของ map ใหม่ — งานที่เหลือของ ticket นี้คือ **เอกสาร flag `include_reserved` เท่านั้น**: summary (+king) ที่ landed, calendar / booking-capacity / allocator ที่กำลังทำใน tickets 02–04 · ระวังข้อความเดิมด้านบนอ้าง `reserved_closed`/is_reserved = historical — ในเอกสารจริงต้องอ้าง reserved period
- Runbook ห้องสำรอง: ปรับเป็นโมเดล period — ทางเลือก 1 = สร้าง reserved period (POST /rooms/{roomId}/periods) ถอดด้วย PATCH/DELETE ทางเลือก 2 = `include_reserved` ใน walk-in / assign-rooms · อ้าง map `room-state-periods` แทน ticket 90 (ticket 90 ถูก override — จดใน Decisions so far ของ map แล้ว)
- tracker closeout: tickets 01, 05 ปิดแล้ว (2026-09-24) — เหลือ 02, 03, 04 และปิด 06 ท้ายสุด

## ✅ Resolution (2026-09-24 — session หนูเมด, wayfinder zcode)

**Docs-only session — ไม่มีการแก้โค้ด** (implementation ครบทุก surface จาก tickets 01–05 แล้ว · suite 526 เขียว ณ ปิด ticket 04):

1. **`docs/api_guide.md`:**
   - หัวข้อกลางใหม่ **"🏨 Flag `include_reserved` — ใช้ห้องสำรอง (reserved period) ครบ 5 surfaces (2026-09-24)"** (คั่นหลังหัวข้อ Room state periods): สัญญา param (boolean · request-scoped ไม่ persist · admin-only ผ่าน `IncludeReservedGate` · silent-ignore ไม่ 403 · ค่าไม่ใช่ boolean = ไม่ได้ส่ง) + ตาราง 5 surfaces + การแทนที่ `available_rooms` + ฟิลด์ `sellable_rooms`/`reserved_rooms` + **🐛 bug fix denominator** + **🎟️ Runbook ห้องสำรอง 2 ทางเลือก** (สร้าง/ถอด reserved period ผ่าน `/rooms/{roomId}/periods` หรือใช้ flag ทันทีบน walk-in/assign-rooms) + ห้ามแก้สถานะห้องหลอก pool — อ้าง map `room-state-periods` เป็นโมเดลเต็ม (แทน ticket 90 ที่ถูก override ตาม amendment)
   - `GET /availability` — param `include_reserved` + ตัวอย่าง payload ใต้ flag (`available_rooms` extended + breakdown + `search_criteria.include_reserved`) + โน้ต byte-identical / ใช้คู่ `bed_type=king_size` ได้
   - Calendar 4 endpoints (`/availability-per-day`, `/availability-ranges`, `/unavailable-dates`, `/unavailable-ranges`) — param + โน้ต matrix นับเฉพาะ maintenance period ใต้ flag + `search_criteria` top-level (รวมแก้ `/unavailable-ranges` เดิมบอก "ไม่รับ param อะไรเลย")
   - Booking 4 write paths — โน้ต denominator = **sellable pool ต่อช่วงเข้าพัก** + flag (create: โน้ตใหม่ใน block โน้ต · add/update/batch: ต่อท้าย bullet availability check เดิม)
   - `PUT .../assign-rooms` — Request Body (optional) + โน้ต ไม่ persist / fail-safe 422 / แตะแค่ `room_id` + ขยายเงื่อนไข 422 กรณีเหลือแต่ห้องสำรอง
   - `POST /front-desk/walk-in` — body เพิ่ม `include_reserved` + โน้ต period guard (maintenance reject เสมอ / reserved ผ่านใต้ flag) + ตัวอย่าง 400 ใหม่ 3 แบบ (ลบตัวอย่างเดิมที่อ้างสถานะ `maintenance` ที่ถูกถอด)
2. **`cline.md`:** หัวข้อใหม่ท้ายไฟล์ "🏨 include_reserved — flag ใช้ห้องสำรอง ครบ 5 surfaces + align capacity denominator (2026-09-24)" — **decision log grill 7 ข้อ** (คงผูกพันหลัง re-base) + gate เดียว + 5 surfaces + bug fix `sellableCapacity()` (ระบุ non-goal "คงนับ physical" ของ map `room-state-periods` ถูกยกเลิก — spec sync แล้ว) + สิ่งที่ flag ไม่ทำ + test 4 ชุด
3. **Tracker closeout:** ตรวจแล้ว tickets 01–05 ทุกใบมี `status: closed` + `## Resolution` (commit อ้างเป็นชื่อ build commit ตามธรรมเนียม map — `feat(rooms)` ของ build room-state-periods และ commits ต่อเนื่อง `feat(rooms)`/`feat(bookings)` บน branch `agust-11`) · map อัปเดต Decisions so far + Tickets list + **ปิด map** (`✅ COMPLETED` บนหัวข้อ Destination + frontmatter `status: closed`) · ticket นี้ปิดเป็นใบสุดท้าย

Lint/format: แก้เฉพาะ markdown (`docs/`, `cline.md`, `wayfinder/`) — ไม่มี PHP เปลี่ยน (`vendor/bin/pint --dirty` ไม่มีไฟล์ให้แตะ) · เอกสารอ้าง scope/`IncludeReservedGate` ตรวจกับโค้ดจริงทุกจุดก่อนเขียน

## 🗓️ Amendment (2026-09-24 — unfreeze, re-base ตาม period model)

- **blocked-by ถอด ticket 05 ออก** — walk-in landed ครบแล้วผ่าน build ของ map `room-state-periods` (ปิดแล้ว)
- **บางส่วนของ docs landed ไปแล้ว:** `docs/api_guide.md` + `cline.md` มีหัวข้อ `room_state_periods` (state machine 5 สถานะ, CRUD periods, breaking change `is_reserved → active_periods`) จาก build ของ map ใหม่ — งานที่เหลือของ ticket นี้คือ **เอกสาร flag `include_reserved` เท่านั้น**: summary (+king) ที่ landed, calendar / booking-capacity / allocator ที่กำลังทำใน tickets 02–04 · ระวังข้อความเดิมด้านบนอ้าง `reserved_closed`/is_reserved = historical — ในเอกสารจริงต้องอ้าง reserved period
- Runbook ห้องสำรอง: ปรับเป็นโมเดล period — ทางเลือก 1 = สร้าง reserved period (POST /rooms/{roomId}/periods) ถอดด้วย PATCH/DELETE ทางเลือก 2 = `include_reserved` ใน walk-in / assign-rooms · อ้าง map `room-state-periods` แทน ticket 90 (ticket 90 ถูก override — จดใน Decisions so far ของ map แล้ว)
- tracker closeout: tickets 01, 05 ปิดแล้ว (2026-09-24) — เหลือ 02, 03, 04 และปิด 06 ท้ายสุด
