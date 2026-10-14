# 02: Guest cap validation 2 ชั้น — ห้ามเกิน max_guests + ≥1 คนตอน confirm (REQ-007/019)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** agent-hub:antigravity/gemini-3.8-flash (manager dispatch 2026-10-14)
- **summary:** บังคับจำนวน guests ไม่เกิน room_types.max_guests ทุก write path + ≥1 คนเมื่อ submit สลิป confirm
- **phase:** 1

## Question

ปัจจุบัน `guests` เป็น nullable JSON และ**ไม่มี validation จำนวนคน ≤ `room_types.max_guests` เลย** (max_guests ใช้แค่ตอนค้นหา — `RoomController` :181, :236) ขณะที่ SRS REQ-007/019 บังคับ "กรอกผู้เข้าพักตามจำนวน ไม่มากกว่าจำนวนสูงสุด" — จะบังคับโดยไม่ทับ flow draft ได้ยังไง?

**Decision ที่ล็อกแล้ว (owner 2026-10-14) — 2 ชั้น:**

1. **ชั้น cap — ทุกจุดที่ส่ง `guests` มา:** ถ้า array มาแล้ว count > `room_types.max_guests` ของห้องนั้น → 422 ทันที
2. **ชั้น ≥1 คน — ตอน submit สลิป confirm:** ทุก `booking_room` ของ booking ต้องมี guests ≥1 คน ไม่งั้น 422
3. **ห้าม**บังคับ guests required ตั้งแต่ `POST /bookings` (ทับ flow draft real-time — user เริ่มจองก่อนกรอกครบ)

**จุดแตะ:**

- FormRequests: `StoreBookingRoomRequest`, `AddBookingRoomsRequest`, `UpdateBookingRoomRequest` (+ batch `updateRooms` ต้องตรวจเทียบ final state ของทั้ง batch ตามธรรมเนียมเดิม) — validation แบบมี context (ต้องรู้ max_guests ของ room_type → อาจตรวจใน controller คู่ FormRequest ตาม precedent availability re-check)
- `BookingConfirmationController::confirm` — ชั้นที่ 2
- ตัวเลข error message ภาษาไทย + emoji ตามธรรมเนียม (เช่น "ผู้เข้าพักเกินจำนวนสูงสุด N คนของห้องประเภทนี้ค่ะ 🥺")
- Tests: เกิน cap → 422 ทั้ง create/update/batch · 0 คนตอน confirm → 422 · ไม่ส่ง guests ตอน create ยังผ่าน · REQ-019 ชื่อซ้ำยังผ่าน
- `php artisan test` เขียวทั้ง suite + อัปเดต `docs/api_guide.md`

## Resolution

- **สิ่งที่ทำ:**
  1. **ชั้นที่ 1 (Guest Cap Validation):**
     - ใน `BookingController::createBooking` (`POST /bookings`) และ `BookingController::addRooms` (`POST /bookings/{id}/rooms`): ตรวจสอบจำนวน `guests` ของแต่ละห้อง หากเกิน `roomType->max_guests` จะ reject ด้วย HTTP 422 และ error message `"ผู้เข้าพักเกินจำนวนสูงสุด {$roomType->max_guests} คนของห้องประเภทนี้ค่ะ 🥺"`
     - ใน `BookingController::updateRoom` (`PUT /bookings/{id}/rooms/{roomId}`): ตรวจสอบ `effectiveGuests` เทียบกับ `effectiveTypeId->max_guests` ป้องกันทั้งกรณีส่ง guests เกิน และกรณีเปลี่ยนห้องไปประเภทที่ max_guests ต่ำกว่าเดิม
     - ใน `BookingController::updateRooms` (`PUT /bookings/{id}/rooms` batch): ตรวจสอบ final state ของทุกห้องใน batch เทียบกับ `effectiveTypeId->max_guests`
     - ใน `StoreBookingRoomRequest`: เพิ่ม `withValidator()` hook ตรวจสอบจำนวน `guests` เทียบกับ `room_type_id`
     - รักษากฎ draft flow โดยไม่บังคับ `guests` ตั้งแต่ตอนสร้าง draft
     - อนุญาตชื่อผู้เข้าพักซ้ำกันได้ตาม REQ-019
  2. **ชั้นที่ 2 (Confirmation Check):**
     - ใน `BookingConfirmationController::confirm` (`POST /bookings/{id}/confirm`): ตรวจสอบทุกห้องใน booking หากมีห้องใดที่ `guests` ไม่มีหรือ empty จะส่ง HTTP 422 พร้อมข้อความ `"กรุณาระบุข้อมูลผู้เข้าพักอย่างน้อย 1 คนต่อห้องก่อนส่งหลักฐานการชำระเงินค่ะ 🥺"`
  3. **Documentation:**
     - เขียนเอกสารส่งมอบสำหรับอัปเดต `docs/api_guide.md` ไว้ที่ `wayfinder/srs-v2-gap-closure/research/api-doc-handoff-02.md`
  4. **Tests:**
     - สร้าง `tests/Feature/GuestCapValidationTest.php` ครอบคลุม 13 scenarios (ทั้ง Layer 1, Layer 2, Draft flow, Duplicate names)
     - ปรับ default test payload ใน `BookingPaymentTypeTest` และ `BookingSurchargeAfterPaymentTest` ให้มี guest อย่างน้อย 1 คนสำหรับขั้นตอนการส่งสลิป confirm
- **ไฟล์ที่แตะ:**
  - `app/Http/Controllers/Api/V1/BookingController.php`
  - `app/Http/Controllers/Api/V1/BookingConfirmationController.php`
  - `app/Http/Requests/StoreBookingRoomRequest.php`
  - `tests/Feature/GuestCapValidationTest.php`
  - `tests/Feature/BookingPaymentTypeTest.php`
  - `tests/Feature/BookingSurchargeAfterPaymentTest.php`
  - `wayfinder/srs-v2-gap-closure/research/api-doc-handoff-02.md`
  - `wayfinder/srs-v2-gap-closure/tickets/02-guest-cap-validation.md`
- **ผลการทดสอบ:**
  - `tests/Feature/GuestCapValidationTest.php`: 13 passed (28 assertions)
  - Full Test Suite (`php artisan test`): 662 passed (2633 assertions) 100% เขียวทั้ง suite
