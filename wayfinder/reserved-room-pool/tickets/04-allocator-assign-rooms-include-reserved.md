---
label: wayfinder:task
type: AFK
title: "Allocator + assign-rooms รับ include_reserved — full-chain E2E"
status: open
assignee:
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

- [ ] `RoomAllocator::allocate` รับ `$includeReserved` parameter (default `false`)
- [ ] เมื่อ `$includeReserved = true`: pool โหลดห้อง `reserved_closed` มาร่วม cluster allocation เมื่อห้อง sellable ไม่พอ
- [ ] ห้องสถานะ `maintenance` ไม่ถูกนำเข้า pool จัดห้องเด็ดขาดในทุกกรณี
- [ ] Endpoint `PUT /bookings/{bookingId}/assign-rooms` รับ param `include_reserved` และส่งต่อให้ allocator
- [ ] ห้องสำรองที่ถูก assign ยังคงมีสถานะ `reserved_closed` ในตาราง `rooms` (ไม่มี auto-flip)
- [ ] หาก sellable หมดและ assign-rooms ไม่ส่ง flag → ตอบ fail อย่างสุภาพ ไม่เกิด assignment ผิดพลาด
- [ ] Default (ไม่ส่ง flag) พฤติกรรม allocator เดิมไม่เปลี่ยน (backward-compatible)
- [ ] Full-chain E2E test: create-booking with flag → assign-rooms with flag → assert room_id + assert room status
- [ ] Single HTTP feature seam tests (ทดสอบผ่าน assign-rooms แบบ indirect ตามข้อตกลง seam decision)
- [ ] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`

**Spec Reference:**
- [spec.md § Solution](../spec.md#solution)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions) (Surfaces touched #4, No persistence, No state-machine change)
- [spec.md § Testing Decisions](../spec.md#testing-decisions)
