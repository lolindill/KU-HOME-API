# 02: Guest cap validation 2 ชั้น — ห้ามเกิน max_guests + ≥1 คนตอน confirm (REQ-007/019)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** (ว่าง)
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

(ยังว่าง)
