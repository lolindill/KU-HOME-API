---
label: wayfinder:task
type: AFK
title: "tests v2 — re-target split suite + suite ใหม่ tier-change family"
status: closed *(2026-11-11 — full suite 917 passed / 0 failed — de2e878)*
assignee: antigravity (agent-hub, session เดียวกับ ticket 01)
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

## Resolution (2026-11-11)

Implement โดย antigravity (session เดียวกับ ticket 01) — commit **`de2e878`** — **full suite: 917 passed / 0 failed (3,861 assertions)** · pint สะอาด:

- **`FrontDeskSplitUpgradeTest`** re-target 30 เคส (payload pure ไม่มี field ตัด) + เคส pure ใหม่: `sending_room_type_id_or_price_per_night_returns_422` · `same_tier_split_preserves_total_amount_invariant_and_zero_surcharge` · `pin_same_room_as_br1_returns_422` · `pin_room_id_mismatched_room_type_returns_422_before_other_checks` · `br1_without_room_spawns_br2_without_room` · `composition_split_then_change_room_on_br1` (e2e)
- **`TierChangeFamilyTest`** ใหม่ 12 เคส: mode 2 (staff allowed / staff manual price / downgrade+clamp+is_paid true / room_id 422 / param room_id 422 / guests cap / extra_beds cap / group reprice) + change-room (manual price / downgrade+syncIsPaid / confirmed ย้ายไม่แตะห้อง+ไม่มี task / checked_in ceremony / validate maintenance+overlap)
- **`ChangeRoomTest`** re-target gate test เป็น `change_room_gate_supports_draft_with_room_and_rejects_others` (draft+room → 200 re-point ล้วน / draft ไม่มีห้อง → 422 / no_show+checked_out → 422 — มติ ข้อ 7)
- **`BookingSurchargeAfterPaymentTest`** re-target: `downgrade_below_paid_is_allowed_with_no_refund_and_is_paid_true` + เพิ่ม `downgrade_above_paid_shrinks_outstanding` + `assigned_room_upgrade_type_rejects_and_points_to_change_room`

Review (ZCode): checklist ครบทุกข้อ + superseded 24 ตัวจาก ticket 01/02 หายจาก fail ทั้งหมด · ตัวอย่างที่สุ่มตรวจละเอียด (gate 3 กรณี / pin mismatch ก่อนเช็คอื่น) ตรง spec
