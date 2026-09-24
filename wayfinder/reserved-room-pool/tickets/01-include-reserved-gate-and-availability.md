---
label: wayfinder:task
type: AFK
title: "Param gate helper + availability summary endpoint — tracer bullet"
status: open
assignee: zcode session (2026-09-24 — 🧊 FROZEN รอ map room-state-periods · งานลงแล้ว suite 446 เขียว แต่ไม่ปิด)
blocked-by: []
---

# 01: Param gate helper + availability summary endpoint (tracer bullet)

**What to build:** เส้นทางแนวตั้งเส้นแรกจนจบ (tracer bullet) สำหรับฟีเจอร์ `include_reserved`:
1. สร้าง **shared gate resolution helper** (เช่น `app/Support/IncludeReservedGate.php` หรือ trait) ตรวจสอบเงื่อนไขว่า flag มีผลหรือไม่:
   - ทำงานเฉพาะเมื่อผู้ใช้ล็อกอินมี role เป็น `admin` (`$request->user('sanctum')?->role === 'admin'`) และส่ง boolean param `include_reserved=true` (ผ่าน query string หรือ request body)
   - หากเป็น non-admin หรือ anonymous (ผู้ใช้ทั่วไปหรือไม่ได้ล็อกอิน) ส่ง flag มา → ให้ **เมินเฉยเงียบ ๆ (silently ignored)** ห้ามคืน 403
   - ออกแบบให้เป็น single source of truth เพื่อให้ tickets 02–05 นำไป reuse ได้ทันที ไม่เขียน logic ซ้ำซ้อน
2. ใช้งานบน **summary availability endpoint** (`GET /api/v1/availability` ใน `RoomController::availability`):
   - **Admin ส่ง flag:**
     - Pool count รวมห้อง `reserved_closed` (สูตร: `available_rooms` = sellable + reserved − booked แทนที่ค่าเดิม)
     - คืน field โปร่งใสเพิ่มเติม: `sellable_rooms` (จำนวนห้องขายปกติที่ไม่รวม reserved) และ `reserved_rooms` (จำนวนห้องสำรอง)
     - บันทึกใน `search_criteria` ว่า `'include_reserved' => true`
     - King counters (`king_total_rooms`) เดินตามกฎ extended pool เช่นเดียวกัน (รวมห้อง king ที่เป็น `reserved_closed` เมื่อส่งทั้ง `bed_type=king_size` และ `include_reserved=true`)
   - **Non-admin / Anonymous ส่ง flag:** เมินเฉยเงียบ ๆ คืน payload ปกติเหมือนไม่ส่ง flag
   - **ไม่ส่ง flag:** Response ต้อง **byte-identical** กับพฤติกรรมปัจจุบัน 100% (backward-compatible)
   - **กฎเหล็กสัมบูรณ์ (Maintenance is absolute):** ห้องสถานะ `maintenance` ต้อง**ไม่**ถูกนับเข้า pool ใด ๆ ทั้งสิ้น ไม่ว่าจะส่ง flag หรือไม่ก็ตาม

**Files / Surfaces touched:**
- `app/Support/IncludeReservedGate.php` (หรือตำแหน่ง support helper กลางที่เหมาะสม)
- `app/Http/Controllers/Api/V1/RoomController.php` (method `availability`)
- Tests: `tests/Feature/RoomAvailabilityIncludeReservedTest.php`

**Blocked by:** None (frontier — can start immediately)

**Status:** ready-for-agent

## 🧊 Progress (2026-09-24) — implementation landed, ticket FROZEN ไม่ปิด

งานลง branch `agust-11` ครบแล้ว (suite เขียว 446 tests / 1553 assertions + pint) แต่ **ticket ยัง open** — map ถูก freeze รอ map `room-state-periods` (req ใหม่ 2026-09-24: ห้องสำรอง/ซ่อมแซมกำหนดระยะเวลาแบบ booking) ซึ่งอาจเปลี่ยน `is_reserved` flag เป็น period records แล้วกระทบ query ที่ลงไปนี้

สิ่งที่ landed แล้ว (รวม model pivot จาก ticket 90 ที่โยกมาไว้ ticket นี้):
- Migration `2026_09_24_091500_add_is_reserved_to_rooms` — เพิ่ม `rooms.is_reserved` + แปลงข้อมูล `reserved_closed → (available, is_reserved=true)`
- `Room` model — fillable/cast PgBoolean + ถอดสถานะ `reserved_closed` ออกจาก `transitionStatusTo()` ทั้ง target/source
- สลับ filter ทุกจุด `reserved_closed → is_reserved`: `RoomType::scopeWithSellableRoomsAndRates`, `RoomAllocator::loadRoomPool`, `BookingPriority::hasX09Free`, availability summary pool + king counters, walk-in guard (reject `is_reserved` รอ ticket 05 เปิด flag), `UpdateRoomRequest` enum
- `app/Support/IncludeReservedGate.php` — shared gate (admin + boolean flag, silent-ignore, ไม่ validate กัน 422)
- `RoomController::availability` — admin flag → `available_rooms` = sellable + reserved − booked + `sellable_rooms`/`reserved_rooms` + `search_criteria.include_reserved`; ไม่ส่ง flag = payload เดิมทุกไบต์
- Room listings (`allRooms`/`getRoomById`/`roomStatus`) โชว์ `is_reserved` badge
- Tests: `RoomStateTest` ปรับตาม machine ใหม่ · king test comment · (test file `RoomAvailabilityIncludeReservedTest` ยังไม่ได้เขียน — ถูกตัดจังหวะด้วย freeze)

**สิ่งค้างเมื่อ unfreeze:** เขียน `tests/Feature/RoomAvailabilityIncludeReservedTest.php` ครบ contract ด้านล่าง + ตรวจกับผลของ map `room-state-periods` ว่า query set นี้ต้องปรับตามหรือไม่

- [ ] สร้าง shared gate resolution helper ตัวเดียวใน support namespace เช็ค sanctum role `admin` + boolean `include_reserved`
- [ ] Non-admin และ anonymous ส่ง `include_reserved=true` เข้า `GET /api/v1/availability` → คืน payload ปกติเหมือนไม่ส่ง flag ทุกไบต์ (ห้ามคืน 403)
- [ ] Admin ส่ง `include_reserved=true` → `available_rooms` ถูกแทนที่ด้วย extended pool + มีฟิลด์ `sellable_rooms` และ `reserved_rooms` + `search_criteria.include_reserved = true`
- [ ] King counters (`king_total_rooms`) ปรับตาม extended pool เมื่อส่งคู่กับ `bed_type=king_size`
- [ ] ไม่ส่ง flag → response ต้อง byte-identical กับระบบเดิม (regression test)
- [ ] ห้องสถานะ `maintenance` ไม่ถูกนับใน pool ทุกกรณี
- [ ] Single HTTP feature seam tests ครอบคลุม contract ทั้งหมด
- [ ] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`

**Spec Reference:**
- [spec.md § Solution](../spec.md#solution)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions) (Param contract, Surfaces touched #1 & #2, Response shape under the flag, Maintenance is absolute)
- [spec.md § Testing Decisions](../spec.md#testing-decisions)
