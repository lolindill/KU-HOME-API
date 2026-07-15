# KU HOME API — Project Memory

## Project Summary
**KU HOME API** is a hotel management REST API for Kasetsart University (KU). It handles room bookings, payments, front-desk operations (check-in/check-out), housekeeping tasks, and room management. The API is consumed by a separate frontend client.

## Tech Stack
| Layer | Technology |
|---|---|
| Runtime | PHP 8.3+ |
| Framework | Laravel 13 |
| Auth | Laravel Sanctum 4 (token-based API auth) |
| Database | PostgreSQL (Supabase) — will migrate to organization server in future |
| Queue | `database` driver |
| Cache | `database` store |
| Session | `database` driver |
| Testing | PHPUnit 12 |
| Code Style | Laravel Pint |
| Frontend Assets | Vite (minimal, API-only project) |
| Dev Tools | Laravel Tinker, Laravel Pail (log streaming) |

## Development Commands
```bash
composer run setup     # Install deps, generate key, migrate, build assets
composer run dev       # Concurrent: serve, queue, pail logs, vite
composer run test      # Clear config + run PHPUnit tests
php artisan serve      # Start dev server only
```

## 📁 Project Structure

```
hotel/
├── app/
│   ├── Casts/
│   │   └── PgBoolean.php              # 🌟 Custom cast: PHP bool ↔ PostgreSQL boolean (strict typing fix)
│   ├── Console/Commands/
│   │   ├── CleanupExpiredDrafts.php   # Scheduled 02:00 — hard-delete expired draft bookings
│   │   └── DailyRoomMaintenance.php   # Scheduled daily — cluster auto-assign (RoomAllocator) + flag dirty
│   ├── Http/
│   │   ├── Controllers/Api/V1/        # All controllers (REST API, /api/v1/)
│   │   │   ├── AuthController.php     # login, register, logout
│   │   │   ├── UserController.php     # user CRUD, profile, verification
│   │   │   ├── BookingController.php  # booking CRUD, status transitions, room assignment (RoomAllocator)
│   │   │   ├── RoomController.php     # rooms & room types listing, availability
│   │   │   ├── PaymentController.php  # payment requests, webhooks (⚠️ no HMAC yet)
│   │   │   ├── FrontDeskController.php# walk-in bookings, check-in/out, record payments (🧹 checkout creates typed task)
│   │   │   ├── DashboardController.php# 🧹 housekeeping dashboard (Phase A: listTasks/createTask/assignTask/acceptTask/updateStatus)
│   │   │   ├── AddonRateController.php
│   │   │   └── ImageController.php    # 🚧 DRAFT image upload
│   │   ├── Middleware/
│   │   │   └── CheckRole.php          # role-based authorization (user.role vs allowed roles)
│   │   └── Requests/                  # 24 Form Requests (Store*/Update* per model)
│   ├── Models/                        # 13 Eloquent models (most use UUID via HasUuids)
│   │   ├── User.php                   # UUID PK, role field, PgBoolean casts (is_ku_member, ver)
│   │   ├── Booking.php                # UUID, confirmation (atomic counter), PgBoolean is_paid
│   │   ├── BookingRoom.php            # 🌟 check_in/out + guests JSON + BR-level state machine + bed_preference
│   │   ├── Room.php                   # transitionStatusTo() + topology (floor/side/pos/bed_type)
│   │   ├── RoomType.php               # PgBoolean extra_bed_enabled
│   │   ├── Payment.php                # integer amount (satang)
│   │   ├── Receipt.php                # integer amount, atomic receipt_no
│   │   ├── Addon.php / AddonRate.php  # 🌟 AddonRate = server-side price lookup
│   │   ├── HousekeepingTask.php / HousekeepingPhoto.php # 🧹 Phase A: task state machine + types
│   │   ├── StockInventory.php         # 🧹 Phase A: master stock (replaces HousekeepingInventory)
│   │   └── Image.php                  # 🚧 DRAFT polymorphic
│   ├── Services/
│   │   └── RoomAllocator/             # 🏨 Phase 4 (14/07/26): v3 Walking Distance cluster algorithm
│   │       ├── RoomAllocator.php      # ⭐ Entry: allocate(Collection $brs) → AllocationResult
│   │       ├── Topology.php           # globalPos() + walkingDist() — port จาก playground
│   │       ├── Weights.php            # value object โหลดจาก config/allocation.php
│   │       ├── CostCalculator.php     # cost() (guide) + walkCost() (🏆 Final Judge Σ pairwise)
│   │       ├── BipartiteMatcher.php   # Kuhn's algorithm (slot ↔ room position)
│   │       ├── BookingPriority.php    # เรียง queue: Suite → X09 → Twin → Most rooms → Checkout
│   │       ├── X09Seeder.php          # pin Deluxe 3-bed (builtin≥2) ถ้า booking ต้องการ extra bed
│   │       ├── Dto/                   # RoomDto, BookingRequestDto, AllocationResult
│   │       └── Algorithms/            # Algorithm interface + A/C/D/FF + HybridPlus (EA = production)
│   └── Providers/AppServiceProvider.php  # 🏨 Phase 5 binding: RoomAllocator + Weights (fixed 15/07/26)
├── routes/
│   ├── api.php                        # 🎯 All REST routes (/api/v1/, public/auth/admin groups)
│   ├── console.php                    # Scheduled commands (DailyRoomMaintenance)
│   └── web.php
├── database/
│   ├── migrations/                    # 26 migrations (UUID PKs, PgBoolean, atomic sequences, topology)
│   │   ├── 2026_06_04_043100_create_booking_sequences_table.php  # confirmation counter
│   │   ├── 2026_06_04_150000_create_receipt_sequences_table.php  # receipt counter
│   │   ├── 2026_06_18_150000_move_guests_to_booking_rooms.php    # 🌟 refactor
│   │   ├── 2026_06_19_110000_create_addon_rates_table.php        # 🌟 server-side pricing
│   │   ├── 2026_07_03_000000_drop_children_from_bookings_table.php
│   │   ├── 2026_07_13_105530_add_topology_to_rooms_table.php           # 🏨 floor/side/pos/bed_type
│   │   └── 2026_07_13_105531_add_bed_preference_to_booking_rooms_table.php # 🏨 twin|any
│   ├── seeders/                       # DatabaseSeeder, RoomSeeder (100 rooms), UserSeeder, AddonRateSeeder
│   └── factories/UserFactory.php
├── tests/
│   ├── TestCase.php                   # Base: actingAsAdmin(), actingAsUser(), createAdmin()
│   ├── Feature/                       # 8 files — HTTP integration (SQLite in-memory + RefreshDatabase)
│   │   ├── AuthTest.php (9)           #  BookingTest.php (14)   FrontDeskTest.php (8)
│   │   ├── PaymentTest.php (4)        #  RoomTest.php (9)       RouteProtectionTest.php (20)
│   │   └── UserTest.php (6)
│   └── Unit/                          # state machine + algorithm coverage
│       ├── BookingStateTest.php (23)  #  all transitions + role restrictions
│       ├── RoomStateTest.php (23)     #  all transitions + edge cases
│       └── RoomAllocator/             # 🏨 Phase 6: algorithm tests (33 tests, 50 assertions)
│           ├── TopologyTest.php (12)           # globalPos + walkingDist (pure unit)
│           ├── CostCalculatorTest.php (13)     # cost + walkCost + breakdown (pure unit)
│           └── RoomAllocatorIntegrationTest.php (8) # end-to-end allocate() via DB
├── docs/                              # 📚 Documentation & interactive tools
│   ├── api_guide.md                   # API reference (state machines, enums, validation)
│   ├── database-er.md                 # ER diagram
│   ├── project-status.md              # 📊 % complete per module (overall ~75%)
│   ├── playground.md                  # 🎮 AI agent guide for room-algorithm playground
│   ├── plan_for_test.md               # Plan: preset cases + metrics (✅ done)
│   ├── room-algorithm-playground.html # 🎮 Single-file interactive algorithm tester (A-I)
│   ├── room-algorithm-explainer.html  # Reference doc for algorithms A-E
│   ├── algo_test/                     # 🏨 v3 playground (Walking Distance Final Judge)
│   │   ├── room-algorithm-playground-eav3.html        # final algorithm (EA = Hybrid+)
│   │   └── room-algorithm-flow-explained.md           # 📐 Paper อธิบาย flow + math
│   ├── test-presets.cjs               # 🧪 node vm test harness (153 runs, 11 assertions)
│   ├── _extracted.js                  # extracted JS from playground (analyze)
│   └── API Bin v2 (KU HOME API).xlsx  # API collection export
├── process/                           # Monthly Process Reports (DD_M_YY.md, every 16th)
├── test_scripts/                      # Manual API test scripts (php, run from root)
│   ├── api_guide.php                  # 🔗 Full lifecycle test (recommended)
│   ├── api_test_chain.php             # 10-phase chained integration test
│   ├── test_get_bookings.php          # quick GET /bookings
│   └── quick_test.php                 # set admin role + list users
├── config/                            # Laravel config (auth, sanctum, database, cors, queue, ...)
│   └── allocation.php                 # 🏨 Phase 3: weights + caps (env-tunable)
├── cline.md                           # 📖 This file — project memory for AI agents
├── plan_re_booking.md / plan_re_booking_final.md  # booking refactor plans (used)
├── composer.json                      # PHP 8.3, Laravel 13, Sanctum 4, Pint, Pail
├── package.json                       # Vite (minimal — API-only project)
└── phpunit.xml                        # SQLite in-memory test config
```

