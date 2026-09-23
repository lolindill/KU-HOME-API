---
label: wayfinder:task
type: task
title: "Room cap — ลิมิต 4 ห้องต่อ 1 booking (non-admin)"
status: closed
assignee: antigravity (2026-09-16)
blocked-by: ["01-advance-notice-rule"]
---

# 02: Room cap — ลิมิต 4 ห้องต่อ 1 booking (non-admin)

**What to build:** พฤติกรรมปลายทางที่ user เจอ — non-admin สร้าง booking เกิน 4 ห้อง →
422 ข้อความไทยแนะนำให้ติดต่อผู้ดูแล, ครบ 4 ห้อง → ผ่าน, และการเพิ่มห้องทีหลังนับห้องเดิม
ใน booking รวมกับห้องใหม่ (3+2 → 422, 3+1 → ผ่าน) — admin ยัดกี่ห้องก็ผ่าน · batch edit
ไม่เพิ่มจำนวนห้องจึงไม่ถูกตรวจ · ใช้ config + helper (admin check / เพดานห้อง) จากใบ 01

**Blocked by:** 01-advance-notice-rule (config + helper + ไฟล์ form-requests ชุดเดียวกัน)

**Status:** closed

- [x] non-admin create 5 ห้อง → 422 / 4 ห้อง → 201 (form-request layer)
- [x] non-admin add-rooms: ห้องเดิม 3 + ใหม่ 2 → 422 / 3 + 1 → 201 (controller guard นับห้องเดิมจริงจาก booking)
- [x] admin ทุกกรณี (create 6 ห้อง, add-rooms เกินเพดาน) → ผ่านหมด
- [x] guard ของ add-rooms วางก่อน beginTransaction ตาม pattern early guards (401/422) ของ method เดิม
- [x] batch edit คงพฤติกรรมเดิม (ไม่เช็ค cap — ไม่มีการเพิ่มห้อง)
- [x] test ใหม่: ชุดกรณี cap ตาม spec §Testing Decisions (HTTP seam เดียว)
- [x] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`
- [x] docs ส่วน cap: api_guide + cline.md (ต่อท้ายหัวข้อเดียวกับใบ 01)

## Resolution

- Implemented `roomCapRule` and `roomCapMessage` methods in `App\Support\BookingRule` helper reusing `maxRoomsPerBooking` and `config('booking.max_rooms_per_booking', 4)`.
- Enforced room cap at FormRequest layer in `StoreBookingRequest` (`booking_rooms` array rule `max:4` for non-admin, bypassed for admin role).
- Enforced room cap at Controller guard before `DB::beginTransaction()` in `BookingController@addRooms`, counting actual existing booking rooms (`$booking->bookingRooms()->count()`) plus requested new rooms (`count($validated['booking_rooms'])`), throwing 422 Exception with Thai message for non-admin.
- Batch edit (`PUT /bookings/{id}/rooms`) and single-room update do not add rooms, leaving existing behavior unchanged.
- Ensured admin exemption across both create and add-rooms paths based strictly on Sanctum login role (`$user->role === 'admin'`), preventing spoofing via request body `source` field.
- Added comprehensive feature test suite `tests/Feature/BookingRoomCapTest.php` with 10 tests covering create cap (4 vs 5), add-rooms cumulative count (3+2 vs 3+1), admin exemptions, spoof guard, batch edit integrity, and config override.
- Documented changes in `docs/api_guide.md` and `cline.md` (appended to existing booking create rules sections).
- Verified entire test suite passes (`411 passed (1413 assertions)`) and `vendor/bin/pint --dirty` clean (0 issues).
