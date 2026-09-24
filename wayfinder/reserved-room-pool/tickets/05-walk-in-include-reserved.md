---
label: wayfinder:task
type: AFK
title: "Walk-in รับ include_reserved"
status: closed
assignee: zcode session (2026-09-24 — ปิดหลัง unfreeze · งาน landed ครบผ่าน build ของ map room-state-periods)
blocked-by: ["01-include-reserved-gate-and-availability"]
---

# 05: Walk-in รับ `include_reserved`

**What to build:** รองรับการเปิดห้องสำรองให้แขก Walk-in ทันทีที่เคาน์เตอร์ Front Desk:
1. **Front Desk Walk-in Endpoint:** `POST /api/v1/front-desk/walk-in` (`FrontDeskController::walkIn`)
   - รับ boolean param `include_reserved`
   - ปรับปรุง **Room status guard**:
     - พฤติกรรมปกติ: ยอมรับเฉพาะ `['available', 'prep_checkin']`
     - **เมื่อ Admin ส่ง `include_reserved=true`:** ขยายให้ยอมรับสถานะ `reserved_closed` เพิ่มเติม
2. **State Machine Transition Compliance:**
   - การ walk-in ต้องเปลี่ยนสถานะห้องเป็น `occupied`
   - เนื่องจากใน `Room` model state machine นั้น transition จาก `reserved_closed -> occupied` ไม่อยู่ใน direct allowed list แต่ `reserved_closed -> available` เป็น legal transition และ `available -> occupied` เป็น legal transition:
     - ให้ทำ transition ตามลำดับที่ถูกต้องตาม state machine (`reserved_closed` → `available` → `occupied`) โดยไม่ต้องแก้ไข room state machine definition (คงกฎ state machine ไว้ตาม Grill #6 และ User Story #19)
3. **Guard Failures:**
   - หากเลือกห้อง `reserved_closed` แต่ไม่ส่ง flag (หรือ non-admin ส่ง flag) → ถูกปฏิเสธด้วย 422 เหมือนเดิม (Runbook เดิมยังใช้ได้: เจ้าหน้าที่ flip ห้องเป็น `available` ก่อน)
   - ห้องสถานะ `maintenance` → ถูกปฏิเสธด้วย 422 ทุกกรณี ไม่ว่าจะส่ง flag หรือไม่ก็ตาม
4. ใช้ shared gate helper จากตั๋ว 01

**Files / Surfaces touched:**
- `app/Http/Controllers/Api/V1/FrontDeskController.php` (method `walkIn`)
- Tests: `tests/Feature/FrontDeskWalkInIncludeReservedTest.php`

**Blocked by:** 01-include-reserved-gate-and-availability

**Status:** ready-for-agent

- [ ] Admin ส่ง `include_reserved=true` และระบุ room_id ของห้อง `reserved_closed` → walk-in สำเร็จ (booking อยู่สถานะ confirmed, booking_room อยู่สถานะ checked_in, สถานะห้องเปลี่ยนเป็น occupied)
- [ ] เลือกระบุห้อง `reserved_closed` แต่ไม่ส่ง flag → ถูกปฏิเสธด้วย 422 พร้อมข้อความระบุว่าห้องไม่พร้อมสำหรับ walk-in
- [ ] เลือกระบุห้อง `maintenance` → ถูกปฏิเสธด้วย 422 เสมอในทุกกรณี ไม่ว่าจะส่ง flag หรือไม่
- [ ] กระบวนการ walk-in ไม่ฝ่าฝืนกฎ Room state machine และบันทึก audit / timestamps ถูกต้อง
- [ ] Single HTTP feature seam tests ครอบคลุมพฤติกรรม walk-in ทั้งหมด
- [ ] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`

**Spec Reference:**
- [spec.md § Solution](../spec.md#solution)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions) (Surfaces touched #5, Maintenance is absolute, Grill #7)
- [spec.md § Testing Decisions](../spec.md#testing-decisions)

## ✅ Resolution (2026-09-24 — ปิดหลัง unfreeze)

**งาน landed ครบโดย build ของ map `room-state-periods`** (spec touchpoint 11, commit `feat(rooms)` 2026-09-24 — รวมอยู่ใน suite 501 เขียว) · โมเดลสุดท้ายเป็น **period** ไม่ใช่สถานะ:

- `FrontDeskController::walkIn` — guard บนช่วง `[today, today+nights)`: มี **maintenance** period overlap → reject เสมอ (ทุกกรณี flag/ไม่ flag) · มี **reserved** period overlap → ผ่าน**เฉพาะ** `IncludeReservedGate::enabled($request)` (admin + flag) · ไม่ส่ง/ไม่ใช่ admin → reject 422 เหมือนเดิม · เงื่อนไขสถานะ available/prep_checkin คงเดิม
- **State machine compliance — ประเด็นเดิมหมดไปเอง:** สถานะ `reserved_closed` ถูกถอดจาก machine (ticket 90 overridden โดย period model) — walk-in ใต้ flag ทำ transition ปกติเส้นเดียว `available → occupied` ผ่าน `transitionStatusTo()` พร้อม audit log
- **Bonus จาก spec ใหม่:** `checkIn` ได้ gate เพิ่ม — ห้องปลายทางมี maintenance period overlap ช่วงพัก → 422 / reserved period ไม่บล็อก
- Tests (ใน `RoomStatePeriodTest`): `test_walkin_rejects_maintenance_period_and_reserved_without_flag` · `test_walkin_into_reserved_period_passes_with_admin_flag` · `test_checkin_rejects_room_with_maintenance_period_overlap` · `test_checkin_into_reserved_period_passes` — checklist ด้านบนครบทุกข้อ (ไฟล์ `FrontDeskWalkInIncludeReservedTest.php` ตามที่ ticket ระบุไม่จำเป็น — coverage อยู่ใน RoomStatePeriodTest แล้ว)
