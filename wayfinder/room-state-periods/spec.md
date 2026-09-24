---
label: ready-for-agent
title: "room_state_periods — ห้องสำรอง/ซ่อมแซม เป็นช่วงเวลา (period) แทน is_reserved flag + สถานะ maintenance"
status: open
assignee:
blocked-by:
---

# Spec: `room_state_periods` — ห้องสำรอง/ซ่อมแซม กำหนดระยะเวลาแบบ booking

> Source: wayfinder map `room-state-periods` tickets 01–05 (owner grill HITL ครบ 2026-09-24) + codebase audit 2026-09-24.
> กฎเหล็ก: **single source of truth = ตาราง period · ถอด `rooms.is_reserved` และสถานะ `maintenance` ออกทั้งคู่ พร้อม release เดียว · derived ตอน query ไม่มี sweep · maintenance ตัดเด็ดขาด · reserved override ได้ด้วย `include_reserved` (admin)**

## Problem Statement

ปัจจุบัน "ห้องสำรอง" = `rooms.is_reserved` boolean ถาวร (pool membership — decision ticket 90 ของ map `reserved-room-pool`) และ "ซ่อมแซม" = สถานะ `maintenance` ที่ไม่มีวันเริ่ม/วันจบ ทำให้ (1) บันทึกไม่ได้ว่าห้องจะกลับมาวันไหน (2) ไม่มีประวัติว่าเคยถูกกัน/ซ่อมช่วงไหน (3) admin ปลดสถานะต้อง flip มือผ่าน endpoint สถานะ

Req ใหม่: ทั้งสองอย่างต้องเป็น **ช่วงวันที่** (เริ่ม–สิ้นสุด) ทำงานเหมือน `booking_rooms` (check_in/check_out) — หมดอายุแล้วห้องกลับมาขายเองโดยไม่ต้องแตะอะไร

## Solution

ตารางเดียว **`room_state_periods`** (room_id, kind `reserved`|`maintenance`, start_date, end_date nullable เปิดปลายได้) ทุกจุดที่เคยอ่าน `is_reserved`/สถานะ `maintenance` สลับเป็น **period-check ผ่าน shared scope ชุดเดียว** (ธรรมเนียม `holdingSlot()` — ห้าม whereIn/เงื่อนไขเอง) พร้อม CRUD endpoint ซ้อนใต้ `/rooms/{roomId}/periods` สำหรับ admin/staff

## Schema — `room_state_periods` (migration ก้อนเดียว)

| column | type | note |
|---|---|---|
| `id` | uuid pk | HasUuids |
| `room_id` | uuid FK → rooms (cascadeOnDelete) | |
| `kind` | string | `reserved` \| `maintenance` — ธรรมเนียมโปรเจกต์ = string + validate ที่ FormRequest (ไม่ใช้ DB enum) |
| `start_date` | date | ย้อนอดีตได้ ("ท่อระเบิดคืนวานบันทึกเช้านี้") |
| `end_date` | date **nullable** | **เปิดปลายได้ทั้งสอง kind** (amendment ticket 05) · **exclusive** เหมือน `check_out` — "ถึงวันที่ 30" = คืน 29 เป็นคืนสุดท้ายที่ห้องหาย |
| `created_by` | uuid nullable FK → users (nullOnDelete) | NULL = row จากระบบ/migration |
| timestamps | | |

Indexes: `room_id` (จาก FK) + composite `[room_id, start_date, end_date]` + `kind`

**นิยาม active:** `start_date <= today AND (end_date IS NULL OR end_date > today)`
**นิยาม overlap กับช่วง [from, to) (half-open เดียกับ booking_rooms):** `start_date < to AND (end_date IS NULL OR end_date > from)`

### กฎระหว่าง period ห้องเดียวกัน (ticket 04 + amendment 05)
- **same-kind overlap → auto-merge:** row **เดิม** (id/created_by คงเดิม) ถูกขยายครอบ union · ซ้อนข้างในไม่ขยายอะไร → ตอบ row เดิมตามสภาพ · merge **cascade** จนไม่เหลือ same-kind overlap · ชน row เปิดปลาย → ผลลัพธ์ยังเปิดปลาย (union กับ ∞ = ∞)
- **ต่าง kind ร่วมอยู่ได้ ช่วงทับ maintenance ชนะทุก semantics** (availability ตัดอยู่แล้วทั้งคู่ + บล็อก check-in + ห้าม include_reserved ในช่วงทับ)
- **validation:** ใส่ `end_date` เมื่อไหร่ต้อง `>= วันนี้` และ `> start_date` (ห้าม period หมดแล้วทั้งช่วง) · ไม่ใส่ = เปิดปลาย

