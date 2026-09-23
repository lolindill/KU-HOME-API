---
label: wayfinder:task
type: AFK
title: "Booking capacity checks — align เป็น sellable pool + flag extension (bug fix)"
status: open
assignee:
blocked-by: ["01-include-reserved-gate-and-availability"]
---

# 03: Booking capacity checks — align เป็น sellable pool + flag extension (bug fix)

**What to build:**
1. **Denominator Bug Fix (Grill #5):**
   - ปัจจุบันการตรวจ capacity ใน write paths ของการจองนับ denominator จาก **physical rooms ทั้งหมด** (`Room::where('room_type_id', $rtId)->count()`) ซึ่งรวมห้อง `maintenance` และ `reserved_closed` ทำให้ผู้ใช้ทั่วไปสามารถจองห้องเกิน sellable capacity ที่มีจริงได้ แล้วการจองค้างไม่มีห้องให้ assign
   - ปรับ denominator เริ่มต้นของทั้งระบบให้เป็น **sellable pool** (`status NOT IN ('maintenance', 'reserved_closed')`) ให้ตรงกับ availability display และ room allocator ทันที
2. **Admin `include_reserved` Flag Extension:**
   - Write paths รับ boolean param `include_reserved`:
     - สร้างบุ๊กกิ้งใหม่: `POST /api/v1/bookings` (`BookingController::createBooking`)
     - เพิ่มห้องในบุ๊กกิ้ง: `POST /api/v1/bookings/{bookingId}/rooms` (`BookingController::addRooms`)
     - แก้ไขห้องเดี่ยว: `PUT /api/v1/bookings/{bookingId}/rooms/{bookingRoomId}` (`BookingController::updateRoom`)
     - แก้ไขห้องแบบกลุ่ม: `PUT /api/v1/bookings/{bookingId}/rooms` (`BookingController::updateRooms`)
   - **Admin ส่ง flag:** ขยาย denominator เป็น `sellable + reserved_closed` (ตัดเฉพาะ `maintenance`) ทำให้ admin สามารถสร้างหรือแก้ไขบุ๊กกิ้งให้ใช้ห้องสำรองได้เมื่อห้องปกติเต็ม
   - **Non-admin ส่ง flag:** เมินเฉยเงียบ ๆ (silently ignored) บังคับใช้ sellable pool ปกติเสมอ
   - **กฎเหล็กสัมบูรณ์ (Maintenance is absolute):** ห้อง `maintenance` ต้อง**ไม่**ถูกนับใน denominator เด็ดขาด ไม่ว่า admin จะส่ง flag หรือไม่ก็ตาม
3. ใช้ shared gate helper จากตั๋ว 01 ในการตัดสิน admin และ parse flag

**Files / Surfaces touched:**
- `app/Http/Controllers/Api/V1/BookingController.php` (methods: `createBooking`, `addRooms`, `updateRoom`, `updateRooms`)
- Tests: `tests/Feature/BookingCapacitySellablePoolTest.php`

**Blocked by:** 01-include-reserved-gate-and-availability

**Status:** ready-for-agent

- [ ] Regression Bug Fix: Non-admin จองห้องเกิน sellable capacity (ทั้งที่ physical rooms รวม maintenance/reserved ยังมี) → ต้องถูกปฏิเสธด้วย 422 ข้อความไทยห้องเต็ม (ครอบคลุม create, add-rooms, update-room, updateRooms)
- [ ] Admin ส่ง `include_reserved=true` → denominator ขยายเป็น sellable + reserved_closed จองผ่านได้ตามจำนวนห้องสำรองที่มี
- [ ] Admin ส่ง `include_reserved=true` จองเกิน sellable + reserved_closed (เช่น พยายามจองกินห้อง maintenance) → ต้องถูกปฏิเสธ 422
- [ ] Non-admin ส่ง flag `include_reserved=true` → เมินเฉยเงียบ ๆ ใช้ sellable pool ปกติ (ไม่ขยาย pool และไม่เออเร่อ 403)
- [ ] ห้องสถานะ `maintenance` ไม่ถูกนับรวมใน denominator ทุกกรณี
- [ ] Transactional boundary และ audit trail ของการจองเดิมทำงานถูกต้อง ไม่ผิดเพี้ยน
- [ ] Single HTTP feature seam tests ครอบคลุมทั้งกรณี bug fix และ flag extension
- [ ] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`

**Spec Reference:**
- [spec.md § Problem Statement](../spec.md#problem-statement)
- [spec.md § Solution](../spec.md#solution)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions) (Surfaces touched #3, Maintenance is absolute, Grill #5)
- [spec.md § Testing Decisions](../spec.md#testing-decisions)
