---
label: wayfinder:map
title: "scrutinize whole project 2026-11-03 — fix backlog + 3 owner decisions"
status: resolved
---

# Wayfinder Map — Scrutinize whole project (handoff 2026-11-03)

> **Execution Completed** — งานแก้ทั้งหมดตามผล scrutinize ได้รับการ implement และทดสอบผ่านครบ 100% แล้ว (807 passed / 3,243 assertions) เมื่อ 2026-11-03 โดยสุดยอดเมดประจำตัวนายท่าน ✨💅💖

## สถานะหลังแก้ไขเสร็จสมบูรณ์
- **Test suite:** ✅ **807 passed (3,243 assertions)** จาก baseline 781 tests (+26 new passing tests)
- **Blockers (B1):** ✅ Resolved
- **Majors (M1–M9):** ✅ Resolved
- **Decisions (D1–D3):** ✅ Resolved (D1 role-filtered discount show, D2 staff pending→paid, D3 draft charge 422 + delete guards)
- **Minors (m1–m12):** ✅ Resolved ครบทุกข้อ

---

## 🚫 Blocker

### B1 — X09Seeder pin ลบล้าง hard constraint `bed_preference` (บั๊กรูปเดิมกลับมาผ่าน pin path)
- **ที่อยู่:** `app/Services/RoomAllocator/X09Seeder.php:55-63` + `app/Services/RoomAllocator/RoomAllocator.php:72-80`
- **Evidence:** ตัวกรอง candidate ใน pin มีแค่ `isX09()` + `hasOverlap()` — **ไม่เรียก `matchesBedPreference()`** แล้ว `allocate()` วางผล pin ก่อนรัน 4 algorithms (ที่กรองถูกแล้วทุก branch) → booking "Deluxe + extra_beds + king_size" โดนตอกไป X09 ชั้นล่างสุด (twin) ทันทีแม้ 809 (king) ว่าง · test เดิม (`BookingAutoAssignRoomsTest.php:434`) ไม่มี extra beds จึงไม่เข้า pin → หลุดตา
- **Fix:** loop candidates เพิ่ม `if (! $brs[$idx]->matchesBedPreference($room)) continue;` (1 บรรทัด) + เพิ่ม test ที่มี `extra_beds > 0` เพื่อเข้า pin path

## 🔴 Major

### M1 — checkOut gate ลืมรวม `charges_total`
- `app/Http/Controllers/Api/V1/FrontDeskController.php:332` — `$totalPaid < $booking->total_amount` ค่าห้องล้วน → แขกมีค่าเสียหายค้างเช็คเอาท์หลุด (ขัดธรรมเนียม "💸 ทุกจุดเปรียบเทียบ is_paid รวม charges" — recordPayment บรรทัด 540 ทำถูก)
- **Fix:** `+ $booking->charges_total` (1 บรรทัด) + test

### M2 — checkIn รับ container `pending`/`verify_error` → บิล bricked
- `FrontDeskController.php:257-264` — guard บล็อกแค่ `draft`; สลิปยังไม่ verify เช็คอินได้ → ห้อง occupied ก่อนเงินผ่าน และ checkOut ทีหลัง `syncStatusFromRooms()` พยายาม `pending → complete` ซึ่งไม่มีใน machine (`Booking.php:259-283`) → throw 422 ตลอดชีวิตบิล
- **Fix:** reject ทุกสถานะที่ไม่ใช่ `paid`/`confirmed` — คัดลอก guard จาก `autoAssignRooms` (`BookingController.php:2111-2116` ทำถูกแล้ว)

### M3 — verify()/reject() ไม่มี row lock — double-verify race
- `BookingConfirmationController.php:171-231` — โหลด `findOrFail` แล้วกัน duplicate ด้วย in-memory state ล้วน (ไม่มี `lockForUpdate`/transaction — ยืนยันเองแล้ว) → admin 2 คน verify พร้อมกันบน PG = `Payment::create` ซ้ำ 2 แถว ledger · เสริม: `confirm()` มี "1 pending max" แบบ check-then-act (:74-82) race ได้ 2 pending ต่อ booking
- **Fix:** tx + re-fetch `lockForUpdate()` + re-check `pending` ตาม pattern `destroyBooking` (`BookingController.php:552` มีอยู่แล้ว) · ปิด confirm race ด้วย partial unique index หรือ guarded WHERE update

