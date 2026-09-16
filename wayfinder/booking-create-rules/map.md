---
label: wayfinder:map
title: "Booking create rules — จองล่วงหน้า ≥ 2 วัน + ลิมิต 4 ห้อง (non-admin)"
status: open
---

# Wayfinder Map — Booking create rules

## Destination

[spec.md](spec.md) (label `ready-for-agent`) implement จบ: non-admin จองล่วงหน้าได้
ตั้งแต่ +2 calendar days (Asia/Bangkok) และ <= 4 ห้องต่อ 1 booking (create + add-rooms
นับห้องเดิมรวม) · admin ตัดสินจาก login role เท่านั้น (ไม่ใช่ field `source`) exempt
ทั้ง 2 กฎ · ทั้ง 4 write paths ผ่าน · test suite เขียวครบ + api_guide/cline.md สะท้อนจริง

## Notes

- **Domain:** KU HOME API — booking writes อยู่ที่ 4 จุด (สร้าง / เพิ่มห้อง / แก้รายห้อง /
  แก้ batch) · validation ล้วน ไม่มี migration
- **Tracker = local-markdown:** map ที่ไฟล์นี้, tickets ใน `tickets/` · claim ticket =
  เติม `assignee:` ใน front-matter ก่อนลงมือ · ปิด ticket = `status: closed` + เขียน
  `## Resolution` ท้ายไฟล์ + append 1 บรรทัดใน "Decisions so far"
- Decision ทั้งหมดอยู่ใน [spec.md](spec.md) — map เป็น index ไม่ restate
- Test seams ที่ยืนยันแล้ว: **HTTP feature seam เดียว** — ไม่มี unit seam แยก (fewest-seams)
- ⚠️ บน branch มี uncommitted WIP (money-integer refactor) — อย่ารื้อ ไฟล์ไม่ทับกัน

## Decisions so far

- [นิยาม "จองก่อน 2 วัน" = calendar days](spec.md): grill-me 2026-09-11 — `check_in >= วันนี้+2` — ไม่ใช่ 48 ชม.เป๊ะ
- [role exempt = admin เท่านั้น](spec.md): grill-me 2026-09-11 — ตัดสินจาก login role ผ่าน sanctum guard, ห้ามใช้ `source`
- [ลิมิต 4 ห้อง = ต่อ 1 booking](spec.md): grill-me 2026-09-11 — create นับอาร์เรย์ · add-rooms นับห้องเดิม+ใหม่ — ไม่นับรวมข้าม booking
- [spec § Implementation Decisions](spec.md): config-driven (2/4 ผ่าน env) · helper กลางตัวเดียว · 422 ไทย · admin ยังห้ามย้อนหลัง · grandfathering drafts
- [tickets/](tickets/): breakdown 2 ใบ — 01 advance-notice rule (tracer bullet: config+helper+กฎวัน 4 paths+แก้ test เดิม+docs) → 02 room cap (blocked-by 01: ใช้ config+helper ต่อ + docs ต่อท้าย) — docs ห่อในแต่ละใบ ไม่แยกใบ
- [Ticket 01 — advance-notice rule](tickets/01-advance-notice-rule.md): จองล่วงหน้าอย่างน้อย 2 วัน (ปฏิทิน Bangkok) ผ่าน FormRequest ทั้ง 4 write paths, config-driven (default 2), admin exempt (เช็คจาก Sanctum role เท่านั้น ไม่ใช่ source=walk_in)

## Not yet specified

- (ไม่มี — decision ครบจาก grill-me session ทั้ง 3 คำถาม)

## Out of scope

- ลิมิตรวมข้าม booking ต่อ user · สิทธิ์ staff/system · ซ่อนวันใกล้ใน availability/calendar ·
  แก้ availability/state machine/throttle/routes · flow walk-in/line · repo frontend —
  รายละเอียดใน [spec § Out of Scope](spec.md)
