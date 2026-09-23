---
label: wayfinder:task
type: AFK
title: "Calendar availability (per-day + ranges) รับ include_reserved"
status: open
assignee:
blocked-by: ["01-include-reserved-gate-and-availability"]
---

# 02: Calendar availability (per-day + ranges) รับ `include_reserved`

**What to build:** ขยายปฏิทิน availability endpoints ทั้งหมดให้รองรับ `include_reserved` ตามกติกาเดียวกับ summary endpoint ในตั๋ว 01:
1. **Per-day calendar endpoint:** `GET /api/v1/availability-per-day` (`RoomController::availabilityPerDay`)
   - **Admin ส่ง flag:** ตัวเลขห้องว่างรายวัน (`$row[$dateKey]`) คำนวณจาก pool ที่รวมห้อง `reserved_closed` (sellable + reserved − booked)
   - ปรับ `RoomType::scopeWithSellableRoomsAndRates` หรือ query ให้รับ parameter `$includeReserved` เพื่อรวม `reserved_closed` เข้า `total_rooms_count`
2. **Availability ranges endpoint:** `GET /api/v1/availability-ranges` (`RoomController::availabilityRanges`)
   - **Admin ส่ง flag:** ช่วงวันที่ห้องเต็ม (sold-out intervals ที่ available = 0) คำนวณจาก pool ที่รวมห้องสำรอง ทำให้ช่วงวันที่ sold-out หดลงหรือเปิดว่างขึ้นเมื่อมีห้องสำรองค้ำอยู่
3. **Flat lists & automatic scan endpoints (ถ้ามี):** `GET /api/v1/unavailable-dates` และ `GET /api/v1/unavailable-ranges` เดินตาม semantics เดียวกันอย่างสม่ำเสมอ
4. **Non-admin / Anonymous ส่ง flag:** เมินเฉยเงียบ ๆ (silently ignored) คืน payload เหมือนไม่ส่ง flag ทุกไบต์
5. **ไม่ส่ง flag:** Response ต้อง **byte-identical** กับระบบเดิม
6. **กฎเหล็กสัมบูรณ์ (Maintenance is absolute):** ห้อง `maintenance` ตัดทิ้งเสมอทุกกรณี

ใช้ shared gate helper จากตั๋ว 01 ห้ามเขียน logic ตรวจ admin หรือ parse flag ซ้ำซ้อน

**Files / Surfaces touched:**
- `app/Http/Controllers/Api/V1/RoomController.php` (`availabilityPerDay`, `availabilityRanges`, `unavailableDates`, `unavailableRanges`)
- `app/Models/RoomType.php` (`scopeWithSellableRoomsAndRates`)
- Tests: `tests/Feature/RoomCalendarIncludeReservedTest.php`

**Blocked by:** 01-include-reserved-gate-and-availability

**Status:** ready-for-agent

- [ ] Admin flag บน `GET /api/v1/availability-per-day` → ตัวเลขห้องว่างรายวันคำนวณจาก pool รวม reserved
- [ ] Admin flag บน `GET /api/v1/availability-ranges` → sold-out ranges สะท้อน pool รวม reserved
- [ ] ปรับ `RoomType::scopeWithSellableRoomsAndRates` ให้รองรับ flag โดยไม่กระทบ callers เดิม
- [ ] Non-admin และ anonymous ส่ง flag บน calendar endpoints → คืน response เหมือนไม่ส่ง flag ทุกไบต์
- [ ] ไม่ส่ง flag → response byte-identical กับระบบเดิม (regression test)
- [ ] ห้องสถานะ `maintenance` ไม่ถูกนับใน pool ทุกกรณี
- [ ] Single HTTP feature seam tests ครอบคลุมทั้งสอง endpoints
- [ ] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`

**Spec Reference:**
- [spec.md § Solution](../spec.md#solution)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions) (Surfaces touched #1, Maintenance is absolute)
- [spec.md § Testing Decisions](../spec.md#testing-decisions)
