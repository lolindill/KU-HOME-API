---
label: wayfinder:task
type: AFK
title: "HTTP feature suite — split-upgrade (ตาม seam ของ room-auto-assign-test)"
status: open
assignee:
blocked-by: ["01-implement-split-upgrade.md"]
---

# 02: Feature test suite สำหรับ split-upgrade

## Question

สร้าง `tests/Feature/FrontDeskSplitUpgradeTest.php` ครอบ contract ของ [tickets/01](01-implement-split-upgrade.md) บน SQLite :memory: — seam แบบ HTTP feature เดียวตาม decision ของ map `room-auto-assign-test` (2026-09-11) ไม่เพิ่ม unit seam

## Checklist เคส (ทั้ง 200 และ 4xx)

**Auth/scope**
- [ ] 401 ไม่ล็อกอิน · 403 role อื่น (user/guest/housekeeping — รวม owner ของบิล)
- [ ] 404 bookingRoomId ของบิลอื่น (scope ผ่านบิลเสมอ)

**Validation 422**
- [ ] split_date ออกนอก `(check_in, check_out)` ทั้งสองขอบ + เท่ากับ check_in/check_out เป๊ะ
- [ ] room_type_id = เดิม (ไม่ใช่ upgrade) / tier ต่ำกว่า / ไม่มีจริง
- [ ] `price_per_night` โดน staff ยิง → 422 · admin ยิงแต่ต่ำกว่า `ceil(room_amount₀/n₀)` → 422
- [ ] บิลมี discount_code/redemption → 422 ข้อความบอกข้อจำกัด v1
- [ ] guests เกิน `max_guests` ของ tier ใหม่
- [ ] extra_beds_by_night คีย์ครึ่งหลังเกิน `max_extra_beds` tier ใหม่
- [ ] allocate fail (pool tier ใหม่ว่าง / pin room_id สถานะ maintenance) → 422 + **rollback ตรวจว่า BR-1 ยังมี check_out/room_amount เดิม + ไม่มี BR-2 เกิด + addon row ไม่ถูกแยก**

**Happy path (admin + staff แยกเคส)**
- [ ] checked_in วันนี้: BR-1 trim + BR-2 `confirmed` · BR-1 คง `checked_in` (ไม่มี auto ceremony — มติ #3) · ห้องกายภาพทั้งสองสถานะเดิม
- [ ] backdated: split_date < วันนี้ ผ่านได้ ผลเหมือนข้างบน
- [ ] future: BR-2 confirmed + room_id ถูกยึด — ยิง check-in ห้องเดียวกันด้วย BR อื่น (บิลมือสาม) ทับช่วง → โดน overlap guard 422
- [ ] admin กรอก price_per_night: total = intdiv(room_amount₀×n₁,n₀) + price×n₂ + addon → surcharge envelope ตรงสูตร + outstanding/max(0,…) ถูก
- [ ] staff ไม่กรอก price: BR-2 ใช้ computed `getEffectiveDailyRate` ของ tier ใหม่
- [ ] addon re-slice: extra_beds_by_night แบ่งถูกครึ่ง + breakfast ยอด = sets × rate × คืนของแต่ละ BR (รวมเท่าเดิมเมื่อ rate ไม่เปลี่ยน) + early_hours คง BR-1 + late_hours ย้าย BR-2
- [ ] audit: `status_change_logs` มี 2 แถว from=to ของ BR-1/BR-2 พร้อม note + causer
- [ ] invariant `Σ booking_rooms.amount == total_amount` + booking_type mirror ไม่พัง
- [ ] บิล multi-room: BR อื่นในบิลไม่ถูกแตะ room_id · availability ของบิลนับถูกหลัง split (holdingSlot ทั้งคู่)
- [ ] split ซ้ำบน BR-2 (ที่ checked_in แล้ว) สำเร็จ
- [ ] deferred bill: split ผ่าน + outstanding เพิ่มตาม surcharge

**Money guard**
- [ ] simulate เคส total ลด (เช่น BR อื่นในบิลโดน group flip หลังจำนวน BR ข้าม 5) → 422 fail-safe + rollback

## Definition of done

suite ใหม่ผ่านครบ + `composer run test` (full suite) ไม่มี regression · รายงานจำนวนเคส/assertions ไว้ใน resolution ตามธรรมเนียม map