### M4 — walkIn ไม่มี holdingSlot overlap guard
- `FrontDeskController.php` walkIn (:52-117) — ตรวจแค่ room status + maintenance/reserved period, **ไม่ query `BookingRoom::holdingSlot()` overlap** (checkIn มี guard นี้แล้ว :192-207) → ห้องที่ BR ยึด slot แต่ room status ยัง `available` ถูก walk-in ทับ (ตระกูลบั๊กห้อง 508 prod)
- **Fix:** คัดลอก overlap query จาก checkIn:194-207

### M5 — `POST /bookings` + `POST /bookings/{id}/rooms` ไม่มี throttle
- `routes/api.php:113-120` — คอมเมนต์ยอมรับ "🚧 Throttle limit paused for testing/dev" ขัดเอกสาร 5,1 · คู่กับดีไซน์ draft กิน slot ทันที = slot-exhaustion DoS ได้
- **Fix:** คืน `->middleware('throttle:5,1')` 2 route

### M6 — task done บนห้อง `occupied` = 422 ตลอดชีวิต + rollback กิน checklist
- `DashboardController.php:295-298` — ทุก `done` พ่น `transitionStatusTo('available')` โดยไม่ดู room status (occupied→available ห้ามใน machine `Room.php:117`) → daily task บนห้องที่แขกอยู่ปิดไม่ได้เลย (createTask :93-100 ไม่กรอง room status)
- **Fix:** transition เฉพาะเมื่อ room อยู่ใน `['checkout_makeup','dirty','prep_checkin']` ไม่งั้นข้าม (done โดยไม่แตะห้อง)

### M7 — IDOR แม่บ้าน
- `DashboardController.php` — `updateStatus` (:254-300) / `acceptTask` (:206-248) ไม่เช็ค `assigned_to` (photo endpoints เช็คครบ :360, :484) → แม่บ้าน B ปิดงานของ A + ปล่อยห้องของ A เป็น available
- **Fix:** เพิ่ม guard เดียวกับ uploadPhoto ใน updateStatus (accept คนอื่น revert false อยู่แล้วไม่อันตราย)

### M8 — `GET /availability` นับ pool ตัวเองขัดตัวเอง
- `RoomController.php:187-192` — `rooms_count` กรอง `status='available'` (lifecycle วันนี้) ขณะที่ king counters ใน endpoint เดียวกัน (:214-231) + calendar 4 ตัว + `createBooking` (sellableCapacity) นับห้องกายภาพทั้งหมดตัดเฉพาะ period + holdingSlot → ห้อง occupied ที่จะออกก่อนวันค้นหายจากผล ตัวเลข public ต่ำกว่าจริง
- **Fix:** ดึง `->where('status','available')` ออกจาก rooms_count (ให้เหลือ period + booked overlap)

### M9 — MockController route สาธารณะแบบไม่มีเงื่อนไข environment
- `routes/api.php:69-70` — คอมเมนต์ตัวเองบอก "อย่าปล่อยขึ้น prod" แต่ไม่มีอะไรกัน deploy
- **Fix:** ครอบ `app()->environment('local')` หรือลบตาม deletion plan ที่จดไว้

## 🟡 Minor

