---
label: wayfinder:task
type: AFK
title: "tests v2 — re-target split suite + suite ใหม่ tier-change family"
status: open
assignee:
blocked-by: ["01-tier-change-family.md", "02-split-pure.md"]
---

# 03: Test suite v2

## Question

Re-target `tests/Feature/FrontDeskSplitUpgradeTest.php` (30 เคสเดิม) ตาม pure-split semantics ใหม่ + เขียน suite ครอบ tier-change family ใหม่ (PUT โหมด 2 / change-room ขยาย) ตาม [map](../map.md) มติ v2 — seam เดียว HTTP feature บน SQLite :memory: (style เดิมจาก suite shipped)

## Checklist

**Split pure (re-target เดิม):**
- [ ] เดิมครอบพฤติกรรมคงเดิม (สถานะ gate/ส่วนลด/split_date/rollback/audit/repeat/deferred/multi-room freeze) — ปรับ payload ใหม่ (ไม่มี room_type_id/price_per_night)
- [ ] ใหม่: ส่ง room_type_id หรือ price_per_night → 422 · total คงเดิมเป๊ะ (surcharge 0, invariant) · BR-1 มีห้อง → BR-2 ได้ห้องต่างเลข type เดียวกัน (pin ซ้ำห้องเดิม 422 / pin ผิด type 422 / auto-allocate ไม่เลือกห้องเดิม) · BR-1 ไม่มีห้อง + ไม่ pin → BR-2 room_id null · อัปเกรดครึ่งแรกผ่าน composition: split → change-room บน BR-1 (e2e)

**PUT โหมด 2 (ใหม่):**
- [ ] staff ยิงผ่าน (role ใหม่) · staff manual price ผ่าน · downgrade: total ลด, outstanding clamp 0, **is_paid กลับ true** (syncIsPaid) · upgrade: surcharge envelope + is_paid reset เดิม · BR มี room_id → 422 ชี้ change-room · guests cap tier ใหม่ · extra_beds cap tier ใหม่ · billRoomCount group reprice pipeline เดิมไม่พัง

**change-room ขยาย (ใหม่ + regression):**
- [ ] BR confirmed + มีห้อง: ย้ายได้ก่อนเช็คอิน — สถานะห้องกายภาพทั้งคู่คงเดิม ไม่มี housekeeping task · checked_in: ceremony ครบเหมือนเดิม (regression suite เดิม) · price_per_night manual (admin+staff) · downgrade tier ผ่าน + เงินตามมติ v2 ข้อ 1 · upgrade surcharge เดิม · validate ห้องใหม่ (maintenance/holdingSlot/สถานะ) regression

## Definition of done

suite ผ่านครบ + `composer run test` full suite ไม่มี regression · รายงานจำนวนเคส/assertions ใน resolution · pint สะอาด