### Key Conventions in Structure
- **Controllers**: all under `App\Http\Controllers\Api\V1\` (REST `/api/v1/` prefix)
- **No API Resources/Transformers**: Models returned directly (no wrapper class)
- **No Policy classes**: Authorization via `CheckRole` middleware only
- **UUID everywhere**: Most models use `HasUuids` trait (string PK, `$incrementing=false`)
- **Boolean columns**: Use `PgBoolean` cast (NOT PHP `true`/`false` directly — PostgreSQL strict typing)
- **Throttle**: `5,1` on login/booking; `10,1` on lookup routes
- **Th-file tools**: `docs/room-algorithm-playground.html` + `docs/algo_test/` are single-file HTML+CSS+JS (algorithm R&D)
- **Service layer**: `app/Services/RoomAllocator/` is the first service namespace (Phase 4, 14/07/26) — pure classes, no container binding, invoked via `app(...)`

## 📊 Project Status Report

> ไฟล์รายงานภาพรวมโปรเจกต์เป็น % — ลงรายละเอียดทั้ง 14 โมดูล + Priority Roadmap + Weighted Average

- **ไฟล์:** `docs/project-status.md`
- **Last Updated:** 2026-06-29
- **Overall:** ~75% complete, ~60% production-ready

| Module | % |
|---|---|
| 🔐 Auth/Login | 70% (ยังไม่เชื่อม KU/Google SSO) |
| 👤 User CRUD | 100% |
| 🛏️ Room & RoomType | 100% |
| 📅 Booking CRUD | 95% |
| 🚶 Front Desk | 100% |
| 💳 Payment | **30%** (🔴 ยังไม่มี gateway + HMAC) |
| 🧾 Receipt | **30%** (🔴 design ยังไม่ final) |
| 🧹 Housekeeping | **90%** (✅ Phase A refactor done — Phase B WebSocket เหลือ) |
| ➕ Addon & AddonRate | 95% |
| 🖼️ Image Upload | 10% (🚧 draft) |
| 🎟️ Discount | 20% (🚧 draft) |

---

## Architecture Overview

### API Versioning
All API routes are prefixed with `/api/v1/`. Defined in `routes/api.php`.

### Standardized API Response Format
All endpoints use consistent JSON format:
```json
// Success
{ "status": "success", "message": "...", ...relevant_fields }

// Error
{ "status": "error", "message": "..." }
```
No `data` wrapper — resources are returned directly at top level (e.g., `booking`, `rooms`, `user`).

### Authentication & Authorization
- **Auth**: Laravel Sanctum (bearer token). Login returns a token used in `Authorization: Bearer <token>` header.
- **Role Middleware**: `CheckRole` middleware (`app/Http/Middleware/CheckRole.php`) checks `user->role` against allowed roles.
- **Roles**: `user`, `guest`, `ku_member`, `staff`, `admin`, `housekeeping`, `system`
- **Login Rate Limit**: `throttle:5,1` (5 requests per minute)

### Route Groups
| Group | Auth | Middleware | Key Operations |
|---|---|---|---|
| Public | None | — | Login, register, room availability, room types, payment webhook |
| Authenticated | `auth:sanctum` | — | Logout, view own bookings, create booking, user profile, discount validation |
| Admin | `auth:sanctum` | `role:admin` | User CRUD, booking status management, room status management, payment requests, housekeeping tasks, front-desk operations |

### Draft / Testing Routes (🚧)
These routes exist but are **not for production use**:
- `POST /upload-image` — Image upload system incomplete
- `POST /bookings/validate-discount` — Discount system incomplete (only `WELCOME10`)

> 🌟 **Refactor (18/06/26)**: Removed `POST /bookings/lookup` and `POST /bookings/{id}/request-payment` — non-member/guest access disabled. All users must login.

### Controllers (all in `App\Http\Controllers\Api\V1\`)
- `AuthController` — login, register, logout
- `UserController` — user CRUD, profile, verification (Eloquent-based)
- `BookingController` — booking CRUD, status transitions, room assignment
- `RoomController` — rooms & room types listing, availability, status updates
- `PaymentController` — payment requests, webhooks
- `FrontDeskController` — walk-in bookings, check-in, check-out, record payments
- `ImageController` — 🚧 DRAFT image upload
- `DashboardController` — housekeeping dashboard (cleaning tasks, status updates)

### Request Validation
Form Requests are used for validation, located in `app/Http/Requests/`. Pattern: `Store{Model}Request` / `Update{Model}Request`.

## Domain Models

### Entity Relationship Map
```
User ──1:N──> Booking
User ──1:N──> HousekeepingTask (assigned_to)
User ──1:N──> Payment (received_by)

Booking ──1:N──> BookingRoom
Booking ──1:N──> Payment
Booking ──1:N──> Receipt

BookingRoom ──N:1──> RoomType    (เลือกประเภทห้องตอนจอง)
BookingRoom ──N:1──> Room        (nullable — assign เลขห้องตอน check-in)
BookingRoom ──1:1──> Addon       (addon ผูกกับแต่ละ booking room line)
BookingRoom ──1:N──> Guests      (🌟 เก็บใน JSON column `guests` — รองรับหลายคนต่อห้อง)

AddonRate                     (🌟 default prices แยกตาราง — server-side lookup, ไม่ trust client)

Room ──N:1──> RoomType
Room ──1:N──> HousekeepingTask
Room ──1:N──> BookingRoom

Payment ──N:1──> User (receiver)
Payment ──1:N──> Receipt

