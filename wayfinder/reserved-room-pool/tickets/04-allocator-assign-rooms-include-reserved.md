---
label: wayfinder:task
type: AFK
title: "Allocator + assign-rooms รับ include_reserved — full-chain E2E"
status: closed
assignee: zcode (session 2026-09-24)
blocked-by: ["03-booking-capacity-sellable-pool"]
---

# 04: Allocator + assign-rooms รับ `include_reserved` — full-chain E2E

**What to build:** เชื่อมโยงระบบจัดสรรห้องจริงเข้ากับห้องสำรอง:
1. **Room Allocator Service:**
   - ปรับ `App\Services\RoomAllocator\RoomAllocator`:
     - Method `allocate(Collection $bookingRooms, bool $includeReserved = false): AllocationResult`
     - ใน `loadRoomPool(array $brs, bool $includeReserved = false)`: เมื่อ `$includeReserved === true` ให้โหลดห้องโดยตัดเฉพาะ `maintenance` (ยอมรับห้องสถานะ `reserved_closed` เข้ามาร่วมคำนวณ cluster)
     - Default `$includeReserved = false` รักษาพฤติกรรมเดิม (ตัดทั้ง `maintenance` และ `reserved_closed`) 100%
2. **Assign Rooms Endpoint:**
   - ปรับ `BookingController::autoAssignRooms` (`PUT /api/v1/bookings/{bookingId}/assign-rooms`):
     - รับ boolean param `include_reserved` จาก request
     - ส่งต่อ flag ไปยัง `RoomAllocator::allocate($unassignedRooms, $includeReserved)`