| # | ประเด็น | ที่อยู่ |
|---|---|---|
| m1 | `CleanupExpiredDrafts` ลบ draft **ไม่เขียน audit log** (2 พาธพี่เลี้ยงเขียน `draft → deleted` ครบ: `BookingController.php:570-578`, `RoomStatePeriodService.php:322-330`) | `CleanupExpiredDrafts.php:43-57` |
| m2 | non-admin ส่ง `source='admin'` ได้ → บิล tag admin ตลอดชีวิต (`isAdminPath` คง true — เพี้ยน daily_ku ตอนแก้ทีหลัง + analytics) | `StoreBookingRequest.php:26` + `BookingController.php:1470` |
| m3 | charge บน draft + FK restrict แต่ 4 จุดลบ draft ไม่มี charges guard → draft zombie ลบไม่ได้ + sweep warn วนตลอด (D3 ด้านล่าง) | `AdditionalChargeController.php:73-95`, `CleanupExpiredDrafts.php:48`, `BookingController.php:557,1243`, `RoomStatePeriodService.php:308` |
| m4 | FrontDesk 5 catch คืน raw `$e->getMessage()` (ขัด "ห้าม leak") + `recordPayment` เจอ pending+staff → transition throw → rollback Payment row ทั้ง tx | `FrontDeskController.php:131,288,397,498,545-585` |
| m5 | `requestPayment` (mock QR ตายแล้ว webhook 410) ยัง route อยู่ → สร้าง pending Payment อมตะ + คิดยอดไม่รวม charges | `PaymentController.php:44-48`, `routes/api.php:222` |
| m6 | Report data ม้วน `whereIn('status',...)` เองแทน `holdingSlot()` → รายงานอาหารเช้า/เตียงเสริมนับ draft หมดอายุเกิน | `ExtraBedReportData.php:67`, `BreakfastReportData.php:44` |
| m8 | owner ลบ deferred draft ที่ admin จัดให้ได้ (guard เช็คแค่ draft + no-payments; mode A ยังไม่มี payments row) | `BookingController.php:538-547` |
| m9 | stock adjust TOCTOU — เช็ค negative นอก tx ไม่มี lock (เห็นผลบน PG เท่านั้น) | `StockController.php:241-256` |
| m10 | OrganizationController กลืนทุก exception เป็น 404 ไม่มี `Log::error` (ขัด 500-convention) | `OrganizationController.php:30-49` |
| m11 | `HousekeepingTask::transitionStatus` ไม่เขียน audit log (Booking/BookingRoom/Room เขียนครบ) | `HousekeepingTask.php:105-147` |
| m12 | งานรูป uncommitted: ขาด negative-path test 4 สาขา (org booking `user_id=null` / imageable type แปลก / staff เปิดสลิป / แม่บ้านไม่ใช่ assignee → ต้องได้ 403) — ใช้ `createSlipImageDirectly()` เขียนสั้น ๆ ได้ | `tests/Feature/ImageTest.php` |

(m7 = `GET /discounts/{code}` ไม่ใต้ `role:admin` — ย้ายไป D1 ด้านล่างเพราะ test ยืนยัน intentional = doc-drift decision)

## ⚪ Nit (เก็บไว้กวาดคราวหน้า)

- `CheckRole` คืน key `error` ไม่ตรง shape `{"status":"error"}` (`app/Http/Middleware/CheckRole.php:22-25`)
- TrustProxies ไม่ได้ตั้ง — ถ้าลงหลัง reverse proxy ที่ rewrite scheme signed URL รูปจะพังทั้งระบบ (`Image.php:79-88`, bootstrap/app.php)
- 403 invalid signature ไม่อยู่ใน envelope (`routes/api.php:90`) + ข้อความ 401 ซ้ำ 2 ที่ (`bootstrap/app.php:71-72` / `ImageController.php:43-44`)
- `BookingRoom::transitionStatus` default `$userRole='admin'` (`BookingRoom.php:76`) · `updateStatus` ยอมเป้า `draft` ที่ transition ไม่ผ่านเลย (`BookingController.php:1929-1977` ยังไม่มี in-controller re-check)
- allocator ไม่บังคับ extra-bed capacity เป็น hard constraint (`CostCalculator.php:81-86`) · `BipartiteMatcher` break เร็วเกิน → ค่า matched ต่ำกว่าจริงกระทบ quality ของ overflowFallback (`BipartiteMatcher.php:46`, `FloorFirst.php:222-243`)
- `UserController::destroy` ไม่มี try/catch + orphan token + ลบ admin ได้จนหมด (`UserController.php:68-77`)
- `GlobalRate::getPrice` ใช้ `whereRaw('is_active = TRUE')` จุดเดียวที่เหลือ (`GlobalRate.php:66-69`) · LIKE wildcard ไม่ escape ใน org search (`OrganizationController.php:69-71`) · `walkIn.verified_by`/`received_by` รับ uuid ใครก็ได้ (`FrontDeskController.php:32,534`)
- **Doc drift 3 จุด:** AGENTS.md ยังลิสต์ Room 7 สถานะ (จริงเหลือ 5 — maintenance/reserved_closed ย้ายไป periods 24/09/26) · HK assign ตาม route เป็น admin-only ไม่ใช่ admin+staff · m7 discount show — แก้พร้อม D1

## ✅ ตัดสินแล้ว — ตรวจแล้วตรง claim (ห้าม re-litigate / ไม่ต้อง re-trace)