Receipt ──N:1──> Booking
Receipt ──N:1──> Payment

HousekeepingTask ──N:1──> Room
HousekeepingTask ──N:1──> User (assigned_to)
HousekeepingTask ──1:N──> HousekeepingPhoto
HousekeepingTask ──1:N──> HousekeepingInventory

Image ── polymorphic (imageable_type + imageable_id) 🚧 DRAFT
```

### Key Models

#### User (`app/Models/User.php`)
- **Primary Key**: UUID (`$incrementing = false`, `$keyType = 'string'`)
- **Fillable**: name, email, password, role, title, phone, nationality, is_ku_member, ver
- **Casts**: email_verified_at→datetime, password→hashed, is_ku_member→boolean, ver→boolean

#### Booking (`app/Models/Booking.php`)
- **Primary Key**: UUID (HasUuids trait)
- **Fillable**: user_id, confirmation, source, status, total_amount, is_paid, payment_deadline
- **Casts**: total_amount→integer, is_paid→**PgBoolean** (🌟 Fix 03/07/26 — PostgreSQL strict typing), payment_deadline→datetime
- **Status Field**: string, managed by `transitionStatus()` state machine
- **Relationships**: user (BelongsTo), bookingRooms (HasMany), payments (HasMany), receipts (HasMany)
- **Confirmation**: Generated via `generateUniqueConfirmation()` — atomic counter with `booking_sequences` table (format: `YYYYMM-XXXXX`)
- **Accessors**: `primary_guest_name` — resolves the first guest name from `bookingRooms.guests` JSON (fallback: user name or "Customer")
- 🌟 **Refactor (25/06/26)**: `check_in`/`check_out` moved to `booking_rooms` (each room has its own dates). Booking is a pure container tracking payment/admin flow only.
- 🌟 **Refactor (18/06/26)**: Guest fields (guest_title, guest_name, guest_email, guest_phone, guest_nationality, is_ku_member, children) moved to `booking_rooms`. Booking now only tracks **who booked** via `user_id`.

#### BookingRoom (`app/Models/BookingRoom.php`)
- **Primary Key**: UUID (HasUuids trait)
- **Fillable**: booking_id, room_type_id, room_id (nullable — assign at check-in), check_in, check_out, guests (JSON), children, status, bed_preference (🏨 twin|any — Phase 1, 13/07/26)
- **Casts**: check_in→date, check_out→date, guests→array, children→integer
- **Status Field**: string, managed by `transitionStatus()` BR-level state machine (draft→confirmed→checked_in→checked_out/no_show)
- **Relationships**: booking (BelongsTo), roomType (BelongsTo), room (BelongsTo), addon (HasOne)
- 🌟 **Refactor (25/06/26)**: `check_in`/`check_out` + `status` now live here (BR-level state machine). Each room can have different dates within the same booking.
- 🏨 **Phase 1 (13/07/26)**: เพิ่ม `bed_preference` ('twin' | null=any) — hard constraint ใน RoomAllocator (ไม่ใช่ soft cost)

#### Room (`app/Models/Room.php`)
- **Primary Key**: UUID
- **Status Field**: string (all lowercase), managed by `transitionStatusTo()` state machine
- **Fillable**: room_type_id, room_number, status, builtin_extra_beds, status_updated_at, status_updated_by, **floor, side, pos, bed_type** (🏨 Phase 1, 13/07/26)
- **Casts**: status_updated_at→datetime, builtin_extra_beds→integer (🌟 Fix 03/07/26)
- **Topology** (🏨 Phase 1): `floor` (5-9), `side` (V1/V2A/V2B), `pos` (within side), `bed_type` (double|twin — twin เฉพาะชั้น 8)
- **Relationships**: roomType (BelongsTo), bookingRooms (HasMany), housekeepingTasks (HasMany)

#### RoomType (`app/Models/RoomType.php`)
- **Primary Key**: UUID
- **Casts**: extra_bed_enabled→**PgBoolean**, max_guests→integer, max_extra_beds→integer, extra_bed_price→integer, rate_daily_general→integer (🌟 Fix 03/07/26)

#### Payment (`app/Models/Payment.php`)
- **Primary Key**: UUID (HasUuids trait)
- **Fillable**: booking_id, amount, payment_method, status, reference_number, received_by
- **Casts**: amount→integer (satang/cents) ✅ #30 Fixed
- **Relationships**: booking (BelongsTo), receiver (BelongsTo User via received_by), receipts (HasMany)

#### Receipt (`app/Models/Receipt.php`)
- **Primary Key**: UUID (HasUuids trait)
- **Fillable**: receipt_no, booking_id, payment_id, amount, billing_name, billing_address
- **Casts**: amount→integer (satang/cents) ✅ #30 Fixed
  
## State Machines

### Booking Status Flow (Container — 🌟 Refactor 29/06/26: Final, no cancelled)
```
draft ──> paid ──> confirmed ──> complete
  │                    ▲
  └────────────────────┘ (admin walk-in skips paid)

Role restrictions:
  draft → paid       : user, guest, admin, system (webhook)
  draft → confirmed  : admin only (walk-in — skips payment)
  paid → confirmed   : admin only
  confirmed → complete : admin, system (auto when all BR finished)
```

**❌ ไม่มี `cancelled` แล้ว** — draft ที่หมดอายุจะถูก **hard delete** โดย `CleanupExpiredDrafts` command
**⚠️ `checked_in` / `checked_out` / `no_show` อยู่ที่ `BookingRoom` (BR-level state machine) ไม่ใช่ booking container**

**Important**: Webhook only transitions `draft → paid`. Admin must manually confirm to `confirmed`.

### BookingRoom Status Flow (BR-level — 🌟 Refactor 25/06/26)
```
draft ──> confirmed ──> checked_in ──> checked_out
                 │
                 └──> no_show

Role restrictions (via FrontDeskController):
  confirmed → checked_in  : admin only
  checked_in → checked_out: admin only
  confirmed → no_show     : admin only
```

### Room Status Flow (all lowercase)
```
available ──> occupied ──> checkout_makeup ──> available (via housekeeping done)
    │              │
    │              └──> prep_checkin ──> available / dirty / occupied
    ├──> dirty ──> available / checkout_makeup
    ├──> maintenance ──> * (any status)
    └──> reserved_closed ──> * (any status)