## Shared scopes (period-check ชุดเดียว — ห้ามเขียนเงื่อนไขเอง)

`App\Models\RoomStatePeriod`:
- `scopeOverlapping($q, $from, $to, ?string $kind = null)` — สูตร overlap ด้านบน
- `scopeActiveOn($q, $date = null)` — สำหรับ display
- `KIND_RESERVED` / `KIND_MAINTENANCE` constants

`App\Models\Room` (relation `periods(): HasMany`):
- `scopeFreeOfPeriod($query, $from, $to, ?string $kind = null)` — whereDoesntHave periods overlapping
- `scopeBlockedByPeriod($query, $from, $to, ?string $kind = null)` — ฝาแฝก whereHas (ใช้เมื่อต้องนับ "ห้องที่โดนตัด")

ทุก consumer ด้านล่างใช้ scope นี้เท่านั้น

## Touchpoints ทั้งหมด (จุดที่ต้องแตะ — audit จากโค้ดจริง 2026-09-24)

### A. ถอด `is_reserved` + สถานะ `maintenance`
1. **`app/Models/Room.php`** — ถอด `is_reserved` ออกจาก `$fillable`/`$casts` · state machine ลบ `'maintenance'` ออกทั้ง target (`'maintenance' => ['*']`) และ source (ใน `available => [...]`) · docblock อัปเดต (reserved/maintenance = period ไม่ใช่สถานะ) · **audit log ใน `transitionStatusTo()` คงเดิม** (ticket 03 — transition ของ maintenance หายไปเอง)
2. **`app/Http/Requests/UpdateRoomRequest.php`** — enum สถานะตัด `maintenance` ออก
3. **`app/Http/Requests/StoreRoomRequest.php`** — เช่นเดียวกัน (ไม่มี route store ห้อง แต่แก้กัน misuse)
4. **`app/Models/BookingRoom.php` `assignAvailableRoom()`** (dead code — คงไว้ตาม out-of-scope เดิม) — เอา `'maintenance'`, `'reserved_closed'` ออกจาก whereNotIn (string ค้างของสถานะที่ถอดแล้ว) + comment ชี้ spec
5. **seeders** — ไม่ต้องแก้ (RoomSeeder สร้าง `available` ทั้งหมด ไม่อ้าง is_reserved/maintenance)

### B. สลับ period-check (3 จุด `whereNotIn('status', ['maintenance'])` + จุด is_reserved)
6. **`app/Services/RoomAllocator/RoomAllocator.php` `loadRoomPool()`** — แทน `whereNotIn(status maintenance)` + `where(is_reserved false)` ด้วย `freeOfPeriod(minCheckIn, maxCheckOut)` (union window เดียวกับที่โหลด reservations) — ทุก kind ห้าม assign ระหว่าง period · การ override reserved ผ่าน allocator (flag) เป็นของ map `reserved-room-pool` ticket 04 (frozen — ดูหัวข้อสุดท้าย)
7. **`app/Services/RoomAllocator/BookingPriority.php` `hasX09Free()`** — แทน filter ชุดเดิมด้วย `freeOfPeriod($br->checkIn, $br->checkOut)`
8. **`app/Models/RoomType.php` `scopeWithSellableRoomsAndRates()`** — `total_rooms_count` = **ทุกห้องของ type (ไม่กรองอะไรเลย)** — denominator เต็ม แล้วให้ period ไปตัดที่ matrix (ดูข้อ 10) · comment อัปเดต
9. **`app/Http/Controllers/Api/V1/RoomController.php` `availability()` (summary)** — pool ต่อช่วง `[checkIn, checkOut)`:
   - `rooms_count` = `status=available` + `freeOfPeriod(checkIn, checkOut, maintenance)` + `freeOfPeriod(..., reserved)`
   - ใต้ flag (admin): `reserved_rooms_count` = `status=available` + `blockedByPeriod(..., reserved)` + `freeOfPeriod(..., maintenance)` (maintenance absolute)
   - king counters: `king_total_rooms` = `bed_type=king_size` (ไม่กรองสถานะ — คง semantics เดิม) + ตัด maintenance period เสมอ + ตัด reserved period เมื่อไม่ส่ง flag