- **State machine chokepoints:** ทุก transition เขียน audit log ใน method — grep ไม่เจอ `->status =` นอก chokepoint (hit เดียวคือ redemption hook 'used' `Booking.php:314`)
- **holdingSlot ครบทุก availability/calendar/write path** (BookingController 4 write paths, RoomController 7 จุด, allocator, FrontDesk, RoomStatePeriodService) — รูรั่วมีแค่ report data (m6)
- **Pricing tier v2 ครบทุกกติกา** (x≥20 422 ฝั่ง non-admin, `intdiv(x×month,30)`, group fallback, set_room_price ชนะ, บิล admin คง pricing) + **nights/half-open date ถูกต้อง ไม่มี off-by-one**
- **ระบบส่วนลด lifecycle สมบูรณ์:** single insert point, quota all-or-nothing, held คงอยู่ผ่าน pending/verify_error, → used idempotent, release ครบทั้ง 4 จุดลบ
- **เงิน integer baht สะอาด** (ไม่มี float/divide นอก Excel display) · **PgBoolean ครบทุก boolean column**
- **SSO/Sanctum:** login กรอง `auth_provider=password`, PKCE relay ไม่เก็บ KU tokens, first-login ku_member, token ไม่ revoke เดิม, logout เฉพาะ current, expiry 480 นาที enforced ใน guard — CORS wildcard ปลอดภัย (token-mode, ไม่มี statefulApi)
- **งานรูป uncommitted:** ownership chain ครบทุก imageable ที่มีอยู่จริง (3 จุดสร้างเท่านั้น), fail-closed ถูกทาง, 401 ก่อน 403 ถูกลำดับ (signed ไม่อยู่ใน middleware priority), `$hidden` ไม่ทับ `$appends`, tests ใช้ signed URL + token จริงไม่มี stub
- **Excel reports:** slug resolve เป็น const map (ไม่มี injection), 366-day ceiling ทุก report, template 15/15, engine ไม่มี query / Data ไม่มี styling ตามสถาปัตยกรรม

## Decisions รอ owner ตัดสิน (grill ก่อนเขียนโค้ด)

- **D1 — `GET /discounts/{code}` เปิดทุก role (ตอนนี้):** route อยู่นอก `role:admin` (`routes/api.php:153-154`) แต่ `test_all_user_roles_can_get_discount_by_code` ยืนยัน intentional → **จะแก้โค้ด (ย้ายเข้า admin group / จำกัด UUID-lookup เฉพาะ admin) หรือแก้ AGENTS.md ให้ตรง fact?** · เปิดทุก role = user ทั่วไปเห็นโค้ด inactive + quota ได้ (ระดับเสี่ยงต่ำ)
- **D2 — staff เก็บเงินบนบิล `pending` ได้ไหม:** ตอนนี้ `recordPayment` บน pending โดน role guard (`pending→paid` อนุญาตเฉพาะ admin `Booking.php:266`) → rollback Payment row ทั้ง tx + 400 raw message → **จะเพิ่ม 'staff' ใน machine, หรือบังคับ 403 สุภาพโดยไม่ rollback?**
- **D3 — charge บน draft บล็อกการลบยังไง:** FK restrict + ไม่มี charges guard ทั้ง 4 จุดลบ draft → **เพิ่ม guard 422 "ลบ charge ก่อน" (อนุรักษ์นิยม) หรือ cascade พร้อม export audit ก่อนลบ?**

## ลำดับแก้ที่แนะนำ

1. **ชุดด่วน (< 50 บรรทัด รวมกัน):** B1 → M1 → M2 → M3 → M4 (แตะเงิน + double-book ตรง ๆ ทุกตัว)
2. **ชุด operation:** M5 (คืน throttle) · M9 (mock route) · M6 → M7 → M8
3. **รอ D1–D3 ตัดสินแล้วค่อยทำ:** m3/m4/m8 + m7
4. **กวาดท้าย:** minors ที่เหลือ + nits + doc drift + m12 (tests งานรูป)

## Out of scope

- WebSocket housekeeping — ปิดตายแล้วตาม AGENTS.md (polling คือ final design) ไม่เสนอซ้ำ
- การรื้อสถาปัตยกรรมใด ๆ — scrutinize สรุปแล้วว่าไม่จำเป็น (โครง chokepoint/holdingSlot/pricing แน่นหนา)
