---
label: wayfinder:task
type: AFK
title: "Calendar availability (per-day + ranges) รับ include_reserved"
status: closed
assignee: zcode-session (2026-09-24)
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

## 🗓️ Amendment (2026-09-24 — unfreeze, re-base ตาม period model)

โมเดลพื้นฐานเปลี่ยน — "ห้องสำรอง" = ห้องติด **reserved period** (`room_state_periods` kind `reserved`) ไม่ใช่สถานะ `reserved_closed` / column `is_reserved` (ถูก drop แล้ว — map `room-state-periods`):

- Calendar 4 endpoints ปัจจุบัน (จาก build ของ map ใหม่ touchpoint 10) **ยัด period ทุก kind เข้า occupied matrix แล้ว** — งานที่เหลือจึงไม่ใช่ "รวม reserved เข้า pool" ใหม่จากศูนย์ แต่คือให้ matrix **นับเฉพาะ maintenance period เมื่อ admin ส่ง flag** (reserved period ไม่ไปบล็อกวัน) + บันทึก `search_criteria.include_reserved`
- ใช้ `IncludeReservedGate::enabled($request)` เสมอ (ห้ามเขียน admin-check เอง)
- `RoomType::scopeWithSellableRoomsAndRates` ไม่ต้องรับ `$includeReserved` แล้ว — denominator ตอนนี้ = ทุกห้องของ type (จาก map ใหม่ ข้อ 8) การตัดเกิดที่ matrix
- ห้อง "ติด reserved period" นับ per-room ต่อช่วง (overlap half-open เดียกับ booking) — ห้องเดียวอาจติดเฉพาะบางวันของช่วงสแกน

## ✅ Resolution (2026-09-24)

**ทำครบตาม amendment — landed บน period model ทั้งหมด:**

- **จุดตัดเดียว:** `RoomController::addPeriodsToOccupied()` รับ `bool $includeReserved = false` — เมื่อ flag → `RoomStatePeriod::overlapping(..., KIND_MAINTENANCE)` (matrix นับเฉพาะ maintenance period · reserved period ไม่บล็อกวัน ห้องสำรองกลับเข้า pool รายวัน) · ไม่ส่ง flag → `kind = null` ทุก kind บล็อกเหมือนเดิม (byte-identical) · maintenance absolute คงอยู่ทั้งสองโหมด (กฎเหล็ก)
- **4 endpoints ผ่าน gate กลาง:** `IncludeReservedGate::enabled($request)` ทั้ง `availabilityPerDay` / `availabilityRanges` / `unavailableDates` / `unavailableRanges` — ไม่มีการเขียน admin-check เอง · `unavailableRanges` ใส่ flag ทั้ง branch หลักและ early-return (ไม่มี booking/period เลย) ให้สม่ำเสมอ
- **`search_criteria.include_reserved` = top-level บน response** (โผล่เฉพาะเมื่อ flag มีผล) — calendar endpoints ไม่มี per-row `search_criteria` เหมือน summary เลยวางกลาง คู่กับ `start_date`/`end_date`
- **`RoomType::scopeWithSellableRoomsAndRates` ไม่แตะ** ตาม amendment — denominator = ทุกห้องของ type อยู่แล้ว การตัดเกิดที่ matrix
- **ห้องติด period นับ per-day จริง:** period [D5, D8) บล็อกเฉพาะ D5–D7 — วันนอกช่วงห้องกลับมาทั้ง flag และไม่ flag (test ยืนยัน)

**Tests:** `tests/Feature/RoomCalendarIncludeReservedTest.php` 11 tests — admin flag ขยาย per-day + interval หายบน ranges + unavailable-dates/ranges · maintenance คงบล็อกทุก endpoint · flag ผสม booking (BR ยังถูกนับ, reserved ปล่อยวัน) · บันทึก search_criteria · user/anonymous silent-ignore **byte-identical ทั้ง 4 endpoints** · regression ไม่ส่ง flag — **suite เต็ม 512 passed (1840 assertions) + pint เขียว**

**เหลือใน map:** ticket 03 (booking capacity — grill กับ owner ก่อน), 04 (allocator), 06 (docs closeout)