3. **No Auto-Flip State (Grill #6):**
   - ห้องที่ถูกจัดสรรให้ booking ยังคงมีสถานะทางกายภาพเป็น `reserved_closed` **ห้าม auto-flip** สถานะห้องเป็น available/occupied อัตโนมัติ (การจัดการ lifecycle ตอน check-in รอตัดสินใจใน ticket 90)
4. **No Persistence Fail-Safe (Grill #2):**
   - หาก admin สร้าง booking ด้วย flag แต่ตอนเรียก assign-rooms ไม่ได้ส่ง flag และห้องปกติหมด เหลือแต่ห้องสำรอง → Allocator ต้องรายงานผล fail อย่างสุภาพและปลอดภัย (ไม่ crash, ไม่ assign เกิน, และไม่ดึงห้องสำรองมาโดยไม่ได้รับอนุญาต)
5. **Full-Chain End-to-End (E2E):**
   - ร้อยเรียงเส้นทางเต็ม: Admin จองห้องด้วย flag ผ่าน capacity check (จากตั๋ว 03) → Admin สั่ง assign-rooms ด้วย flag → Booking ได้รับ `room_id` เป็นห้อง `reserved_closed` และสถานะห้องในตาราง `rooms` ยังคงเป็น `reserved_closed`

**Files / Surfaces touched:**
- `app/Services/RoomAllocator/RoomAllocator.php`
- `app/Http/Controllers/Api/V1/BookingController.php` (method `autoAssignRooms`)
- Tests: `tests/Feature/AssignRoomsIncludeReservedTest.php`

**Blocked by:** 03-booking-capacity-sellable-pool

**Status:** ready-for-agent

- [x] `RoomAllocator::allocate` รับ `$includeReserved` parameter (default `false`)
- [x] เมื่อ `$includeReserved = true`: pool โหลดห้อง **ติด reserved period** มาร่วม cluster allocation เมื่อห้อง sellable ไม่พอ *(amendment — โมเดล period)*
- [x] ห้องติด `maintenance` period ไม่ถูกนำเข้า pool จัดห้องเด็ดขาดในทุกกรณี
- [x] Endpoint `PUT /bookings/{bookingId}/assign-rooms` รับ param `include_reserved` และส่งต่อให้ allocator
- [x] การ assign เขียนแค่ `booking_rooms.room_id` — สถานะกายภาพห้อง + period row ไม่ถูกแตะ *(amendment — "ไม่มี auto-flip" เดิมหมดความหมายเมื่อสถานะ `reserved_closed` ถูกถอด)*
- [x] หาก sellable หมดและ assign-rooms ไม่ส่ง flag → ตอบ fail อย่างสุภาพ ไม่เกิด assignment ผิดพลาด
- [x] Default (ไม่ส่ง flag) พฤติกรรม allocator เดิมไม่เปลี่ยน (backward-compatible)
- [x] Full-chain E2E test: create-booking with flag → assign-rooms with flag → assert room_id + assert room status
- [x] Single HTTP feature seam tests (ทดสอบผ่าน assign-rooms แบบ indirect ตามข้อตกลง seam decision)
- [x] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`

**Spec Reference:**
- [spec.md § Solution](../spec.md#solution)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions) (Surfaces touched #4, No persistence, No state-machine change)
- [spec.md § Testing Decisions](../spec.md#testing-decisions)

## 🗓️ Amendment (2026-09-24 — unfreeze, เขียนใหม่บน period-check scopes ตามคำจดของ map `room-state-periods`)

โมเดลพื้นฐานเปลี่ยน — "ห้องสำรอง" = ห้องติด **reserved period** (`room_state_periods` kind `reserved`) ไม่ใช่สถานะ `reserved_closed` / column `is_reserved` (ถูก drop แล้ว — map `room-state-periods`) · spec ใหม่จดไว้ชัด: *"ticket 04 ของ map นั้น (allocator flag threading) ยังไม่เกิด — เมื่อ unfreeze ต้องเขียนใหม่บน period-check scopes ของ spec นี้"*:

- สถานะปัจจุบัน: `RoomAllocator::loadRoomPool()` ใช้ `freeOfPeriod($minCheckIn, $maxCheckOut)` **ตัดทุก kind อยู่แล้ว** — งาน = เพิ่ม `bool $includeReserved` เข้า `allocate()`/`loadRoomPool()` เมื่อ flag เป็น true → ตัดเฉพาะ `freeOfPeriod(..., KIND_MAINTENANCE)` (reserved period ห้องเข้า pool ได้) · `BookingPriority::hasX09Free()` กฎเดียวกัน ต่อช่วง BR
- flag รับที่ endpoint `PUT /bookings/{bookingId}/assign-rooms` → ผ่าน `IncludeReservedGate::enabled($request)` (admin + flag เท่านั้น — silent-ignore)
- **ข้อ "No Auto-Flip" เดิมหมดความหมาย:** สถานะ `reserved_closed` ถูกถอด — ห้องสำรองวิ่ง lifecycle ปกติ (`available → occupied`) ตาม decision ticket 90 ที่ถูก override ด้วย period model; ข้อ "No Persistence" ยังคงเดิม (param ไม่ persist)
- E2E: admin create-booking ให้ช่วงที่ห้องปกติเต็มแต่มี reserved period → assign-rooms พร้อม flag → ได้ `room_id` เป็นห้องติด reserved period · ไม่ส่ง flag → fail สุภาพ
- ⚠️ ถ้า ticket 03 (capacity) ยังไม่ยอมรับ flag ฝั่ง create — E2E สร้าง booking แบบ admin override ผ่าน `source=admin` + status ตรง ๆ ตาม prior art `BookingAutoAssignRoomsTest` ได้ (cap เข้งวง scope แค่ allocator) — ระบุแนวทางให้ชัดตอนทำ

## ✅ Resolution (2026-09-24 — session หนูเมด, wayfinder zcode)

**Implementation บน period model (ตาม amendment — ไม่ต้อง override ผ่าน `source=admin` เพราะ ticket 03 รับ flag ฝั่ง create แล้ว):**

- `app/Services/RoomAllocator/RoomAllocator.php` — `allocate(Collection $bookingRooms, bool $includeReserved = false)` → thread เข้า `loadRoomPool($brs, $includeReserved)`: flag true → `freeOfPeriod($min, $max, RoomStatePeriod::KIND_MAINTENANCE)` (reserved period เข้า pool ได้), default → `freeOfPeriod($min, $max)` ตัดทุก kind เหมือนเดิม 100% · `X09Seeder::find()` ทำงานบน pool ที่โหลดแล้ว — inherit ผลตัดสินโดยไม่ต้องแก้
- `app/Services/RoomAllocator/BookingPriority.php` — `traits(..., bool $includeReserved = false)` → `hasX09Free(..., $includeReserved)` กฎเดียวกันต่อช่วง BR (default false — caller เดียวคือ `DailyRoomMaintenance` console ซึ่งไม่มี flag context จึงคงพฤติกรรมเดิม)
- `app/Http/Controllers/Api/V1/BookingController.php` `autoAssignRooms` — `$includeReserved = IncludeReservedGate::enabled($request)` (admin + flag เท่านั้น silent-ignore) ส่งต่อเป็น arg ที่ 2 ของ `allocate()` · ไม่ persist ค่า flag · fail path เดิม (422 สุภาพ + rollback) ทำงานเองตาม `! $result->ok`
- `app/Support/IncludeReservedGate.php` — docblock อัปเดต: assign-rooms → allocator ใช้ gate แล้ว (ครบ 5 surfaces)

**No Auto-Flip → obsolete ตาม amendment:** สถานะ `reserved_closed` ถูกถอด (period model) — การ assign เขียนแค่ `booking_rooms.room_id`, สถานะกายภาพห้อง + period row ไม่ถูกแตะ (test ยืนยันใน E2E) · **No Persistence (grill #2) คงเดิม:** create ด้วย flag แต่ assign ไม่ส่ง flag → allocator fail สุภาพ ไม่ดึงห้องสำรองเอง

**Tests:** `tests/Feature/AssignRoomsIncludeReservedTest.php` 6 tests บน HTTP seam เดียว — full-chain E2E (create ด้วย flag → `PUT update` draft→paid → assign ด้วย flag → ได้ห้อง reserved period, period row คงอยู่, สถานะห้อง `available` ไม่ถูกแตะ) · fail-safe ไม่ส่ง flag ตอนเหลือแต่ห้องสำรอง (422 สุภาพ + ไม่มี assignment ค้าง) · flag ไม่มีทางได้ maintenance (มี reserved คู่/มีแต่ maintenance) · default pool ยังตัด reserved · non-admin + flag → 403 โดย `role:admin` middleware — **suite 526 เขียว (เดิม 520) + pint ผ่าน**

**หมายเหตุ:** เคส "มีแต่ maintenance room" สร้าง booking ทาง HTTP ไม่ได้ (capacity check ของ ticket 03 บล็อกตั้งแต่ create — ถูกต้องตาม design) จึง seed ด้วย model ตาม prior art `BookingAutoAssignRoomsTest` แล้วยิง assign-rooms ผ่าน HTTP จริง