```

Valid room statuses: `available`, `occupied`, `checkout_makeup`, `dirty`, `prep_checkin`, `maintenance`, `reserved_closed`

## Console Commands
- `DailyRoomMaintenance` (`app/Console/Commands/DailyRoomMaintenance.php`) — Scheduled daily. 🏨 **Phase 5 (14/07/26)**: เรียง bookings ตาม BookingPriority (Suite → X09 → Twin → Most rooms → Checkout) แล้วเรียก `RoomAllocator` (Hybrid+ v3 cluster) แทน first-available greedy แบบเดิม — booking ที่จัดยากได้สิทธิ์เลือกก่อน
- `CleanupExpiredDrafts` (`app/Console/Commands/CleanupExpiredDrafts.php`) — Scheduled daily at 02:00. Transitions expired draft bookings (past `payment_deadline`) to `deleted` via `system` role.

## Database
- **Current**: PostgreSQL (Supabase)
- **Future**: Organization's own server
- **Migrations**: 26 migrations covering all entities + topology + bed_preference
- **Seeders**: `DatabaseSeeder`, `RoomSeeder` (🏨 100 rooms: 5 floors × V1/V2A/V2B), `UserSeeder`, `AddonRateSeeder`
- **UUIDs**: Most models use UUID primary keys via `HasUuids` trait or manual `$incrementing = false`

## Monthly Process Reports (`process/`)

โฟลเดอร์ `process/` เก็บ **Monthly Process Report** — รายงานประจำเดือนที่บันทึกงานที่ทำ, issues ที่แก้, tests ที่ผ่าน
- **Schedule:** รายงานทุกวันที่ 16 ของเดือน หรือเมื่อมีการ update สำคัญ
- **Naming:** `DD_M_YY.md` (เช่น `16_5_26.md` = พฤษภาคม 2026, `16_6_26.md` = มิถุนายน 2026)
- **ดูเพิ่มเติม:** `process/README.md`

## Test Scripts (`test_scripts/`)

โฟลเดอร์รวมสคริปต์ทดสอบ API แบบ manual (ไม่ใช่ PHPUnit) — รันจาก **root directory** เท่านั้น

| ไฟล์ | ประเภท | รายละเอียด |
|---|---|---|
| `api_guide.php` | API Test | Full lifecycle: Seed → Booking → Payment → Confirm → Check-in → Check-out (auto cleanup) |
| `api_test_chain.php` | API Test | 10-phase chained integration test |
| `test_get_bookings.php` | API Test | Quick test: Login + GET /bookings |
| `quick_test.php` | DB Utility | Set user role to admin + list all users |
| `tinker_test.php` | Tinker Snippet | สำหรับ copy-paste ลง `php artisan tinker` |

```bash
php test_scripts/api_guide.php          # 🔗 Full Chain Guide (แนะนำ)
php test_scripts/api_test_chain.php     # 🔗 Chained Integration Test
php test_scripts/test_get_bookings.php  # 📋 Quick GET /bookings test
php test_scripts/quick_test.php         # 🔧 Set admin role + list users
```

> **หมายเหตุ**: `setup_test.php` ถูกลบแล้ว (ซ้ำกับ `quick_test.php`), `move_form_SB_plan.md` ลบแล้ว (ใช้เสร็จ), `api_bin_output.txt` อยู่ใน `.gitignore`

## Conventions
- **Language**: Code comments often in Thai with emoji markers
- **Error responses**: Some error messages are in Thai
- **API responses**: Standardized JSON format (`status`, `message`, + resource fields)
- **No `data` wrapper**: Resources returned at top level
- **No resources/transformers**: Models are returned directly (no API Resource classes)
- **No policy classes**: Authorization handled via `CheckRole` middleware only
- **No events/listeners**: Booking/Payment state changes are synchronous
- **Draft/testing methods**: Marked with `🚧 DRAFT / TESTING` comment prefix

## ✅ Refactor (2026-06-18): Booking Structure Overhaul

> **สรุปการเปลี่ยนแปลงครั้งใหญ่** — โครงสร้าง booking & booking room ถูก refactor ทั้งระบบ

### 🎯 การเปลี่ยนแปลงหลัก

1. **❌ Non-member/Guest access disabled** — `POST /bookings`, `POST /bookings/lookup`, `POST /bookings/{id}/request-payment` ถูกลบจาก public routes
   - `POST /bookings` ย้ายไป protected (`auth:sanctum`) — ทุกคนต้อง login ก่อนจอง
   - `lookupBooking()` และ `requestPaymentForGuest()` ใน `PaymentController` ถูกลบทิ้ง
   - Guest/non-member ไม่สามารถใช้งานระบบ booking ได้โดยตรงอีกต่อไป

2. **🚶 Walk-in ใช้ staff user** — `FrontDeskController::walkIn()` ไม่สร้าง guest user อีกต่อไป
   - ใช้ `verified_by` (staff/admin ID) เป็น `user_id` ของ booking
   - ข้อมูลผู้เข้าพักเก็บใน `booking_rooms.guests` (JSON) แทน
   - Payload ใหม่: `verified_by`, `room_id`, `nights`, `guests[]`, `children`

3. **👥 Guest data moved to `booking_rooms`** — รองรับผู้เข้าพักหลายคนต่อห้อง
   - ลบ columns: `guest_title`, `guest_name`, `guest_email`, `guest_phone`, `guest_nationality`, `is_ku_member`, `children` จาก `bookings` table
   - เพิ่ม columns: `guests` (JSON array), `children` ใน `booking_rooms` table
   - `Booking::primary_guest_name` accessor — ดึงชื่อแขกคนแรกจาก `bookingRooms.guests` JSON

### 📁 Files Changed

| Category | Files |
|---|---|
| Migration | `2026_06_18_150000_move_guests_to_booking_rooms.php` |
| Models | `Booking.php`, `BookingRoom.php` |
| Controllers | `BookingController.php`, `FrontDeskController.php`, `PaymentController.php` |
| Requests | `StoreBookingRequest.php`, `UpdateBookingRequest.php` |
| Routes | `routes/api.php` |
| Tests | `BookingTest.php`, `FrontDeskTest.php`, `PaymentTest.php`, `RouteProtectionTest.php` |

### 🗄️ Migration Required

⚠️ **ต้องรัน `php artisan migrate` หลังจาก pull code นี้** — migration จะ:
1. เพิ่ม `guests` (JSON) + `children` (integer) columns ใน `booking_rooms`
2. ย้ายข้อมูลเดิมจาก `bookings.guest_*` fields ไป `booking_rooms.guests` JSON
3. ลบ `guest_*` และ `children` columns ออกจาก `bookings`

### 🔄 Draft Prevention Logic

- **ก่อน**: ป้องกัน spam ด้วย `guest_email` (public route)
- **หลัง**: ป้องกัน spam ด้วย `user_id` (เพราะทุกคนต้อง login แล้ว)

---

## ✅ Scrutinize: Test Quality Hardening (2026-06-29)

> ตรวจสอบ test files ทั้งหมดเพื่อหา **false confidence** — tests ที่ assert แค่ status code 200 แต่ไม่เช็คว่าข้อมูลจริงๆ กลับมาถูกต้องไหม เสริม assertions ให้ตรวจจับ bugs ได้จริง

### 🎯 การเปลี่ยนแปลงหลัก

เสริม assertions ใน **8 weak tests** ให้ verify payload จริง ไม่ใช่แค่ status code:

| File | Test | เดิม (Weak) | ใหม่ (Hardened) |
|---|---|---|---|
| `BookingTest` | `test_authenticated_user_can_create_booking` | เช็คแค่ 201 + `booking_rooms.children` | + ตรวจ `total_amount` calculated server-side (3000), booking linkage, guests JSON content |
| `BookingTest` | `test_authenticated_user_can_get_own_bookings` | เช็คแค่ 200 | + ใส่ noise booking ของ user อื่น + ยืนยันว่า user เห็นแค่ booking ตัวเอง (no cross-user leak) |
| `RoomTest` | `test_anyone_can_list_rooms` | เช็คแค่ 200 | + ยืนยันว่า rooms ที่สร้าง ปรากฏในผลลัพธ์ |
| `RoomTest` | `test_anyone_can_list_room_types` | เช็คแค่ 200 | + ยืนยัน room type ที่สร้าง ปรากฏในผลลัพธ์ (UUID cast เป็น string ก่อนเปรียบเทียบ) |
| `RoomTest` | `test_anyone_can_check_availability` | เช็คแค่ 200 (ไม่มี parameters เลย!) | + ใส่ check_in/check_out + ยืนยัน available_rooms ≥ 1 |
| `PaymentTest` | (1 test) | *(เสริมใน session ก่อน)* | — |
| `UserTest` | (1 test) | *(เสริมใน session ก่อน)* | — |

### 🧪 Test Results (2026-06-29)

```
BookingTest:  12 passed (24 assertions)
RoomTest:      9 passed (18 assertions)
PaymentTest:   4 passed (7 assertions)
UserTest:      6 passed (11 assertions)
─────────────────────────────────────────
Total:        31 passed (60 assertions)
```

### 💡 Patterns Applied

1. **Negative noise tests** — ใส่ข้อมูลที่ต้องถูกกรองออก (เช่น booking ของ user อื่น) เพื่อยืนยันว่า logic filter ทำงานจริง
2. **Server-side calculation checks** — verify ว่า `total_amount` ถูกคำนวณที่ server ไม่ใช่ trust จาก client
3. **Payload content checks** — ไม่ใช่แค่ status code แต่ต้องเช็คว่าข้อมูลที่คาดหวังกลับมาจริงๆ
4. **UUID type safety** — cast เป็น string ก่อนเปรียบเทียบระหว่าง model object กับ JSON response

### 🔗 Related Session Work

- **FrontDeskController scrutinize** — ตรวจพบ bugs ใน walkIn flow (guest data linkage, amount calculation) และแก้ไขแล้ว

---

## ✅ Tested Changes (2026-06-05)

> การเปลี่ยนแปลงเหล่านี้ผ่าน automated test ทั้งหมดแล้ว — **121 tests, 164 assertions, 0 failures**

### Route Restructuring (`routes/api.php`) — ✅ Verified by RouteProtectionTest
- ย้าย `PUT /bookings/update/{id}` จาก Public → Admin group (`auth:sanctum` + `role:admin`)
- ย้าย `GET /bookings/search` จาก Public → Admin group (`auth:sanctum` + `role:admin`)
- ย้าย User CRUD routes เข้า `role:admin` middleware
- ย้าย `PUT /rooms/{id}/status` เข้า `role:admin` middleware
- ย้าย `POST /payments` เข้า `role:admin` middleware
- เพิ่ม `throttle:5,1` ให้ `POST /login` (เดิมไม่มี)
- เพิ่ม `throttle:5,1` ให้ `POST /bookings` (เดิมไม่มี — #6 fixed)
- เพิ่ม `throttle:10,1` ให้ `POST /bookings/lookup` (เดิมไม่มี)
- เพิ่ม `throttle:5,1` ให้ `POST /bookings/{id}/request-payment` (เดิมไม่มี)
- ~~**Bug fix**: Route `GET /bookings/search` ต้องอยู่ก่อน `GET /bookings/{id}`~~ → ❌ **ลบแล้ว**: merged เข้า `GET /bookings?term=` (see #14 fix)

### New Endpoints — ✅ Verified by BookingTest, PaymentTest
- **`POST /bookings/lookup`** — Guest ค้นหาบุ๊กกิ้งด้วย confirmation + email/phone
- **`POST /bookings/{id}/request-payment`** — Guest ขอลิงก์ชำระเงิน (email verification)

### Bug Fixes Found & Verified by Tests
- **`BookingController::updateStatus()`** — แก้ response key ซ้ำ: `'status'` → `'booking_status'`
- **`BookingController::store()`** — `guest_nationality` ส่ง `null` ทับ default → แก้ migration เป็น `nullable()`
- **`FrontDeskController::walkIn()`** — User.firstOrCreate ไม่มี `email` (NOT NULL) → เพิ่ม auto email
- **`FrontDeskController::walkIn()`** — BookingRoom.create ใส่ `room_price`, `subtotal` ซึ่งไม่มีใน schema → ลบออก
- **`FrontDeskController::recordPayment()`** — `$validated['reference_number']` undefined → เพิ่ม `?? null`
- **`UserController::update()`** — Role escalation vulnerability → user ไม่สามารถเปลี่ยน role ผ่าน profile update ได้แล้ว

### Test Suite (121 tests)
| File | Tests | Coverage |
|---|---|---|
| `tests/Unit/BookingStateTest` | 23 | Booking status state machine (all transitions + role restrictions) |
| `tests/Unit/RoomStateTest` | 23 | Room status state machine (all transitions + edge cases) |
| `tests/Feature/AuthTest` | 9 | Register, login, logout, me, 401 |
| `tests/Feature/BookingTest` | 14 | CRUD (auth required), search via `?term=`, validation, status transitions, draft prevention (by user_id), rate limiting |
| `tests/Feature/FrontDeskTest` | 8 | Walk-in, check-in (incl. draft rejection), check-out, full integration flow, record payment, room type mismatch |
| `tests/Feature/PaymentTest` | 4 | Payment request, webhook (removed guest email verification tests) |
| `tests/Feature/RoomTest` | 9 | List rooms/types, availability, status update + transitions, availability query consistency |
| `tests/Feature/RouteProtectionTest` | 20 | Public vs authenticated vs admin route access (added create_booking_requires_auth) |
| `tests/Feature/UserTest` | 6 | User CRUD, profile update, role escalation prevention |

### Base Test Infrastructure
- `tests/TestCase.php` — Base with `actingAsAdmin()`, `actingAsUser()`, `createAdmin()` helpers
- All tests use SQLite in-memory + `RefreshDatabase` trait

---

## ✅ Fixed Issues (สรุป)

> ปัญหาทั้งหมดนี้แก้ไขแล้วและผ่าน automated tests — 121 tests, 164 assertions, 0 failures

| # | ปัญหา | วันที่แก้ |
|---|---|---|
| #6 | Public booking routes ไม่มี rate limiting | 2026-06-04 |
| #8 | `Booking` model `$guarded = []` → เปลี่ยนเป็น `$fillable` | 2026-06-04 |
| #9 | `StoreBookingRequest` ยอมรับ server-only fields → ลบออก | 2026-06-04 |
| #10 | Confirmation number `rand()` collision → atomic counter | 2026-06-04 |
| #12 | ไม่มี test coverage → 118 tests | 2026-06-04 |
| #13 | `DailyRoomMaintenance` log สถานะผิด → เก็บ original status | 2026-06-04 |
| #14 | `bookingSearch` inconsistent → merged เข้า `getBookings()` | 2026-06-04 |
| #15 | Register allows admin role escalation → pick safe fields only | 2026-06-04 |
| #16 | Guest can spam draft bookings → เช็ค `guest_email` | 2026-06-04 |
| #11 | Booking list ไม่มี pagination → `paginate(15)` | 2026-06-04 |
| #22 | `DailyRoomMaintenance` ไม่มี schedule → มีอยู่แล้วใน `routes/console.php` | 2026-06-04 |
| #23 | Expired draft cleanup → สร้าง `CleanupExpiredDrafts` command + schedule | 2026-06-04 |
| #24 | `LIKE` wildcard abuse → escape `%` และ `_` | 2026-06-04 |
| #25 | Dead route `GET /bookings/addons` → ลบแล้ว | 2026-06-04 |
| #26 | Room state machine `occupied → prep_checkin` ขัดแย้งกับ flow document → เพิ่ม `occupied` เข้า allowed transitions | 2026-06-05 |
| #27 | Addon model `$guarded = []` → `$fillable` | 2026-06-05 |
| #28 | `requestPaymentForGuest()` OR logic อ่อนแอ → AND logic + บังคับ `guest_email` | 2026-06-05 |
| #29 | Webhook ไม่เช็ค `payment_deadline` → เพิ่ม deadline check | 2026-06-05 |
| #33 | `DB::raw('TRUE')` PostgreSQL-specific → `true` (portable) | 2026-06-05 → 🔄 **Reworked 03/07/26**: `true` พังบน PostgreSQL จริง (PDO ส่ง integer 0/1) → ใช้ **PgBoolean custom cast** แทน (ดู root cause fix) |
| #34 | `$totalGuests` dead code → ลบแล้ว | 2026-06-05 |
| #35 | Walk-in `guest_email` ไม่ unique → `walkin-{phone}@hotel.local` | 2026-06-05 |
| #17 | `Room` + `BookingRoom` `$guarded = []` → `$fillable` + Room เพิ่ม `HasUuids` | 2026-06-04 |
| #18 | `recordPayment` ไม่อัปเดต `is_paid` → แก้ + auto Receipt | 2026-06-04 |
| #19 | Receipt number `rand()` → atomic counter `receipt_sequences` | 2026-06-04 |
| #21 | `lookupBooking` OR brute-force → AND + บังคับ `guest_email` | 2026-06-04 |
| #31 | Room availability query inconsistent → `whereIn` ให้ตรงกับ booking logic | 2026-06-05 |
| #32 | `checkIn()` assign rooms ไม่ตรวจ room type → เพิ่ม validation | 2026-06-05 |
| #36 | `BookingController::updateStatus()` ดึง error code จาก exception โดยไม่ validate → `$statusCode = $e->getCode() ?: 500` | 2026-06-05 |
| #37 | `BookingController::createBooking()` ดึง error code จาก exception โดยไม่ validate → แยก business logic (422) จาก unexpected errors | 2026-06-05 |
| #38 | `PaymentController::webhook()` exception handler ไม่มี `DB::rollBack()` → เพิ่มแล้ว (เดิมมีอยู่แล้ว) | 2026-06-05 |
| #39 | `BookingController::createBooking()` `$e->getCode()` อาจ return invalid HTTP code → แยก 422 business logic จาก 500 server errors | 2026-06-05 |
| #40 | **Security**: Exception message รั่วใน error responses → ซ่อน message สำหรับ unexpected errors, คง message เฉพาะ business logic exceptions | 2026-06-05 |
| #41 | `Room` model redundant UUID config → ลบ `$incrementing`, `$keyType`, `$guarded` ที่ซ้ำซ้อนเพราะใช้ `HasUuids` trait แล้ว | 2026-06-05 |
| #30 | Payment/Receipt `amount` type mismatch → standardize เป็น `integer` (satang/cents) ทั้ง DB column + model cast + validation | 2026-06-05 |

---

## ✅ Whole-Project Scrutinize (2026-07-03)

> ตรวจสอบทั้งโปรเจกต์ด้วย `scrutinize` skill — วิเคราะห์ Models/Relations, Controllers/Logic, Requests/Seeders/Docs แบบ end-to-end พบและแก้ bugs **18 ข้อ** (H1 skipped — webhook เป็น mock)

### 🏆 Root Cause Fix: PostgreSQL Strict Boolean Typing

ระหว่างทำงานหนูค้นพบ root cause ที่ซ่อนอยู่: **PostgreSQL strict typing ไม่ยอมรับ integer 0/1 ใน boolean column** แต่ Eloquent `boolean` cast ส่ง PHP `false`/`true` ผ่าน PDO → กลายเป็น integer `0`/`1` → PostgreSQL ปฏิเสธ (Datatype mismatch)

- **ทางแก้เดิม**: `DB::raw('TRUE'/'FALSE')` หรือ string `'true'`/`'false'` กระจัดกระจาย → ไม่สวย และนักพัฒนาใหม่เผลอใช้ PHP bool แล้วพัง
- **ทางแก้ใหม่**: **`App\Casts\PgBoolean`** (Custom Cast class) — แปลง PHP bool ↔ SQL boolean literal อัตโนมัติ ทำงานได้ทั้ง PostgreSQL (strict) และ MySQL/SQLite (lenient)
- **Apply ครบทุก boolean column**: `is_paid`, `extra_bed_enabled`, `is_active`, `is_ku_member`, `ver`

### 🔴 HIGH (5 ข้อ — แก้ครบ)

| Bug | การแก้ |
|-----|-------|
| **H2** orphan `bookings.children` column | migration ใหม่ `2026_07_03_drop_children_from_bookings_table` |
| **H3** `is_paid` write 2 วิธีไม่สอดคล้อง | unify ผ่าน Eloquent + PgBoolean |
| **H4** `StorePaymentRequest.booking_id` required-but-ignored | เปลี่ยนเป็น `sometimes` (front-desk ใช้ URL param) |
| **H5** payment pending ซ้ำ → orphan payments | guard reject ถ้ามี pending อยู่ (1 payment/booking) |
| **Root** PostgreSQL strict boolean | **PgBoolean custom cast** (ดูด้านบน) |

### 🟡 MEDIUM (4 ข้อ — แก้ครบ)

| Bug | การแก้ |
|-----|-------|
| **M1** Seeder ส่ง boolean เป็น string (`'false'`) | ใช้ PHP `false`/`true` จริง (PgBoolean จัดการ) — `RoomSeeder`, `AddonRateSeeder` |
| **M2** duplicate receipts (webhook ยิงซ้ำ) | idempotency guard `if (!Receipt::where('payment_id')->exists())` |
| **M3** webhook พังเมื่อ booking จ่ายแล้ว | guard `if ($booking->status === 'draft')` ก่อน transitionStatus |
| **M4** Dead validation (`status` field) | ลบออกจาก `StorePaymentRequest` + `UpdatePaymentRequest` |

### 🟢 LOW (5 ข้อ — แก้ครบ)

| Bug | การแก้ |
|-----|-------|
| **L1** Missing casts | `Room` (+datetime/integer), `HousekeepingTask` (+datetime×2), `RoomType` (+integer×4) |
| **L2** Missing inverse relationships | `Payment::receipts()`, `HousekeepingTask::room()/assignee()/photos()/inventories()`, `Room::housekeepingTasks()` |
| **L3** DashboardController set task status ตรงๆ | guard `if ($task->status === 'done') throw` |
| **L4** Defense-in-depth gap | `checkOut`/`markNoShow`/`recordPayment` เพิ่ม in-controller role check |
| **L5** ImageController unvalidated polymorphic fields | `StoreImageRequest` +`imageable_id`/`imageable_type` |

### 🧹 Dead Code
- **DC1**: ลบ dead import `UpdateBookingRequest` ใน `BookingController`

### 📄 Docs Drift (`docs/api_guide.md`)
- **D1**: State machine section — ลบ `cancelled`/`deleted` ให้ตรง `Booking::transitionStatus` + เพิ่ม BR-level state machine แยก
- **D2**: Receipt prefix `RCP-` → `REC-`
- **D3**: `payment_method` enum — ลบ `qr`/`other`
- **D4**: `amount` rule `min:1` → `min:0`
- **D5**: `password` rule — ลบ `confirmed`

### 🧪 Test Results (2026-07-03)
```
120 passed (206 assertions) — เพิ่มจาก 116 → +4 tests ใหม่
```
Tests ใหม่: `test_request_payment_rejects_when_pending_exists`, `test_receipt_idempotent_on_duplicate_webhook`, `test_webhook_does_not_crash_when_booking_already_paid`, `test_record_payment_without_body_booking_id`

---

## ✅ Room Allocation Algorithm Port (2026-07-14)

> **พอร์ต algorithm v3 (Walking Distance Final Judge) จาก playground เข้า backend** — แทนที่ first-available greedy แบบเดิมด้วย Hybrid+ (EA) cluster algorithm แบบ end-to-end พร้อมใช้งานจริง

### 🎯 การเปลี่ยนแปลงหลัก

เปลี่ยนวิธีจัดห้องจาก **"assign ทีละ BR แยกกัน"** (first-available greedy) → **"จัดห้องทั้ง booking เป็น cluster"** (ระยะเดินใกล้กันที่สุด) ตาม algorithm ใน `docs/algo_test/room-algorithm-playground-eav3.html`

| ส่วน | เดิม | ใหม่ |
|---|---|---|
| Algorithm | `BookingRoom::assignAvailableRoom()` (first-available greedy ทีละ BR) | `RoomAllocator::allocate()` (Hybrid+ = C+D+A+FF tie-breaker, ทั้ง booking) |
| Topology | ไม่มี (8 ห้อง 101-302) | `floor`/`side`/`pos`/`bed_type` columns + 100 ห้อง (5 ชั้น × V1/V2A/V2B) |
| Cost | ไม่มี (sort ตาม builtin_extra_beds) | `costFunction` (guide) + `walkCost` (🏆 Final Judge Σ pairwise) |
| bed_preference | ไม่มี | hard constraint (`twin` → ต้อง bed_type=twin เท่านั้น) |
| X09 priority | ไม่มี | pin Deluxe 3-bed ถ้า booking ต้องการ extra bed ก่อนรัน algo |

### 📐 Phase Breakdown

| Phase | ไฟล์ | สิ่งที่ทำ |
|---|---|---|
| **1. Schema** | 2 migrations ใหม่ | `rooms` +floor/side/pos/bed_type · `booking_rooms` +bed_preference |
| **2. Seeder** | `RoomSeeder.php` rewrite | 8 ห้อง → 100 ห้อง (5 ชั้น × 20) ตาม topology KU HOME |
| **3. Config** | `config/allocation.php` ใหม่ | weights (floor/side/pos/bed/walk) + caps ผ่าน `.env` |
| **4. Service** | `app/Services/RoomAllocator/` (16 ไฟล์ใหม่) | Topology, Weights, Dto, CostCalculator, BipartiteMatcher, BookingPriority, X09Seeder, 4 algorithms + HybridPlus + RoomAllocator |
| **5. Wire-up** | `BookingController` + `DailyRoomMaintenance` | เปลี่ยนจาก `assignAvailableRoom()` → `RoomAllocator::allocate()` |
| **6. Tests** | `tests/Unit/RoomAllocator/` (3 ไฟล์) | 33 tests: Topology (12) + CostCalculator (13) + Integration (8) |

### 🧠 Algorithm Overview (port จาก playground)

**4 algorithms + Hybrid+ tie-breaker (EA = production):**
- **A (Block Contiguous)** — หาห้องติดกันจริง + bipartite matching · ถ้าไม่เจอ → ok:false → ตัดออก
- **C (Coordinate + Cost)** — brute-force global min (n≤2 เสมอ · n=3 len≤80 · …) + multi-seed greedy fallback
- **D (Greedy Seed + Expand)** — seed + proximity expand (|Δfloor| + |ΔglobalPos|×0.5 + side penalty)
- **FF (Floor-First)** — edge seed + in-floor expand · overflow fallback ถ้าไม่มีชั้นจุครบ
- **EA (Hybrid+)** — รันทั้ง 4 → filter ok → เลือก min(walkCost) · tie → C ชนะ

**2 ชั้น cost:**
- `costFunction` (guide) — multi-dimension: floorSpread + sideMismatch + posSpread² + bedWaste + bedPrefPenalty
- `walkCost` (🏆 Final Judge) — Σ pairwise walking dist × w.walk + floor penalty · ใช้ rank ข้าม algorithm

### 🐛 Bug ที่แก้ระหว่าง port (สำคัญ!)

1. **Mass-assignment protection** — `Room` + `BookingRoom` model ไม่ได้ list topology columns ใน `$fillable` → algorithm ไม่เห็น floor/side/pos/bed_type ทั้งหมด → แก้โดยเพิ่มใน `$fillable`
2. **bed_preference เป็น hard constraint** — เดิมวางเป็น soft penalty (+100) ใน costFunction ทำให้ Hybrid+ เลือก cluster แน่นที่ผิด preference ได้ → เปลี่ยนเป็น filter ในทุก algorithm (`matchesBedPreference()`)
3. **`??` ใน string interpolation** `"{$x ?? 'y'}"` ไม่ support → ใช้ตัวแปรแยก

### 📁 Files Changed (22 new + 4 modified)

| Category | Files |
|---|---|
| Migrations | `2026_07_13_105530_add_topology_to_rooms_table`, `2026_07_13_105531_add_bed_preference_to_booking_rooms_table` |
| Config | `config/allocation.php` (new) |
| Seeder | `RoomSeeder.php` (rewrite — 8 → 100 rooms) |
| Models | `Room.php` (+fillable), `BookingRoom.php` (+fillable) |
| Service | `app/Services/RoomAllocator/` (16 new files) |
| Controllers | `BookingController.php` (autoAssignRooms) |
| Commands | `DailyRoomMaintenance.php` (priority sort + allocator) |
| Tests | `tests/Unit/RoomAllocator/` (TopologyTest, CostCalculatorTest, RoomAllocatorIntegrationTest) |

### 🗄️ Migration Required

⚠️ **ต้องรัน `php artisan migrate:fresh --seed`** (นายท่านเลือกใช้ fresh) เพราะ:
1. Seeder เปลี่ยนจาก 8 → 100 ห้อง (ข้อมูลเดิมใช้ไม่ได้)
2. Algorithm ต้องการ topology columns ทุกห้อง

### 🚀 การใช้งาน

API endpoint `PUT /api/v1/bookings/{id}/assign-rooms` ทำงานเหมือนเดิม แต่ response มี `allocation` debug info เพิ่ม:
```json
{
  "status": "success",
  "booking": { ... },
  "allocation": {
    "winner": "Coordinate + Cost",   // algorithm ที่ชนะ
    "cost": 12.0,                    // walkCost (ยิ่งต่ำยิ่ง cluster แน่น)
    "algo": "Hybrid+ (C+D+A+FF)"
  }
}
```

ปรับ weights ได้ผ่าน `.env`:
```
ALLOC_WEIGHT_FLOOR=50
ALLOC_WEIGHT_SIDE=8
ALLOC_WEIGHT_POS=3
ALLOC_WEIGHT_BED=5
ALLOC_WEIGHT_WALK=1
```

### 🧪 Test Results (2026-07-14)
```
153 passed (256 assertions) — เพิ่มจาก 120 → +33 tests ใหม่ (algorithm)
```
Tests ใหม่ครอบคลุม: Topology (globalPos/walkingDist), CostCalculator (cost/walkCost/breakdown), Integration (solo/mixed/X09/twin/cluster/overlap/no-room)

### 🔗 Related Docs

- `docs/algo_test/room-algorithm-playground-eav3.html` — final algorithm playground (EA = Hybrid+)
- `docs/algo_test/room-algorithm-flow-explained.md` — 📐 Paper อธิบาย flow + math + ตัวอย่าง

---

## ✅ Housekeeping Refactor — Phase A (2026-07-15)

> **เปลี่ยน housekeeping จาก "งานกองกลาง auto ตอน checkout" → "ระบบจัดการงานเต็มรูปแบบ มีหลาย type, assign/accept ได้"**
>
> **Decisions (นายท่านเลือก):** D1=Phase A ก่อน (polling), D2=Daily wire สร้าง pre_checkin, D3=Master stock (ลอย)

### 🎯 การเปลี่ยนแปลงหลัก

| ส่วน | เดิม | ใหม่ (Phase A) |
|---|---|---|
| Task status | `pending \| in_progress \| done` (no state machine) | `unassigned → accepted → in_progress → done` (state machine + lock) |
| Task type | ไม่มี (แยกด้วย `checked_out_at`) | `pre_checkin \| checkout \| checkout_then_in \| daily \| monthly \| group` |
| Assign | `assigned_to` มี column แต่ไม่มี code เขียน | housekeeper accept เอง / admin assign (skip accepted) |
| Routes | `role:admin` ทั้งคู่ | แยก admin (`listTasks/createTask/assignTask`) vs shared `role:admin,housekeeping` (`unassigned/accept/status`) |
| prep_checkin | dead state (ไม่มี code set) | `DailyRoomMaintenance` Phase 2: set + สร้าง task `pre_checkin` อัตโนมัติ |
| ห้อง dirty | mark dirty แต่ไม่สร้าง task → ไม่ขึ้น dashboard | mark dirty + สร้าง task `daily` (gap ปิดแล้ว) |
| Stock | `HousekeepingInventory` (ผูก task) | `StockInventory` (master ลอย — D3) |
| Dashboard ID | `first()` ตาม `room_id` (สับสนหลาย task/ห้อง) | ใช้ `task_id` (Fix S-B2) |

### 🔴 Scrutinize Findings ที่ปิดหมด

| # | Finding | วิธีแก้ |
|---|---|---|
| **S-B1** | `pre_checkin` trigger ไม่มีจุดเกิดจริง | `DailyRoomMaintenance` Phase 2 สร้างให้ (D2) |
| **S-B2** | `checkout` vs `checkout_then_in` overlap + duplicate | task_id แทน room_id + duplicate guard + auto-detect rush |
| **S-B3** | ลบ table แต่ `inventories()` relation ค้าง | ลบ relation + import |
| **S-B4** | housekeeper route/role ไม่มี | แยก routes + seed user + in-controller role check |
| **L3 (dead code)** | guard `done→*` อยู่หลัง `first()` ที่กรอง done ออกแล้ว | ย้าย guard เข้า `HousekeepingTask::transitionStatus()` — ทำงานจริง |

### 🐛 Bonus Bug ที่เจอระหว่างทำ

- **RoomAllocator binding** — `app(RoomAllocator::class)` พัง (BindingResolutionException) เพราะ container ไม่สามารถ autowire `Weights` (ต้องการ `int $floor` primitive) ได้ → เพิ่ม binding ใน `AppServiceProvider` (existing bug ตั้งแต่ Phase 5 14/07 — `DailyRoomMaintenance` พังใน production จริง ไม่เคยถูก test)

### 📁 Files Changed (Phase A)

| Category | Files |
|---|---|
| Migration | `2026_07_15_100000_refactor_housekeeping_tasks_and_add_stock_inventories` (drop `housekeeping_inventories` + add task_type/accepted_at/scheduled_for + create `stock_inventories`) |
| Models | ❌ `HousekeepingInventory` · ✨ `StockInventory` · ✏️ `HousekeepingTask` (state machine + $fillable) · ✏️ `Room` (prep_checkin transition) |
| Providers | ✏️ `AppServiceProvider` (RoomAllocator/Weights binding) |
| Seeder | ✏️ `UserSeeder` (+housekeeping user) |
| Requests | ✏️ `StoreHousekeepingTaskRequest`, `UpdateHousekeepingTaskRequest` · ✨ `AssignTaskRequest` |
| Controller | ✏️ `DashboardController` (rewrite — 6 methods) · ✏️ `FrontDeskController::checkOut` |
| Command | ✏️ `DailyRoomMaintenance` (Phase 2 prep_checkin + Phase 3 dirty+task) |
| Routes | ✏️ `routes/api.php` (split admin vs shared) |
| Tests | ✨ `HousekeepingTaskTest` (16 tests) · ✏️ `RouteProtectionTest`, `TestCase` (+housekeeping helpers) |

### 🗄️ Migration Required (Breaking)

⚠️ **ต้องรัน `php artisan migrate:fresh --seed`** เพราะ:
1. Drop `housekeeping_inventories` table
2. Status `pending → unassigned` migration
3. Seeder เพิ่ม housekeeping user

### 🧪 Test Results (2026-07-15)
```
173 passed (300 assertions) — เพิ่มจาก 153 → +20 tests ใหม่ (housekeeping)
```
Tests ใหม่ครอบคลุม: state machine lifecycle + terminal lock + role gating (admin/housekeeping/user) + duplicate prevention + pre_checkin trigger + checkout_then_in detection + done→available

### 🔗 Phase B (เลื่อน — WebSocket)
- ทำหลัง polling พิสูจน์ว่าไม่พอ
- Stack: Laravel Reverb + Echo
- Auth gap (S-B5): API-only + Sanctum → ใช้ public channel หรือ custom auth driver

---



> ปัญหาที่ตรวจพบจาก Scrutinize Report แต่ยังไม่ได้แก้ไข (อัปเดต: 2026-06-05)

### 🔴 Blocker (ร้ายแรง — deploy จริงไม่ได้)

- **#4: Webhook ไม่มี HMAC signature verification** — `POST /payment/webhook` ไม่มีการตรวจสอบลายเซ็น ใครก็ปลอมการชำระเงินได้
  - ต้องเพิ่ม: HMAC signature check (`X-Webhook-Signature` header) หรือ shared secret token
  - ไฟล์: `PaymentController.php:159`
  - ⏳ **รอหัวหน้าคุยเรื่อง Payment Gateway** — ยังไม่รู้ว่าใช้ Gateway อะไร, signature format ยังไง

### 🟠 Major (สำคัญ — ควรแก้ก่อน production)

*(ไม่มี — #20 แก้แล้ววันที่ 2026-06-19)*

### 🟡 Resolved (แก้ไขแล้ววันที่ 2026-06-05)

- **#40: Exception message รั่วใน error responses** — ✅ แก้แล้ว
  - ปัญหา: หลาย controller ส่ง `$e->getMessage()` ตรงๆ ใน 500 response → รั่ว DB connection info, file paths
  - แก้: Business logic errors (422) ส่ง message ได้, unexpected errors (500) ส่ง generic message + `Log::error()` สำหรับ debug
  - ไฟล์ที่แก้: `BookingController`, `PaymentController`, `DashboardController`, `FrontDeskController`, `RoomController`

- **#41: Room model redundant UUID config** — ✅ แก้แล้ว
  - ปัญหา: `Room` model มีทั้ง `HasUuids` trait และ manual `$incrementing = false`, `$keyType = 'string'`, `$guarded = []` → redundant
  - แก้: ลบ manual config ที่ซ้ำซ้อน เพราะ `HasUuids` trait จัดการให้อยู่แล้ว
  - ไฟล์: `app/Models/Room.php`

- **#30: Payment/Receipt amount type mismatch** — ✅ แก้แล้ว
  - ปัญหา: `Booking.total_amount` cast เป็น `integer` แต่ `Payment.amount` เป็น `decimal:2`, `Receipt.amount` ไม่มี cast → เวลา `$totalPaid >= $booking->total_amount` เปรียบเทียบข้าม type
  - แก้: Standardize ทุก amount field เป็น `integer` (satang/cents) — เปลี่ยน DB column `decimal→bigint`, model cast `decimal:2→integer`, validation `numeric→integer`
  - ไฟล์ที่แก้: `Payment.php`, `Receipt.php`, `StorePaymentRequest.php`, 2 migrations ใหม่

