---
label: wayfinder:task
type: AFK
title: "Walk-in รับ include_reserved"
status: open
assignee:
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