10. **calendar 4 endpoints (`availabilityPerDay` / `availabilityRanges` / `unavailableDates` / `unavailableRanges`)** — โหลด `RoomStatePeriod::overlapping($start, $endExclusive)` (all kinds) ครั้งเดียว แล้วยัดเข้า occupied matrix เดียวกับ BR: วันไหน period ครอบ `occupied[type][date] += 1` (ห้องที่ถูกกัน/ซ่อมหายรายวันจริง) · `unavailableRanges` ไม่มี booking เลยแต่มี period → ใช้ max(period.start_date) เป็น end ของช่วงสแกน (แทนคืน list ว่าง)
11. **`app/Http/Controllers/Api/V1/FrontDeskController.php`**
    - `walkIn`: เงื่อนไข `is_reserved` → period-check บนช่วง `[today, today+nights)`: มี **maintenance** period overlap → reject เสมอ · มี **reserved** period overlap → ผ่าน**เฉพาะ** `IncludeReservedGate::enabled($request)` (ไม่ส่ง/ไม่ใช่ admin → reject เหมือนเดิม) · เงื่อนไขสถานะ available/prep_checkin คงเดิม (lifecycle readiness)
    - `checkIn`: เพิ่ม gate — ห้องปลายทางมี **maintenance** period overlap `[BR.check_in, BR.check_out)` → 422 (reserved **ไม่บล็อก** — booking include_reserved ของ admin ถูกต้องแล้ว ฟรอนต์ย้ายด้วย `assigned_rooms` มีอยู่) · ช่องเก่า "เช็คอินห้องสถานะ maintenance หลุดผ่าน" ปิดเองเมื่อสถานะถูกถอด
12. **`app/Support/IncludeReservedGate.php`** — คง helper เดิมทุกอย่าง (admin + flag เท่านั้น, silent-ignore) · **ความหมายเปลี่ยน**: reserved pool = ห้องที่มี reserved period overlap ช่วงที่ขอ (ไม่ใช่ is_reserved) · comment/docblock อัปเดตอ้าง map นี้

### C. CRUD endpoint (ticket 04)
13. **routes (`routes/api.php`)** — ใต้ `auth:sanctum` + `role:admin,staff` (OR semantics):
    ```
    GET    /rooms/{roomId}/periods           → index  (admin+staff เห็นทุก kind)
    POST   /rooms/{roomId}/periods           → store  (201)
    PATCH  /rooms/{roomId}/periods/{periodId} → update
    DELETE /rooms/{roomId}/periods/{periodId} → destroy
    ```
    ไม่เพิ่ม throttle (precedent กลุ่ม admin `/discounts`) · constraint uuid ทั้งสอง segment
14. **`app/Http/Controllers/Api/V1/RoomStatePeriodController.php`** (ใหม่) + **service `app/Services/RoomStatePeriod/RoomStatePeriodService.php`** (ใหม่ — merge/audit/booking-effects เป็น logic domain หนา แยกจาก controller ตาม precedent DiscountService):
    - **สิทธิ์ (guard ใน controller — defense-in-depth):** kind=`maintenance` → admin และ staff ทำได้ครบ POST/PATCH/DELETE/GET · kind=`reserved` → **admin เท่านั้นทุก verb** (staff → 403) · kind immutable ทำให้ PATCH ตรวจจาก row ครั้งเดียวพอ
    - **FormRequests ใหม่** `StoreRoomStatePeriodRequest` / `UpdateRoomStatePeriodRequest`: store = `kind` (in: reserved,maintenance) + `start_date` (date) + `end_date` (nullable|date|>= วันนี้|after:start_date) · update = เฉพาะ `start_date`/`end_date` sometimes + **`kind` prohibited (immutable)** + end >= วันนี้ เมื่อใส่
    - **merge + effects บน "วันที่ครอบใหม่"**: POST → effects ทั้ง union window หลัง merge · PATCH → effects เฉพาะช่วงวันที่**เพิ่ม**มาจากเดิม (หดวัน = ไม่แตะ booking ที่ค้างในช่วงเดิมเด็ดขาด) · effects = (1) ลบ **draft booking** ที่ BR overlap ช่วงใหม่ทันที — mechanism เดียวกับ `BookingController::destroyBooking` (hard delete cascade + audit `draft → deleted`, causer = ผู้สร้าง period) (2) `affected_bookings` = confirmed/checked_in ที่ overlap ช่วงใหม่ — ไม่แตะ แค่รายงาน
    - **Response 201/200:** `period` (row ผลลัพธ์หลัง merge ตรง ๆ ตาม convention) + `merged: bool` + `deleted_drafts: [booking_id…]` + `affected_bookings: [{booking_id, confirmation_number, status, check_in, check_out}]` · DELETE → success message ธรรมดา
    - **Audit:** ทุกเหตุการณ์เขียน `status_change_logs` `entity_type = 'room_state_period'` · `from_status`/`to_status` = ช่วงวันที่ string `"YYYY-MM-DD..YYYY-MM-DD"` (เปิดปลาย = `"YYYY-MM-DD..NULL"`, create ไม่มีเดิม = `from_status: "-"`) · `note` = `created|merged|extended|shortened|deleted` · `role`/`causer_id` = ผู้ทำ · **hard delete เขียน log ก่อนลบ row** (ไม่มี soft delete — ตาม ticket 04)

