---
label: wayfinder:task
type: AFK
title: "Booking capacity checks — align เป็น sellable pool + flag extension (bug fix)"
status: closed
assignee: zcode (session 2026-09-24)
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

- [x] Regression Bug Fix: Non-admin จองห้องเกิน sellable capacity (ทั้งที่ physical rooms รวม maintenance/reserved ยังมี) → ต้องถูกปฏิเสธด้วย 422 ข้อความไทยห้องเต็ม (ครอบคลุม create, add-rooms, update-room, updateRooms)
- [x] Admin ส่ง `include_reserved=true` → denominator ขยายเป็น sellable + reserved_closed จองผ่านได้ตามจำนวนห้องสำรองที่มี
- [x] Admin ส่ง `include_reserved=true` จองเกิน sellable + reserved_closed (เช่น พยายามจองกินห้อง maintenance) → ต้องถูกปฏิเสธ 422
- [x] Non-admin ส่ง flag `include_reserved=true` → เมินเฉยเงียบ ๆ ใช้ sellable pool ปกติ (ไม่ขยาย pool และไม่เออเร่อ 403)
- [x] ห้องสถานะ `maintenance` ไม่ถูกนับรวมใน denominator ทุกกรณี
- [x] Transactional boundary และ audit trail ของการจองเดิมทำงานถูกต้อง ไม่ผิดเพี้ยน
- [x] Single HTTP feature seam tests ครอบคลุมทั้งกรณี bug fix และ flag extension
- [x] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`

**Spec Reference:**
- [spec.md § Problem Statement](../spec.md#problem-statement)
- [spec.md § Solution](../spec.md#solution)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions) (Surfaces touched #3, Maintenance is absolute, Grill #5)
- [spec.md § Testing Decisions](../spec.md#testing-decisions)

## ⚠️ Amendment (2026-09-24 — unfreeze, re-base ตาม period model + conflict ต้องเคลียร์ก่อน)

โมเดลพื้นฐานเปลี่ยน — "ห้องสำรอง" = ห้องติด **reserved period** (`room_state_periods` kind `reserved`) ไม่ใช่สถานะ `reserved_closed` / column `is_reserved` (ถูก drop แล้ว — map `room-state-periods`) · ข้อความเดิมด้านบนอ้างสถานะ = historical

**🥊 conflict ที่ต้อง grill กับ owner ก่อนลงมือ (HITL ขั้นแรกของ ticket นี้):**

- **spec เดิม (grill #5 ของ map นี้, 2026-09-11):** align denominator ทั้ง 4 จุดเป็น sellable pool = **bug fix** (กันจองเกินแล้ว assign ไม่ได้)
- **spec ของ map `room-state-periods` § non-goals (owner, 2026-09-24):** capacity denominators 4 จุด **คงนับห้องกายภาพเต็ม** — fail-safe อยู่ที่ allocator ตัด period + checkIn gate — "การจองเกิน pool จริงจบที่ assign ไม่ได้ เหมือนพฤติกรรมปัจจุบัน"

owner ประกาศ non-goal นี้ทีหลัง grill #5 → น่าจะ override แล้ว แต่เป็นการย้อน user-confirmed decision เดิม **ต้องยืนยันครั้งเดียวก่อนเขียนโค้ด:** (a) ยกเลิก bug fix — ticket นี้เหลือเพียง "flag ขยาย denominator เป็น physical + reserved period ใต้ `include_reserved` (admin)" หรือ (b) คง bug fix ตาม grill #5 เดิม — แล้วเขียน resolution ลง ticket นี้ + อัปเดต spec.md ทั้งสอง map ให้สอดคล้อง

- ใช้ `IncludeReservedGate::enabled($request)` เสมอ — reserved pool ตอนนี้คือห้องติด reserved period ช่วง `[check_in, check_out)` ของแต่ละ BR (overlap half-open เดียกับ booking_rooms) ไม่ใช่ property ถาวรของห้อง — query ต้องต่อช่วง BR ผ่าน scope ของ `RoomStatePeriod` ห้ามเขียนเงื่อนไขเอง
- maintenance absolute = ช่วงทับ maintenance period ไม่เคยนับ แม้ส่ง flag

## ✅ Resolution (2026-09-24 — session หนูเมด, wayfinder zcode)

**Grill ตัดสิน conflict (HITL กับ owner):** เลือก **(b) คง bug fix ตาม grill #5 เดิม** — denominator ทั้ง 4 จุด align เป็น **sellable pool ต่อช่วงเข้าพัก** (ตัดทั้ง maintenance และ reserved period), flag admin ขยายเป็น sellable + reserved · non-goal "คงนับห้องกายภาพ" ของ map `room-state-periods` **ถูกยกเลิก** และอัปเดต spec ทั้งสอง map ให้สอดคล้องแล้ว (เหตุผล: กัน user จองเกิน pool ที่ assign ได้จริงตั้งแต่หน้าจอง — ดีกว่าปล่อยจองผ่านแล้วไปสะดุดที่ assign · maintenance absolute ได้ครบทุก pool ด้วย)

**Implementation (`app/Http/Controllers/Api/V1/BookingController.php`):**
- private helper `sellableCapacity($typeId, $checkIn, $checkOut, $request)` — `Room::where(room_type_id)` + `freeOfPeriod(…, KIND_MAINTENANCE)` เสมอ + `when(! IncludeReservedGate::enabled($request), freeOfPeriod(…, KIND_RESERVED))` — ใช้ scope ของ room-state-periods ห้ามเขียนเงื่อนไขเอง
- 4 จุดเรียก helper: `createBooking`, `addRooms`, `updateRoom` (effective window ตอน shapeChanged), `updateRooms` — ทั้งหมดย้ายการนับ denominator **เข้าไปใน loop ต่อช่วงวันจริงของแต่ละ BR** (denominator ขึ้นกับช่วงแล้ว) · ใน `updateRooms` เลิก cache `$totalByType` (keyed แค่ type ใช้ไม่ได้อีก — แต่ละแถวมีช่วงของตัวเอง)
- `IncludeReservedGate` docblock อัปเดต: booking capacity checks 4 จุดใช้ gate แล้ว (เหลือ allocator — ticket 04)

**Tests:** `tests/Feature/BookingCapacitySellablePoolTest.php` 8 tests บน HTTP seam เดียว — regression non-admin 422 ทั้ง 4 paths (create/add/update/batch + sequential) · admin flag ขยาย create/add/update/batch · admin flag ไม่กิน maintenance เด็ดขาด · non-admin ส่ง flag เมยายีเงียบ ๆ · transaction rollback ไม่ทิ้ง booking ค้าง — **suite 520 เขียว (เดิม 512) + pint ผ่าน**
