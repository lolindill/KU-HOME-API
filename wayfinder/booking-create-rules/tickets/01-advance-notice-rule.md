---
label: wayfinder:task
type: task
title: "Advance-notice rule — จองล่วงหน้า ≥ 2 วัน (4 write paths, tracer bullet)"
status: closed
assignee: antigravity (2026-09-16)
blocked-by: []
---

# 01: Advance-notice rule — จองล่วงหน้า ≥ 2 วัน (tracer bullet)

**What to build:** พฤติกรรมปลายทางที่ user เจอ — non-admin สร้าง/แก้การจองด้วยวันเช็คอิน
เร็วกว่า +2 calendar days (Asia/Bangkok) → โดน 422 พร้อมข้อความไทย, +2 วันขึ้นไป → ผ่าน,
admin (ตัดสินจาก login role เท่านั้น ไม่ใช่ field `source`) จองวันเดียวกัน/พรุ่งนี้ได้
แต่ยังห้ามย้อนหลังตามกฎเดิม — ครบทั้ง 4 write paths (create / add-rooms / แก้รายห้อง /
แก้ batch) พร้อม booking config ใหม่ (ใส่คีย์ทั้ง 2 ตัว — อีกคีย์รอใบ 02 ใช้) + helper
กลาง + test + docs ส่วนกฎวัน

**Blocked by:** None (can start immediately)

**Status:** closed

- [x] non-admin จองเช็คอินวันนี้/+1 วัน → 422 ข้อความไทยระบุเงื่อนไขล่วงหน้า 2 วัน — ครบทั้ง create / add-rooms / แก้รายห้อง / แก้ batch
- [x] non-admin เช็คอิน +2 วัน → 201 (boundary ผ่าน)
- [x] admin วันนี้/+1 วัน → 201 และยังโดนกฎ not-in-past เดิม — exempt ตัดสินจาก sanctum role
- [x] booking config: `min_advance_days` (env `BOOKING_MIN_ADVANCE_DAYS`, default 2) + `max_rooms_per_booking` (env `BOOKING_MAX_ROOMS_PER_BOOKING`, default 4 — รอใบ 02) อ่าน env ได้ สไตล์เดียวกับ allocation config
- [x] helper กลางตัวเดียวใน support namespace เดิม: admin check + วันเช็คอินขั้นต่ำ + เพดานห้อง — ครอบผ่าน HTTP seam เท่านั้น (fewest-seams)
- [x] test ใหม่ชุด boundary ตาม spec §Testing Decisions (HTTP feature seam เดียว)
- [x] test เดิม ~17 จุดที่ใช้ check-in พรุ่งนี้ เลื่อนเป็น +2/+3 โดยคงเจตนาเดิมของแต่ละ test — draft-guard/ownership-403/unknown-404 ต้องยังโดน error ที่ตัวเองทดสอบ (validation ใหม่วิ่งก่อน controller)
- [x] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`
- [x] docs ส่วนกฎวัน: api_guide (validation ของ booking endpoints) + cline.md (decision จาก grill-me)

## Resolution

- Implemented config `config/booking.php` with `min_advance_days` (default 2) and `max_rooms_per_booking` (default 4).
- Implemented `App\Support\BookingRule` helper with `Asia/Bangkok` calendar calculation, Sanctum role check (`$user->role === 'admin'`), dynamic rules, and localized messages.
- Updated 4 Form Requests (`StoreBookingRequest`, `AddBookingRoomsRequest`, `UpdateBookingRoomRequest`, `UpdateBookingRoomsRequest`) with custom validation rules and messages.
- Updated existing tests in `tests/Feature/BookingTest.php` without altering test semantics (all 73 tests passing).
- Added comprehensive feature test suite `tests/Feature/BookingAdvanceNoticeTest.php` with 15 tests covering boundary conditions, write paths, admin exemptions, spoof guard, grandfathered drafts, and config override.
- Documented changes in `docs/api_guide.md` and `cline.md`.
- Verified entire test suite passes (`401 passed (1380 assertions)`) and `vendor/bin/pint --dirty` clean.