### D. Migration ข้อมูลเดิม (ticket 05 — ก้อนเดียวจบ ไม่มี dual source)
15. **migration ใหม่** `2026_09_24_*_create_room_state_periods_drop_is_reserved.php`:
    - สร้างตารางตาม schema ด้านบน
    - `rooms.is_reserved = true` → insert period kind=reserved `start_date` = วัน deploy · `end_date = NULL` · `created_by = NULL`
    - `rooms.status = 'maintenance'` → insert period kind=maintenance เปิดปลายเช่นกัน + **flip lifecycle status → `available`** (owner override — สวน dirty; period เป็นตัวกันขายอยู่แล้ว) + เขียน audit log `entity_type=room` from maintenance → available, role `system`, note period migration (ให้ประวัติ REQ-039 ไม่มีรู) + `status_updated_at = now()`
    - drop column `is_reserved`
    - **`down()` best-effort:** สร้าง column คืน (default false) · ห้องที่มี reserved period active ณ ตอน rollback → `is_reserved = TRUE` (PgBoolean gotcha — เขียนด้วย `DB::raw('TRUE')`) · ห้องที่มี maintenance period active → `status = 'maintenance'` · drop ตาราง

### E. Display ที่อ่านสถานะ `maintenance` ตรง ๆ + room JSON (fog ของ map — ticket 06 ตัดสิน)
16. **room JSON 3 endpoints (`allRooms` / `roomStatus` / `getRoomById`)** — decision ที่ ticket 05 ส่งมา: **expose ค่า derived แทน `is_reserved`**:
    - ถอด key `is_reserved` (column drop — release เดียว) แล้วเพิ่ม **`active_periods`** = array period ของห้องที่ **active วันนี้** `[{id, kind, start_date, end_date}]` — eager load `Room::with(['periods' => fn ($q) => $q->activeOn(today)])` กัน N+1
    - เหตุผล: room board ต้อง badge "สำรอง/ซ่อมแซม" ได้ใน call เดียว (ไม่เรียก periods ต่อห้อง) และ id ให้ admin drill-in ต่อที่ endpoint รายห้อง · ⚠️ **breaking change สำหรับ frontend ku-home** — จดใน api_guide + cline.md
    - ไม่มี display ไหนอ่านสถานะ `maintenance` ตรง ๆ ใน repo นี้ (ค้นแล้ว — DashboardController เป็น housekeeping-task-only) จุดเดียวคือ room JSON ด้านบน

## ไม่แตะ (explicit non-goals)
- **`BookingController` capacity denominators 4 จุด** (`Room::where(room_type_id)->count()`) — คงนับห้องกายภาพเต็ม เหมือนที่วันนี้นับ maintenance-status/is_reserved รวมอยู่แล้ว (fail-safe = allocator ตัด period ที่ข้อ 6 + checkIn gate ที่ข้อ 11) — การจองเกิน pool จริงจบที่ assign ไม่ได้ เหมือนพฤติกรรมปัจจุบัน
- **HousekeepingTask ทั้งระบบ** — ไม่มี FK/field อ้าง period · สร้าง/รับ/ทำ task บนห้องติด period ได้ทุก kind · done → `available` คงเดิม (**ห้ามเพิ่ม period-check เอง**)
- **include_reserved ใน calendar 4 endpoints / createBooking / allocator** — วันนี้ flag มีผลแค่ summary (+king) และ (ต่อจากนี้) walk-in — การขยาย flag เหลือ surface อื่นเป็นของ map `reserved-room-pool` (frozen)
- **WebSocket / sweep / cron สำหรับ period** — derived ตอน query เท่านั้น (ticket 01)
- `assignAvailableRoom()` — dead code คงไว้ (แค่เก็บกวาด string สถานะค้าง)

## Testing decisions
- ฝั่ง HTTP feature tests บน SQLite in-memory เทียบ precedent `RoomKingAvailabilityTest` / `BookingAutoAssignRoomsTest` / `FrontDeskTest`
- **tests เดิมที่ต้องแก้:** `RoomStateTest` (transition maintenance — สลับเป็น assert ว่า maintenance ถูกถอด 422) · `RoomKingAvailabilityTest` (ห้อง king "maintenance" → available + maintenance period) · `RoomTest` (total=0 case → period) · `BookingAutoAssignRoomsTest` (ปิด pool ด้วย maintenance status → maintenance period)
- **tests ใหม่ (`RoomStatePeriodTest` + เสริมไฟล์เดิม):**
  - CRUD: สิทธิ์ 3 มุม (admin ครบ / staff maintenance ครบ / staff reserved 403) · validation (end < วันนี้ 422, kind แก้ไม่ได้ 422)
  - auto-merge: POST ทับ row เดิมขยาย union (id เดิมคงอยู่) · cascade หลาย row · ชน row เปิดปลายยังเปิดปลาย · PATCH ใช้กฎเดียวกัน
  - effects: POST ทับ draft → draft หาย + audit `draft → deleted` + `deleted_drafts` ครบ · confirmed/checked_in ไม่หาย แต่ `affected_bookings` รายงาน · PATCH หดวัน **ไม่**ลบ draft ในช่วงเดิม
  - audit: ทุกเหตุการณ์มี log entity_type `room_state_period` (created/merged/deleted) · DELETE แล้ว log ยังอยู่
  - availability: summary ตัดห้อง maintenance period ออกจาก pool ช่วงทับ (นอกช่วงขายปกติ) · flag ของ admin เห็น reserved_rooms แต่ maintenance ไม่เคยกลับมา · calendar per-day ห้องหายเฉพาะวันที่ period ครอบ
  - gates: walk-in เข้าห้อง reserved period ได้เมื่อ admin ส่ง flag / ไม่ส่ง reject / maintenance period reject เสมอ · check-in reject เมื่อ maintenance period ทับช่วงพัก, reserved ผ่าน
  - room JSON: ไม่มี `is_reserved` · `active_periods` แสดงเฉพาะ period active วันนี้
  - allocator: BR ช่วงทับ period ไม่ได้รับห้องที่ติด period

## ผลกระทบ map `reserved-room-pool` (🧊 FROZEN — จดไว้ตอน unfreeze)
- decision ticket 90 (is_reserved flag) **ถูก override ทั้งก้อน** — column/migration เดิม (`2026_09_24_091500_add_is_reserved_to_rooms.php`) ถูกถอดต่อโดย migration ก้อนใหม่ของ spec นี้
- `spec.md` เดิมของ map นั้น **ห้ามปิดตาม spec** — ticket 01 (code landed suite 446) ต้องรื้อให้เข้า period model เมื่อ unfreeze
- `IncludeReservedGate` คงอยู่ แต่ความหมาย = gate reserved **period** (ไม่ใช่ pool membership)
- ticket 04 ของ map นั้น (allocator flag threading ให้ assign-rooms/auto-assign ขยาย pool ด้วย reserved period) ยังไม่เกิด — เมื่อ unfreeze ต้องเขียนใหม่บน period-check scopes ของ spec นี้

## Build status
- (ยังไม่ build — spec นี้คือ deliverable ของ ticket 06 · เมื่อ build ลงแล้วให้ append วันที่ + ชี้ commit ที่นี่)
