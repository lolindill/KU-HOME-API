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
│   │   ├── CleanupImages.php          # 🖼️ Scheduled 02:30 — sweep orphan slip files/rows + rejected retention
│   │   └── DailyRoomMaintenance.php   # Scheduled daily — cluster auto-assign (RoomAllocator) + flag dirty
│   ├── Http/
│   │   ├── Controllers/Api/V1/        # All controllers (REST API, /api/v1/)
│   │   │   ├── AuthController.php     # login, register, logout
│   │   │   ├── UserController.php     # user CRUD, profile, verification
│   │   │   ├── BookingController.php  # booking CRUD, status transitions, room assignment (RoomAllocator)
│   │   │   ├── RoomController.php     # rooms & room types listing, availability
│   │   │   ├── PaymentController.php  # ❄️ FROZEN webhook (410), requestPayment (admin, walk-in cash)
│   │   │   ├── FrontDeskController.php# walk-in bookings, check-in/out, record payments (🧹 checkout creates typed task)
│   │   │   ├── DashboardController.php# 🧹 housekeeping dashboard (Phase A: listTasks/createTask/assignTask/acceptTask/updateStatus)
│   │   │   ├── AddonRateController.php
│   │   │   ├── ImageController.php    # 🖼️ serve image file ผ่าน signed URL (images.file)
│   │   │   └── MockController.php     # 🚧 DRAFT / TESTING — mock availability-ranges สำหรับ frontend
│   │   ├── Middleware/
│   │   │   └── CheckRole.php          # role-based authorization (user.role vs allowed roles)
│   │   └── Requests/                  # 24 Form Requests (Store*/Update* per model)
│   ├── Models/                        # 13 Eloquent models (most use UUID via HasUuids)
│   │   ├── User.php                   # UUID PK, role field, PgBoolean casts (is_ku_member, ver)
│   │   ├── Booking.php                # UUID, confirmation (atomic counter), PgBoolean is_paid
│   │   ├── BookingRoom.php            # 🌟 check_in/out + guests JSON + BR-level state machine + bed_preference
│   │   ├── Room.php                   # transitionStatusTo() + topology (floor/side/pos/bed_type)
│   │   ├── RoomType.php               # PgBoolean extra_bed_enabled
│   │   ├── Payment.php                # 🔓 unfrozen (19/08/26 ลบ payment_method) — integer amount (satang)
│   │   ├── Receipt.php                # ❄️ legacy (frozen 24/07/26) — integer amount, atomic receipt_no
│   │   ├── BookingConfirmation.php    # 🌟 NEW (24/07/26): payment proof table, state machine pending→verified|rejected
│   │   ├── Addon.php / AddonRate.php  # 🌟 AddonRate = server-side price lookup
│   │   ├── HousekeepingTask.php / HousekeepingPhoto.php # 🧹 Phase A: task state machine + types
│   │   ├── StockInventory.php         # 🧹 Phase A: master stock (replaces HousekeepingInventory)
│   │   └── Image.php                  # 🖼️ (19/08/26) ระบบรูปจริงจัง: morph + private disk + signed URL 15 นาที + auto-delete-file
│   ├── Services/
│   │   └── RoomAllocator/             # 🏨 Phase 4 (14/07/26): v3 Walking Distance cluster algorithm
│   │       ├── RoomAllocator.php      # ⭐ Entry: allocate(Collection $brs) → AllocationResult
│   │       ├── Topology.php           # globalPos() + walkingDist() — port จาก playground
│   │       ├── Weights.php            # value object โหลดจาก config/allocation.php
│   │       ├── CostCalculator.php     # cost() (guide) + walkCost() (🏆 Final Judge Σ pairwise)
│   │       ├── BipartiteMatcher.php   # Kuhn's algorithm (slot ↔ room position)
│   │       ├── BookingPriority.php    # เรียง queue: Suite → X09 → BedPref → Most rooms → Checkout
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
│   │   └── 2026_07_13_105531_add_bed_preference_to_booking_rooms_table.php # 🏨 king_size|any (rename 27/08/26)
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
| 🖼️ Image System | **90%** (✅ 19/08/26: private disk + `images` table + signed URL — ใช้กับสลิปแล้ว · เหลือ: ไม่มี generic upload endpoint โดยตั้งใจ) |
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
- `POST /bookings/validate-discount` — Discount system incomplete (only `WELCOME10`)

> 🖼️ **(19/08/26)**: `POST /upload-image` (draft, unauthenticated) ถูกถอดออก — แทนด้วยระบบรูปจริง (ไฟล์บน private disk + `images` table + `GET /images/{id}/file` signed URL)

> 🌟 **Refactor (18/06/26)**: Removed `POST /bookings/lookup` and `POST /bookings/{id}/request-payment` — non-member/guest access disabled. All users must login.

### Controllers (all in `App\Http\Controllers\Api\V1\`)
- `AuthController` — login, register, logout
- `UserController` — user CRUD, profile, verification (Eloquent-based)
- `BookingController` — booking CRUD, status transitions, room assignment
- `RoomController` — rooms & room types listing, availability, per-day availability calendar, status updates
- `PaymentController` — ❄️ webhook frozen (410), requestPayment (admin walk-in)
- `FrontDeskController` — walk-in bookings, check-in, check-out, record payments
- `ImageController` — 🖼️ (19/08/26) serve image file ผ่าน signed URL — ไม่มี auth:sanctum (signature ยืนยันแทน) + exempt RequireJsonAccept
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
- **Fillable**: booking_id, room_type_id, room_id (nullable — assign at check-in), check_in, check_out, guests (JSON), children, status, bed_preference (🏨 king_size|any — rename 27/08/26, เดิม twin)
- **Casts**: check_in→date, check_out→date, guests→array, children→integer
- **Status Field**: string, managed by `transitionStatus()` BR-level state machine (draft→confirmed→checked_in→checked_out/no_show)
- **Relationships**: booking (BelongsTo), roomType (BelongsTo), room (BelongsTo), addon (HasOne)
- 🌟 **Refactor (25/06/26)**: `check_in`/`check_out` + `status` now live here (BR-level state machine). Each room can have different dates within the same booking.
- 🏨 **Phase 1 (13/07/26)**: เพิ่ม `bed_preference` ('twin' | null=any) — hard constraint ใน RoomAllocator (ไม่ใช่ soft cost) · 🌟 **Rename (27/08/26)**: ค่าที่ request ได้เปลี่ยนเป็น 'king_size' | null=any

#### Room (`app/Models/Room.php`)
- **Primary Key**: UUID
- **Status Field**: string (all lowercase), managed by `transitionStatusTo()` state machine
- **Fillable**: room_type_id, room_number, status, builtin_extra_beds, status_updated_at, status_updated_by, **floor, side, pos, bed_type** (🏨 Phase 1, 13/07/26)
- **Casts**: status_updated_at→datetime, builtin_extra_beds→integer (🌟 Fix 03/07/26)
- **Topology** (🏨 Phase 1): `floor` (5-9), `side` (V1/V2A/V2B), `pos` (within side), `bed_type` (twin|king_size — king_size เฉพาะชั้น 8; 🌟 rename 27/08/26 เดิม double|twin)
- **Relationships**: roomType (BelongsTo), bookingRooms (HasMany), housekeepingTasks (HasMany)

#### RoomType (`app/Models/RoomType.php`)
- **Primary Key**: UUID
- **Casts**: extra_bed_enabled→**PgBoolean**, max_guests→integer, max_extra_beds→integer, extra_bed_price→integer, rate_daily_general→integer (🌟 Fix 03/07/26)

#### Payment (`app/Models/Payment.php`)
- **Primary Key**: UUID (HasUuids trait)
- **Fillable**: booking_id, amount, status, reference_number, received_by (🌟 19/08/26: ลบ payment_method)
- **Casts**: amount→integer (satang/cents) ✅ #30 Fixed
- **Relationships**: booking (BelongsTo), receiver (BelongsTo User via received_by), receipts (HasMany)

#### Receipt (`app/Models/Receipt.php`)
- **Primary Key**: UUID (HasUuids trait)
- **Fillable**: receipt_no, booking_id, payment_id, amount, billing_name, billing_address
- **Casts**: amount→integer (satang/cents) ✅ #30 Fixed
  
## State Machines

### Booking Status Flow (Container — 🌟 Refactor 25/08/26: เพิ่ม pending และ verify_error)
```
draft ──> pending ──> paid ──> confirmed ──> complete
  │          │          ▲          ▲
  │          │          │          │
  │          └──> verify_error (admin reject สลิป)
  │                 │
  │                 └──> pending (user/guest/admin ส่งสลิปใหม่)
  │                     │          │
  └─────────────────────┘ (admin/system — เงินสดหน้าเคาน์เตอร์ / webhook อนาคต)
  └─────────────────────────────┘ (admin walk-in skips ไป confirmed เลย)

Role restrictions:
  draft → pending        : user, guest, admin (ส่งสลิป POST /bookings/{id}/confirm — mirror BookingConfirmation)
  draft → paid           : admin, system (front-desk เก็บเงินสด / webhook อนาคต — user ทำตรงๆ ไม่ได้อีกต่อไป)
  pending → paid         : admin only (verify สลิปผ่าน — set is_paid=true ตอนนี้)
  pending → verify_error : admin only (reject สลิป)
  verify_error → pending : user, guest, admin (ส่งสลิปใหม่)
  draft → confirmed      : admin only (walk-in — skips payment)
  paid → confirmed       : admin only
  confirmed → complete   : admin, system (auto when all BR finished)
```

**❌ ไม่มี `cancelled` แล้ว** — draft ที่หมดอายุจะถูก **hard delete** โดย `CleanupExpiredDrafts` command
**🧹 `pending` และ `verify_error` ที่หมด deadline ไม่ถูก cleanup ลบ** (ห้องยังถูก hold ไว้ตาม availability และรอ user ส่งสลิปใหม่)
**⚠️ `checked_in` / `checked_out` / `no_show` อยู่ที่ `BookingRoom` (BR-level state machine) ไม่ใช่ booking container**
**⚠️ `PUT /bookings/update/{id}` (admin) validation ยังเป็น `in:draft,paid,confirmed,complete`** — ไม่มี `pending` หรือ `verify_error` โดยตั้งใจ (เกิดจาก slip submission/rejection เท่านั้น)

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

> 🔒 **Re-litigation Freeze (03/08/26 — บันทึกตามคำสั่งนายท่าน):**
> เคย scrutinize + พยายามแก้ทางอื่นมาแล้ว แต่ **ไม่ work** → `PgBoolean` (emit `DB::raw('TRUE'/'FALSE')` ตอน write) คือ **final solution**.
> ทางเลือกที่ลองแล้วพังบน PostgreSQL จริง:
> - ใช้ plain PHP `true`/`false` ตรงๆ (PDO แปลงเป็น int `0`/`1` → PostgreSQL ปฏิเสธ)
> - ใช้ string `'true'`/`'false'` (type mismatch)
> - พึ่ง Eloquent built-in `boolean` cast (ตัวเดียวกันกับที่พัง)
>
> **ห้ามลอง "ปรับปรุง" หรือ "ทำให้ง่ายขึ้น" อีก** — ถ้านึกอยาก refactor แสดงว่ากำลังจะแกนำ bug กลับมา ปล่อยมันไว้แบบนี้ได้เลย

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

---

## ✅ Booking Confirmation Table + Payments/Receipts Freeze (2026-07-24)

> **แยก payment info ออกจาก bookings container → table ใหม่ `booking_confirmations`** + ❄️ freeze `payments`/`receipts` เป็น read-only legacy
>
> **Decisions (นายท่านเลือก):** สร้าง table ใหม่ (ไม่ยัด column ใน bookings) · 1:N history (ไม่ unique) · 1 pending max guard · row ใหม่ทุกครั้ง · verify/reject by confirmation_id · freeze webhook + ลบ Receipt::create

### 🎯 การเปลี่ยนแปลงหลัก

| ส่วน | เดิม | ใหม่ |
|---|---|---|
| Payment flow | webhook (mock, no HMAC) draft→paid + Receipt::create | user `POST /bookings/{id}/confirm` ส่ง slip → admin verify/reject |
| Storage | `payments` (1:N) + `receipts` (1:1) | `booking_confirmations` (1:N history) |
| Receipt | auto-generate ตอน webhook/recordPayment | ❄️ deprecated — ไม่สร้าง row ใหม่ |
| Webhook | draft→paid + receipt (mock) | ❄️ `410 GONE` |
| recordPayment | Payment + Receipt | Payment only (walk-in/cash admin) |
| Confirmation state | n/a | `pending → verified \| rejected` (terminal) |

### 🔄 Flow

```
USER  POST /bookings/{id}/confirm (slip+time)
   └─► สร้าง confirmation (pending) + booking draft→paid
        └─ [1 pending max guard — กัน spam]

ADMIN PUT /booking-confirmations/{id}/verify  → pending→verified + booking paid→confirmed
ADMIN PUT /booking-confirmations/{id}/reject  → pending→rejected, booking ค้าง paid
ADMIN GET  /booking-confirmations/pending     → dashboard list (FIFO)

USER (re-submit หลัง reject) → สร้าง row ใหม่ pending (row rejected เก่ายังอยู่ใน history)
```

### 📁 Files Changed (6 new + 7 modified)

| Category | Files |
|---|---|
| Migration | `2026_07_24_140000_create_booking_confirmations_table` |
| Models | ✨ `BookingConfirmation.php` (state machine) · ✏️ `Booking.php` (+`confirmations()` HasMany) |
| Requests | ✨ `ConfirmBookingRequest` (multipart slip validation) · ✨ `ReviewConfirmationRequest` |
| Controller | ✨ `BookingConfirmationController` (confirm/verify/reject/pending) |
| Routes | ✏️ `routes/api.php` (+4 routes: 1 user + 3 admin) |
| Freeze | ✏️ `PaymentController` (webhook → 410) · ✏️ `FrontDeskController::recordPayment` (ลบ Receipt::create) |
| Tests | ✨ `BookingConfirmationTest` (19 tests) · ✏️ `PaymentTest` (ลบ webhook tests) · ✏️ `FrontDeskTest` (assert no receipt) |
| Docs | ✏️ `api_guide.md`, `database-er.md`, `cline.md` |

### 🗄️ Migration Required

⚠️ **ต้องรัน `php artisan migrate`** — migration จะสร้าง `booking_confirmations` table (ไม่ได้แตะ payments/receipts เดิม เพราะ freeze)

### 🧪 Test Results (2026-07-24)
```
188 passed (343 assertions) — เพิ่มจาก 173 → +15 net (19 ใหม่ - 4 ลบ webhook)
```
Tests ใหม่ครอบคลุม: confirm happy path + ownership + state guards + deadline + 1-pending-max + slip validation + verify/reject + re-submit history + terminal lock + webhook 410

---

## ✅ Audit Log — Booking/BookingRoom State Changes (2026-08-04)

> 📌 **เป้าหมาย:** เมื่อ status ของ Booking หรือ BookingRoom เปลี่ยน → บันทึก audit row (ใคร/role/from/to/เมื่อไหร่) + เปิด endpoint ให้ admin อ่าน

### 🎯 การเปลี่ยนแปลงหลัก

| ส่วน | รายละเอียด |
|---|---|
| Table ใหม่ | `status_change_logs` (polymorphic — `entity_type` + `entity_id`) |
| Chokepoint | เขียน log **ใน** `transitionStatus()` ของ `Booking` + `BookingRoom` (ห้าม bypass) |
| Endpoint | `GET /api/v1/bookings/{id}/status-logs` (admin) → log ของ booking + ทุก booking_room |
| Model | `StatusChangeLog` (HasUuids, append-only) |

### 🤔 Decision: polymorphic ตารางเดียว vs สองตารางแยก

**เลือก polymorphic ตารางเดียว** (`status_change_logs`) เพราะ:
1. ขยาย entity ใหม่ในอนาคตง่าย (Room/HousekeepingTask) — เพิ่ม `entity_type` ใหม่ได้เลย
2. โครงสร้างเดียวกันทุก entity → query/UI/logic รวมศูนย์

**Trade-off ที่ยอมรับ:**
- ไม่ใส่ FK constraint บน `entity_id` (polymorphic → morph target หลายตาราง ใส่ FK ไม่ได้) → ความเสี่ยง orphan row
- ลดความเสี่ยง: row ถูกสร้างเฉพาะตอนที่ entity มีอยู่จริง (ใน `transitionStatus()` ของ model ที่ load มาแล้ว) + booking delete จะไม่ cascade ไป log (ตั้งใจ — audit trail ต้องอยู่ต่อแม้ booking ถูกลบ)

> 🔒 **ห้ามเปลี่ยนเป็นสองตาราง** (re-litigation freeze) — เลือกแล้ว ตามที่นายท่านตัดสินใจใน plan

### 🤔 Decision: เขียน log ใน transitionStatus() ไม่ใช่ observer/event

เหตุผล 3 ข้อ (ไม่ re-litigate):
1. **Project ตั้งใจหลีกเลี่ยง events/listeners** (`cline.md:395` — "No events/listeners … state changes are synchronous")
2. **Observer ไม่เห็น `$userRole`** — role guard อยู่ใน scope `transitionStatus()` เท่านั้น ถ้าใช้ `::updated` hook จะไม่รู้ role ที่ authorize transition
3. **`transitionStatus()` เป็น chokepoint เดียว** — grep ยืนยันว่าไม่มี controller ไหน `->status =` ตรงๆ นอกเมธอดนี้เลย → เขียนที่เดียวครอบคลุมหมด

### 🤔 Decision: ไม่แก้ signature `transitionStatus()`

ถ้าเพิ่ม `causer_id` เข้า signature = breaking change กระทบ caller ~12 แห่ง → ใช้ `Auth::id()` resolve จาก request context ณ runtime แทน (non-breaking). `causer_id` เป็น nullable เพราะ system/queue transition (เช่น `syncStatusFromRooms`) ไม่มี user ล็อกอิน

### 🔄 Transaction Safety

log row ถูกเขียนหลัง `$this->save()` **ในไม่ใช่ try/catch ปกป้อง** เพราะ:
- caller ส่วนใหญ่ (เช่น `FrontDeskController`) ห่อ `transitionStatus()` ใน `DB::transaction()` → log rollback ไปด้วยถ้า transition ที่ตามมา fail
- test `test_audit_log_rolls_back_with_transition_on_failure` ยืนยันพฤติกรรมนี้

### 📁 Files Changed (2 new + 4 modified + 1 test new)

| ไฟล์ | การเปลี่ยน |
|---|---|
| ✨ `database/migrations/2026_08_04_100000_create_status_change_logs_table.php` | สร้างใหม่ |
| ✨ `app/Models/StatusChangeLog.php` | สร้างใหม่ |
| ✏️ `app/Models/Booking.php` | +`use Auth`, `use StatusChangeLog`; +8 บรรทัดใน `transitionStatus()` |
| ✏️ `app/Models/BookingRoom.php` | +`use Auth`, `use StatusChangeLog`; +10 บรรทัดใน `transitionStatus()` |
| ✏️ `app/Http/Controllers/Api/V1/BookingController.php` | +`use StatusChangeLog`; +method `statusLogs()` |
| ✏️ `routes/api.php` | +1 route ใต้ `role:admin` |
| ✨ `tests/Feature/StatusChangeLogTest.php` | สร้างใหม่ (10 tests) |
| ✏️ `AGENTS.md`, `docs/api_guide.md`, `cline.md` | อัปเดต doc |

### 🗄️ Migration Required

⚠️ **ต้องรัน `php artisan migrate`** — migration จะสร้าง `status_change_logs` table (ไม่แตะ table เดิม)

### 🧪 Test Results (2026-08-04)
```
198 passed (370 assertions) — เพิ่มจาก 188 → +10 net (10 audit log tests ใหม่)
```
Tests ใหม่ครอบคลุม: booking transition log + system null causer + invalid transition (no log) + BR transition + BR no_show + syncStatusFromRooms system log + admin endpoint 200 + non-admin 403 + 404 missing booking + transaction rollback safety

### 🔮 Future Extension (ไม่ใช่ scope รอบนี้)

Polymorphic table ออกแบบให้ขยายได้:
- เพิ่ม `entity_type = 'room'` → ใช้ `Room::transitionStatusTo()` เขียน log
- เพิ่ม `entity_type = 'housekeeping_task'` → ใช้ `HousekeepingTask::transitionStatus()` เขียน log
- เพิ่ม `entity_type = 'booking_confirmation'` → ใช้ `BookingConfirmation` state machine

เมื่อขยาย ให้เขียน log ใน `transitionStatus()` ของ model นั้น (chokepoint pattern เดียวกัน) และอัปเดต `StatusChangeLog` model docblock สำหรับ `entity_type` values ใหม่


---

## ✅ Availability Per-Day Calendar Endpoint (2026-08-10)

> เพิ่ม endpoint `GET /api/v1/availability-per-day` — คืนจำนวนห้องว่างรายวัน × ราย room type สำหรับทำ calendar view ที่ frontend (ทำงานคู่กับ `/availability` เดิมที่คืนเลขเดียวต่อ range)

### 🎯 การเปลี่ยนแปลงหลัก
- **Route:** `GET /v1/availability-per-day?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD` (public, ไม่ auth) — เพิ่มใน `routes/api.php` sibling ของ `/availability`
- **Method:** `RoomController::availabilityPerDay()` — คืน **ทุก room type** (ไม่ filter) × ทุกคืนในช่วง
- **Output shape:** minimal ตามที่นายท่านขอ + `room_type_id`/`name_en`/`name_th`:
  ```json
  {
    "status": "success", "message": "...",
    "start_date": "2026-08-10", "end_date": "2026-08-12",
    "room_types": [
      { "room_type_id": "uuid", "name_en": "...", "name_th": "...",
        "2026-08-10": 8, "2026-08-11": 7, "2026-08-12": 9 }
    ]
  }
  ```

### 🤔 Decision: Semantics ของ "ห้องว่างคืนนั้น"
- **Total rooms (ตัวตั้ง)** = ห้องที่ `status NOT IN (maintenance, reserved_closed)` → **ตรงกับ `BookingController::createBooking` และ `BookingRoom::assignAvailableRoom`** (ไม่ใช่ `status='available'` เหมือน `/availability` เดิมที่ under-count)
- **Occupied** = booking_rooms ที่ status ∈ (draft, confirmed, checked_in) และ overlap คืนนั้น — half-open `check_in <= D < check_out` (checkout day free) → ตรงกับ `/availability` + `createBooking` 100%
- **ข้อจำกัดตามธรรมชาติ (ล็อกไว้):** total = snapshot ณ today ของ sellable rooms ไม่ใช่ future-aware maintenance schedule (ถ้าวันนี้ maintenance แต่อนาคตซ่อมเสร็จ calendar ก็ยังตัดออก) → **consistent กับ booking-time check เป็นหลัก** ป้องกัน over-promise

### 🤔 Decision: Matrix one-query approach (ไม่ใช่ N×M ในลูป)
แทนที่จะวน query ทีละวัน × room type ใช้ "load once, fill matrix":
1. query room_types ทั้งหมดพร้อม `withCount rooms whereNotIn(status, [maintenance, reserved_closed])` (1 query)
2. query booking_rooms ที่ overlap `[start, end+1]` **ครั้งเดียว** (1 query)
3. PHP วนแต่ละ BR fill occupied count ลง matrix `[room_type_id][date]` เฉพาะคืนใน [start, end]
4. compute `available = max(0, total − occupied)` แต่ละ cell

→ **3 queries ตลอด ไม่ว่าจะกี่วัน** (vs. `days × room_types` queries แบบ naive). สำคัญมากเพราะ route public + ไม่ auth.

### 🛡️ DoS Guard
Public route → cap `(end − start) ≤ 365` คืน (366 max) → เกินปฏิเสธด้วย 422 `{"status":"error","message":"Date range cannot exceed 366 days"}` กัน JSON bomb และ memory spike (เช่น 100 ปี × ทุก room type)

### 📁 Files Changed (1 controller edit + 1 route + doc)
- `app/Http/Controllers/Api/V1/RoomController.php` — เพิ่ม `use App\Models\BookingRoom` + `use Carbon\CarbonPeriod` + method `availabilityPerDay()`
- `routes/api.php` — เพิ่ม `Route::get('/availability-per-day', [RoomController::class, 'availabilityPerDay'])`
- `cline.md` — section นี้ + อัปเดต controller description (บรรทัด 209)

### 🔮 Future Extension (ไม่ใช่ scope รอบนี้)
- ถ้า calendar ต้องการ future-aware maintenance schedule → ต้องการ `room_maintenance_schedule` table (วันที่เริ่ม/จบ maintenance) แล้ว query เข้า matrix เพิ่ม
- ถ้า frontend ต้องการ rate ด้วย → embed `daily_rate` เหมือน `/availability` (ตอนนี้ minimal ตามคำขอ)
- ถ้าโดน abuse หนัก → เพิ่ม `throttle:10,1` เฉพาะ route หรือ cache ผลลัพธ์ short-TTL


## ✅ Sold-out Intervals + Unavailable Dates Endpoints (2026-08-10)

> เพิ่ม endpoint availability อีก 2 ตัว ทำงานคู่กับ `/availability-per-day` แต่ตอบ use-case อื่น:
> - `GET /availability-ranges` — คืน **intervals** วัน sold-out **ราย room_type** (กลุ่มวันติดกันเป็น `{start_date,end_date}`) เบากว่า per-day matrix
> - `GET /unavailable-dates` — คืน **flat list** วันที่จองไม่ได้เลย (**ทุก room type เต็ม**) สำหรับ disable วันในปฏิทินแบบรวม

### 🎯 การเปลี่ยนแปลงหลัก
- **Route:** `GET /v1/availability-ranges` + `GET /v1/unavailable-dates` (public, sibling ของ `/availability-per-day`)
- **Methods:** `RoomController::availabilityRanges()` + `RoomController::unavailableDates()`
- **Output shapes:**
  ```json
  // /availability-ranges — แยกราย room_type, intervals กลุ่มติดกัน
  { "status":"success", "room_types": [
    { "room_type_id":"uuid","name_en":"...","intervals":[ {"start_date":"2026-08-12","end_date":"2026-08-13"} ] }
  ]}

  // /unavailable-dates — flat list รวมทุก type
  { "status":"success", "unavailable_dates": ["2026-08-12","2026-08-13"] }
  ```

### 🤔 Decision: Semantics ต่างกันยังไง
| Endpoint | Granularity | Shape | "เต็ม" หมายถึง |
|---|---|---|---|
| `/availability-per-day` | ราย room_type × รายวัน | matrix (เลขห้องว่าง) | n/a (คืนจำนวน) |
| `/availability-ranges` | ราย room_type | intervals กลุ่มติดกัน | type นั้น occupied >= total |
| `/unavailable-dates` | รวมทุก type | flat list รายวัน | **sum across types = 0** |

→ total/occupied semantics เหมือนกันทั้ง 3 ตัว (status NOT IN maintenance/reserved_closed, BR ∈ draft/confirmed/checked_in, half-open `check_in <= D < check_out`)

### 🤔 Decision: Edge case `total_rooms = 0`
- **`/availability-ranges`** — `total=0` ไม่ถือว่า sold-out (คืน `intervals: []`) เพราะ frontend มัก disable เฉพาะวันที่เคยมีห้องแต่หมดแล้ว
- **`/unavailable-dates`** — **degenerate guard:** ถ้าไม่มี room type เลย หรือ `sum(total) === 0` → คืน `[]` เลย ป้องกัน false-positive ว่าทุกวัน "เต็ม" ทั้งที่จริงคือไม่มีห้องขายอยู่แล้ว (สำคัญใน dev/test DB ที่ยังไม่มีข้อมูล)

### 🛡️ DoS Guard / Performance
- Public routes → cap `(end − start) ≤ 365` คืน เหมือน `/availability-per-day`
- Reuse matrix one-query approach (3 queries ตลอด ไม่ว่าจะกี่วัน)

### 📁 Files Changed
- `app/Http/Controllers/Api/V1/RoomController.php` — เพิ่ม `availabilityRanges()` + `unavailableDates()`
- `routes/api.php` — เพิ่ม 2 routes ใน public block
- `tests/Feature/RoomTest.php` — เพิ่ม 3 tests สำหรับ availability-ranges + 3 tests สำหรับ unavailable-dates
- `docs/api_guide.md` — เพิ่ม 2 sections ตาม pattern เดียวกับ `/availability-per-day`


## ✅ Add Rooms to Draft Booking Endpoint (2026-08-10)

> เพิ่ม endpoint `POST /api/v1/bookings/{bookingId}/rooms` — เพิ่มห้องเข้า booking ที่สร้างไปแล้ว **เฉพาะเมื่อ booking อยู่ในสถานะ `draft`** ทำให้ user/admin ปรับแต่ง cart ก่อน confirm ได้

### 🎯 การเปลี่ยนแปลงหลัก
- **Route:** `POST /v1/bookings/{bookingId}/rooms` (auth:sanctum + throttle:5,1) — วางใน protected group sibling ของ `createBooking`
- **Method:** `BookingController::addRooms(AddBookingRoomsRequest, $bookingId)` — reuse logic จาก `createBooking` (availability check + pricing + create BR + Addon) แต่ไม่สร้าง Booking ใหม่
- **Request shape:** array `booking_rooms.*` เหมือน `StoreBookingRequest` (ไม่มี `source` เพราะ booking สร้างไปแล้ว)
- **Output:** `{"status":"success","booking_id","added_amount","total_amount","payment_deadline"}` (HTTP 200)

### 🔒 Constraints (state machine)
- **Draft guard:** `if ($booking->status !== 'draft')` → **422** "ไม่สามารถเพิ่มห้องได้ เนื่องจากการจองไม่ได้อยู่ในสถานะ draft ค่ะ" — **core constraint ของ feature**
- **Authorization:** เจ้าของ booking (`user_id === Auth::id()`) **หรือ** admin → ใช้ได้; คนอื่น → **403** (pattern เดียวกับ `showById`)
- **Availability check** เหมือน `createBooking` เป๊ะ — นับ `['draft','confirmed','checked_in']` BRs ที่ overlap + batch overlaps ภายใน request; reject ถ้าเกิน `Room::where('room_type_id',$rtId)->count()`
- **Pricing** อ่านจาก `GlobalRate` server-side เท่านั้น (ป้องกัน price manipulation #20) เหมือน `createBooking`

### 🤔 Decision: ไม่เรียก RoomAllocator / transitionStatus
- **ไม่เรียก RoomAllocator** — draft booking ยังไม่จ่ายห้องจริง (room allocation เกิดตอน `paid`/`confirmed` ผ่าน `autoAssignRooms`) → สร้าง BR ด้วย `room_id = null`
- **ไม่เรียก `transitionStatus()`** — ห้องใหม่ถูกสร้างด้วย `status='draft'` ตรงๆ (initial state ไม่ใช่ transition target) เหมือน `createBooking` บรรทัด 233
- **ไม่เขียน status_change_logs เอง** — ไม่มี transition เกิดขึ้นใน endpoint นี้ → audit log ยังสอดคล้องกับ state machine

### 🤔 Decision: สร้าง AddBookingRoomsRequest ใหม่ (ไม่ใช้ StoreBookingRoomRequest)
- `StoreBookingRoomRequest` + `UpdateBookingRoomRequest` ที่มีอยู่เป็น **flat (single room) + unused scaffold** (grep: zero usages) และไม่มี `addons` rules
- สร้าง `AddBookingRoomsRequest` ใหม่เป็น nested array shape (`booking_rooms.*`) ตรงกับ `createBooking` → reuse validation + Thai messages pattern เดิม ไม่ทำซ้ำ semantics

### 🤔 Decision: existing count รวมห้องใน booking นี้เอง
- availability query ไม่ `where('booking_id', '!=', $booking->id)` exclude — เพราะห้องที่อยู่ใน booking นี้มี `status='draft'` และ overlap อยู่แล้ว นับเข้าไปด้วยถูกต้อง (มันจองไปแล้วจริงๆ)
- ถ้า exclude ออกจะทำให้ over-count available → risk overbooking ข้าม booking

### 📁 Files Changed (1 new + 2 modified + 1 doc)
- `app/Http/Requests/AddBookingRoomsRequest.php` — **(new)** form request nested array + Thai messages
- `app/Http/Controllers/Api/V1/BookingController.php` — เพิ่ม import + method `addRooms()` วางก่อน `createBooking()`
- `routes/api.php` — เพิ่ม `POST /v1/bookings/{bookingId}/rooms`
- `cline.md` — section นี้

### 🗄️ Migration Required
**ไม่ต้อง** — ไม่มี schema change; ใช้ตารางที่มีอยู่ (`bookings`, `booking_rooms`, `addons`, `global_rates`)

### 🧪 Test Results (2026-08-10)
- **198 passed (370 assertions)** — existing suite ไม่พัง (ไม่มี test ใหม่สำหรับ endpoint นี้ใน scope รอบนี้ — ถ้าจะเพิ่ม unit/feature test ให้ตรงกับ `BookingTest` pattern: create draft → add rooms → assert total_amount; add rooms ให้ `paid` booking → assert 422)
- `vendor/bin/pint --dirty` — ผ่าน (3 files)
- `php artisan route:list` — route ลงทะเบียน: `POST api/v1/bookings/{bookingId}/rooms → BookingController@addRooms`

### 🔮 Future Extension (ไม่ใช่ scope รอบนี้)
- ~~**Remove rooms** (delete BR จาก draft booking + recompute total)~~ — ✅ **ทำแล้ว (17/08/26)** ดู section "Draft Deletion + Draft BR Update/Delete" ด้านล่าง
- ~~**Update individual room** (เปลี่ยน room_type/date/addons ของ BR ใน draft) — repurpose `UpdateBookingRoomRequest`~~ — ✅ **ทำแล้ว (17/08/26)** (rewrite เป็น flat `sometimes` rules)
- **Feature test** สำหรับ addRooms (draft/paid/owner/admin/availability-exhausted cases)


## ✅ Draft Booking Deletion + Draft BookingRoom Update/Delete (2026-08-17)

> เพิ่ม 3 endpoints ให้ผู้ใช้จัดการ cart ตอน draft ได้ครบวงจร: ลบ draft booking ทั้งใบ / แก้ไขห้องรายห้อง / ลบห้องออกจาก booking — ปิด gap ที่เคยมีแค่ `addRooms` (เพิ่มได้อย่างเดียว)

### 🎯 Endpoints ใหม่ (ทั้งหมด auth:sanctum + throttle:5,1 + ownership: เจ้าของหรือ admin)
- `DELETE /v1/bookings/{bookingId}` → `BookingController@destroyBooking` — booking ต้องเป็น `draft`
- `PUT /v1/bookings/{bookingId}/rooms/{bookingRoomId}` → `updateRoom` — **BR=draft และ booking=draft**
- `DELETE /v1/bookings/{bookingId}/rooms/{bookingRoomId}` → `destroyRoom` — **BR=draft และ booking=draft**, ห้องสุดท้าย 422

### 🔒 Constraints (state machine)
- **ไม่เพิ่ม state ใหม่** — การลบคือ hard delete ไม่ใช่ transition (ไม่มี `cancelled`/`deleted` state เหมือนเดิม) แต่**เขียน audit log** `draft → deleted` (entity_type `booking`/`booking_room`) ใน `status_change_logs` ก่อนลบ — เก็บ trail แบบ append-only ไว้แม้ row หาย
- **destroyBooking cascade** เหมือน `CleanupExpiredDrafts`: addon ทุกห้อง → BR → payments (frozen) → confirmations (defense-in-depth) → booking — ทำใน `DB::transaction` + `lockForUpdate` booking row + re-check draft (กัน race กับ confirm/verify ที่กำลัง draft→paid)
- **updateRoom**: แก้ได้ทุก field **ยกเว้น** `room_id` (กฎ RoomAllocator) / `status` (กฎ transitionStatus) / `booking_id` — ถ้าแก้ room_type_id/check_in/check_out จะ availability re-check โดย `where('id','!=',$br->id)` **ตัดตัวเองออกจาก count** (ต่างจาก addRooms ที่นับรวม — เพราะที่นี่แก้ห้องเดิม ไม่ใช่เพิ่มใหม่)
- **Repricing ทั้ง server-side**: คิดราคาห้องใหม่จาก `global_rates` (room rate × nights + extra_bed × rate × nights + breakfast + early/late) → update กลับ `addons` row → คำนวณ `total_amount` ของ booking ใหม่ทั้งใบผ่าน helper `recalculateBookingTotal()` (draft อายุ ≤24 ชม. — rate drift ไม่มีนัยสำคัญ)
- **`payment_deadline` คงเดิม** เหมือน addRooms
- **ห้องสุดท้ายลบไม่ได้ (422)** — decision กับนายท่าน: กันเกิด draft เปล่าที่ไปล็อกโควตา "มี draft ค้าง" ของผู้ใช้ (สร้าง booking ใหม่ไม่ได้) — ให้ใช้ `DELETE /bookings/{id}` แทน
- BR ต้องอยู่ใต้ booking ที่ระบุ (`$booking->bookingRooms()->where('id',...)`) — ใส่ BR id ของ booking อื่น = **404** ไม่ใช่ 403 (ไม่ leak การมีอยู่ของ BR)

### 🐛 Bug ที่เจอระหว่างทำ
- **`status_change_logs.role` NOT NULL ชนกับ `$user->role` เป็น null** — `users.role` มี DB default `'user'` แต่ model ที่สร้างผ่าน factory ใน tests ไม่ back-fill default (attribute เป็น null ใน memory) → insert log พัง 500. Fix: `$user->role ?? 'user'` ตอนเขียน log (production ทุก user มี role จริงเสมอ — แค่กันขอบ)

### 📁 Files Changed
- `app/Http/Controllers/Api/V1/BookingController.php` — เพิ่ม `destroyBooking()`, `updateRoom()`, `destroyRoom()` + private `recalculateBookingTotal()`
- `app/Http/Requests/UpdateBookingRoomRequest.php` — **rewrite** จาก unused scaffold เดิม (มี booking_id/room_id) เป็น flat `sometimes` rules + Thai messages
- `routes/api.php` — 3 routes ใหม่ใน protected group
- `tests/Feature/BookingTest.php` — เพิ่ม 17 tests + helper `createDraftBooking()`
- `docs/api_guide.md` — 3 endpoint sections ใหม่ + state machine notes
- `AGENTS.md` — State Machines section อัปเดต

### 🗄️ Migration Required
**ไม่ต้อง** — ไม่มี schema change

### 🧪 Test Results (2026-08-17)
- `php artisan test` — **225 passed (458 assertions)** (BookingTest 30 tests: 17 ใหม่ + 13 เดิม)
- `vendor/bin/pint --dirty` — ผ่าน (4 files, fixed unused import 1 จุด)


## ✅ Batch Update Booking Rooms (2026-08-19)

> `PUT /v1/bookings/{bookingId}/rooms` — แก้ไข booking room **หลายห้องพร้อมกัน** (payload ต่างกันได้รายห้อง, all-or-nothing) — นายท่านเลือก design ทาง B (per-room payload) จาก 2 ทางที่เสนอ (A = payload เดียว apply ทุกห้อง / B = array `rooms[]` ระบุ `booking_room_id` รายแถว)

### 🎯 Endpoint ใหม่ (auth:sanctum + throttle:5,1 + ownership: เจ้าของหรือ admin)
- `PUT /v1/bookings/{bookingId}/rooms` → `BookingController@updateRooms` — **BR ทุกห้องใน batch=draft และ booking=draft**
- Route รายห้องเดิม `PUT .../rooms/{bookingRoomId}` **ยังอยู่ครบถ้วน** (backward compat — ไม่แตะ)

### 🔒 Constraints / การตัดสินใจสำคัญ
- **All-or-nothing** — pre-flight ทุกห้อง (ต้องเป็นของ booking นี้ + draft) ก่อนเขียนอะไร; ห้องใด fail ระหว่าง transaction = rollback ทั้งชุด (422) ; มี BR id ของ booking อื่นสักอัน = 404 ทั้ง batch
- **`booking_room_id` ซ้ำใน batch ไม่ได้** — validation `distinct` (422)
- **Availability check จาก final state ของทั้ง batch** (จุดที่ delicate ที่สุด):
  - existing count ใช้ `whereNotIn('id', $batchIds)` — ตัด **ทุกห้องใน batch** ออก (ต่างจาก updateRoom ที่ตัดตัวเดียว) เพราะห้องเหล่านี้มีอยู่แล้วเป็น draft rows
  - batch overlap นับจากค่า final ของ **ทุกห้องใน batch รวมห้องที่ไม่ได้เปลี่ยน shape** (guests-only) — ถ้านับเฉพาะ shape-changed ห้อง unchanged ที่ยังยึดพื้นที่อยู่จะหายไปจากการนับ → overbook (มี test กันไว้: `test_batch_update_rejects_when_batch_overbooks_type`)
  - ตรวจเฉพาะห้อง shape-changed (ส่ง room_type_id/check_in/check_out) — ห้อง unchanged ผ่านมาแล้วโดย invariant (occupancy ของมันไม่เพิ่ม)
- **🐛 Gap ที่เจอ: validation `after:rooms.*.check_in` ผ่านเงียบๆ เมื่อไม่ส่ง check_in มาด้วย** (verify ด้วย tinker — เช่นส่งแต่ check_out ย้อนหลัง หรือส่งแต่ check_in ทับ check_out เดิม) → เพิ่ม **effective-dates guard ใน controller**: `check_out ≤ check_in` จากค่า final (ใหม่ถ้าส่ง ไม่งั้นเดิม) = 422 (มี test กันไว้) — หมายเหตุ: updateRoom รายห้องเดิมมี gap เดียวกันนี้กับ `after:check_in` แต่ไม่ได้แตะใน session นี้
- Repricing server-side รายห้อง (เหมือน updateRoom) + `recalculateBookingTotal()` **ครั้งเดียว**ท้าย transaction · `payment_deadline` คงเดิม · ไม่มี state transition ใหม่ / ไม่แตะ audit trail
- Response: `booking_rooms` array เรียงตามลำดับ `rooms` ใน request + `total_amount`

### 📁 Files Changed
- `app/Http/Requests/UpdateBookingRoomsRequest.php` — **สร้างใหม่** (array `rooms[]` + `booking_room_id` required/uuid/distinct + flat rules เหมือน UpdateBookingRoomRequest ทุก field)
- `app/Http/Controllers/Api/V1/BookingController.php` — เพิ่ม `updateRooms()` (ไม่แตะ `updateRoom()` เดิม)
- `routes/api.php` — route ใหม่ใต้ throttle:5,1 (ไม่ชน route รายห้องเพราะไม่มี segment ที่สาม)
- `tests/Feature/BookingTest.php` — เพิ่ม 7 tests (happy path 2 ห้องต่าง field กัน / id ซ้ำ 422 / id ต่าง booking 404 atomic / ห้องไม่ draft 422 atomic / overbook final-state / วันที่กลับข้าง 422 / ส่งเดี่ยว check_out ก่อน check_in เดิม 422)
- `test_scripts/test_draft_ops_remote.php` — เพิ่ม section 3.5 (batch 200 + total, id ซ้ำ 422, id แปลกปลอม 404, ไม่มี token 401, restore สถานะเดิมให้ section 4 ทำงานเหมือนเดิม)
- `docs/api_guide.md` — section ใหม่ระหว่าง PUT รายห้องกับ DELETE

### 🗄️ Migration Required
**ไม่ต้อง** — ไม่มี schema change

### 🧪 Test Results (2026-08-19)
- `php artisan test` — **232 passed (480 assertions)** (BookingTest 37 tests: 7 ใหม่ + 30 เดิม)
- `vendor/bin/pint --dirty` — ผ่าน (fixed style 1 จุดใน test script)


## ✅ ลบ payment_method ทุก flow + ปลด freeze payments (2026-08-19)

> **Flow การชำระเงินเหลือ "ส่งสลิป → รอแอดมินตรวจ" อย่างเดียว** — นายท่านสั่งลบ `payment_method` ออกจากทั้ง flow ใหม่ (`booking_confirmations`) และ legacy (`payments`) พร้อมปลด freeze ตาราง payments
>
> **Decisions (นายท่านเลือก):** ลบทั้งสองฝั่ง + unfreeze payments · `transfer_time` เป็น **optional** (`nullable|date|before_or_equal:now`) · `slip_image` บังคับเสมอ · `receipts` ยัง frozen · webhook ยัง 410

### 🎯 การเปลี่ยนแปลงหลัก

| ส่วน | เดิม | ใหม่ |
|---|---|---|
| `POST /bookings/{id}/confirm` | `payment_method` required (cash/credit_card/transfer) + slip/time เฉพาะ transfer | ลบ method · `slip_image` required เสมอ · `transfer_time` optional |
| `booking_confirmations` table | มี `payment_method` nullable | **drop column** |
| `payments` table (🔓 unfrozen) | มี `payment_method` NOT NULL | **drop column** |
| `POST /payments` + `POST /front-desk/{id}/payment` | ต้องส่ง `payment_method` | ไม่รับ/ไม่เก็บแล้ว (ส่งมาก็ถูก ignore) |
| Response ที่ serialize model | มี `payment_method` | หายไป (breaking — frontend `ku-home` ต้องเลิกอ่าน field นี้) |

### 📁 Files Changed
- Migration: ✨ `2026_08_19_100000_drop_payment_method_columns` (drop ทั้งสองตาราง, down() คืนเป็น nullable)
- Requests: ✏️ `ConfirmBookingRequest` (ลบ required_if ทั้งหมด) · `StorePaymentRequest` · `UpdatePaymentRequest`
- Controllers: ✏️ `BookingConfirmationController::confirm` (store slip ตรงๆ ไม่ต้อง hasFile guard) · `PaymentController::requestPayment` · `FrontDeskController::recordPayment`
- Models: ✏️ `BookingConfirmation` · `Payment` (ลบจาก fillable)
- Tests: ✏️ `BookingConfirmationTest` (rename `test_owner_can_submit_confirmation_with_slip` · `test_cash_payment_does_not_require_slip` → `test_transfer_time_is_optional` · `test_transfer_requires_slip_image` → `test_confirm_requires_slip_image`) · `PaymentTest` · `FrontDeskTest`
- Scripts: ✏️ `test_scripts/api_test_chain.php`, `api_test_remote.php`, `api_guide.php`
- Docs: ✏️ `api_guide.md` · `booking-verify-flow.md` · `database-er.md` · `AGENTS.md` (Frozen section)

### 🗄️ Migration Required
⚠️ **ต้องรัน `php artisan migrate`** — drop column `payment_method` จาก `booking_confirmations` + `payments` (ข้อมูลใน column นี้หายถาวร — ตั้งใจ)

### 🧪 Test Results (2026-08-19)
- `php artisan migrate` — ผ่าน (drop column ทั้งสองตาราง)
- `php artisan test` — **232 passed (481 assertions)** (BookingConfirmationTest 19 · PaymentTest 3 · FrontDeskTest 14 ผ่านครบ)
- `vendor/bin/pint --dirty` — ผ่าน (fixed 9 style issues รวม pre-existing ในไฟล์ที่แตะ)

---

## ✅ ระบบรูปจริงจัง: private disk + images table + signed URL (2026-08-19 #2)

> **สลิปเลิกเก็บบน public disk + path column แล้ว** — ย้ายมาเป็นระบบรูปกลางที่ปลอดภัยและขยายได้
>
> **Decisions (นายท่านเลือก):** Disk + `images` table polymorphic (ไม่เอา BLOB ใน DB — เหตุผล: มือใหม่ฝั่ง server + deploy บน company server, DB ต้องเบา, ย้ายไป S3/Supabase ภายหลังได้โดยไม่แก้ schema) · สลิปเป็น **private + ดูผ่าน signed URL อายุ 15 นาที** (frontend `<img src>` ตรงๆ ได้) · ขอบเขตแบบเต็ม (ถอด draft `/upload-image`) · มี cleanup

### 🎯 การเปลี่ยนแปลงหลัก

| ส่วน | เดิม | ใหม่ |
|---|---|---|
| ที่เก็บไฟล์สลิป | `public` disk (`storage/app/public/slips/` — URL ถาวรใครมีลิงก์ก็เห็น) | **`local` private disk** (`storage/app/private/slips/` — เว็บเปิดตรงๆ ไม่ได้) |
| DB | path string ใน `booking_confirmations.slip_image` | **drop column** — สลิป 1 ใบ = 1 row ใน `images` (morph `slipImage()`) |
| `images` table | 🚧 draft (`url` + morph หลวม, ไม่มี index) | ของจริง: `path`/`disk`/`mime_type`/`size`/`original_name`/`uploaded_by` + morph index |
| ดูรูป | `Storage::url()` = URL ถาวร | **`GET /api/v1/images/{id}/file`** + signed URL อายุ 15 นาที (ออกให้เฉพาะ response ของเจ้าของ/admin) |
| `POST /upload-image` | 🚧 draft **ไม่มี auth** ใครก็อัปได้ | **ถอดออก** (route + `upload()` + `StoreImageRequest`) |
| ตอน booking ถูก hard-delete | ไฟล์สลิปตาย (path ค้างใน row ที่ cascade หาย) | `destroyBooking` + `CleanupExpiredDrafts` ลบ Image row ก่อน (hook ลบไฟล์ให้) |
| Cleanup | ไม่มี (ไฟล์สะสม) | **`app:cleanup-images`** รอบ 02:30: ไฟล์กำพร้า → ลบ · row กำพร้า → ลบ · rejected เกิน `SLIP_RETENTION_DAYS` (default 30) → ลบรูปคง confirmation · **verified ห้ามลบ** (หลักฐานการเงิน) |

### 🔐 RequireJsonAccept exemption (จุดเดียวในระบบ — บันทึกตามกติกา AGENTS.md)
`GET /images/{id}/file` ใช้ `->withoutMiddleware([RequireJsonAccept::class])` เพราะ browser `<img>` ส่ง `Accept: image/avif,image/webp,...` (ไม่มี JSON/wildcard) — ไม่ exempt แล้วรูปทุกใบโดน 406 ตอน frontend ฝัง tag · route ยังมี `signed` + `throttle:10,1` คุมอยู่ · ผ่าน test `test_signed_url_accepts_image_accept_header` กัน regression

### 🧠 พฤติกรรมสำคัญที่ต้องจำ
- **`Image` model**: serialize แล้วมี appended `url` (signed URL สดทุกครั้ง) · **ลบ row = ลบไฟล์อัตโนมัติ** (hook `deleting`) — call site ไหนลบ Image ไม่ต้องลบไฟล์เอง
- `confirm()` เก็บไฟล์ใน transaction แต่ไฟล์ไม่ transactional → catch มี best-effort delete + command 02:30 เก็บรอยสอดคล้องอีกชั้น
- ไม่มี generic upload endpoint โดยตั้งใจ — รูปเกิดจาก flow ของเจ้าของเสมอ (วันนี้คือ confirm) เพื่อกันอัปโหลดอิสระแบบ draft เดิม
- **ทางไปต่อ:** `HousekeepingPhoto` (draft ลอย — มี model/migration/requests แต่ไม่มี route) ควรย้ายมาใช้ `images` table (morph `HousekeepingTask`) แล้ว drop ตาราง `housekeeping_photos` — ยังไม่ทำในรอบนี้

### 📁 Files Changed
- Migrations: ✨ `2026_08_19_110000_reshape_images_table` (drop+recreate — ตารางเดิมเป็น draft ไม่มีข้อมูล production) · `2026_08_19_110100_drop_slip_image_from_booking_confirmations`
- Models: ✏️ `Image` (เขียนใหม่หมด) · `BookingConfirmation` (ลบ fillable `slip_image` + เพิ่ม `slipImage()` morphOne)
- Controllers: ✏️ `BookingConfirmationController` (store local + สร้าง Image + eager `slipImage` ใน verify/reject/pending) · `ImageController` (เขียนใหม่: `show()` stream ไฟล์) · `BookingController::destroyBooking` (ลบรูปก่อน cascade)
- Routes: ✏️ `api.php` (ลบ `/upload-image` · เพิ่ม `images.file` signed) · `console.php` (schedule 02:30)
- Commands: ✨ `CleanupImages` · ✏️ `CleanupExpiredDrafts` (ลบรูปก่อนลบ booking)
- Requests: 🗑️ `StoreImageRequest`
- Tests: ✏️ `BookingConfirmationTest` (fake `local` disk + อ้าง morph แทน column) · ✏️ `BookingTest` (แก้ flaky `rand()` room_number → counter) · ✨ `ImageTest` · ✨ `CleanupImagesTest`
- Docs: ✏️ `api_guide.md` · `cline.md` (ไฟล์นี้) · `AGENTS.md`

### 🗄️ Migration Required
⚠️ **ต้องรัน `php artisan migrate`** (หรือ `migrate:fresh --seed` บน dev): ตาราง `images` ถูกสร้างใหม่ (ข้อมูล draft เดิมหาย — ตั้งใจ) + **drop column `booking_confirmations.slip_image`** (path เดิมอ่านไม่ได้อีก — dev-only) · สลิปเก่าใน `storage/app/public/slips/` ตัดทิ้งได้ด้วยมือ

### 🧪 Test Results (2026-08-19)
- `php artisan test` — **256 passed (553 assertions)** (BookingConfirmationTest 18 · ImageTest 7 · CleanupImagesTest 7 ผ่านครบ)
- `vendor/bin/pint --dirty` — ผ่าน (fixed 5 style issues)
- `php artisan migrate` (dev SQLite) — ผ่านทั้ง 2 migration


## ✅ Standardize PUT /bookings/{bookingId}/rooms payload key to `booking_rooms` (2026-08-19)

> **Standardize payload key** — เปลี่ยน head key ของ batch room update จาก `rooms` เป็น `booking_rooms` ให้สอดคล้องกับ `POST /bookings` และ `POST /bookings/{bookingId}/rooms`
>
> **Files Changed:**
> - `app/Http/Requests/UpdateBookingRoomsRequest.php` (`rooms` → `booking_rooms`)
> - `app/Http/Controllers/Api/V1/BookingController.php` (`$validated['rooms']` → `$validated['booking_rooms']`)
> - `tests/Feature/BookingTest.php` (update batch tests)
> - `test_scripts/test_draft_ops_remote.php` (section 3.5)
> - `docs/api_guide.md` & `AGENTS.md`

## ✅ Smart DB Diffing & Shape Checking for Booking Room Updates (2026-08-20)

> **Optimize room updates** — เพิ่ม logic "Smart DB Diffing" ในทั้ง `updateRoom` (single) และ `updateRooms` (batch) เพื่อป้องกันการเช็ค availability ที่หนักและป้องกันคำสั่ง SQL UPDATE ที่ซ้ำซ้อน
> 
> **ปัญหาเดิม:** การกดบันทึกโดยไม่เปลี่ยนข้อมูลอะไรเลย (เช่น กด save ห้องเดิม) ทำให้เกิด availability overlap check ที่ต้องดึงข้อมูลห้องเยอะมาก และ update timestamp ใน DB แม้ข้อมูลไม่เปลี่ยน
> 
> **สิ่งที่แก้ไข:**
> - เทียบ `$request` กับ current DB state ของ BookingRoom และ Addon (มีการ cast date และ bool เพื่อเปรียบเทียบ type ให้ตรงกัน)
> - **Shape change detection**: จะเช็ค availability/overlap ก็ต่อเมื่อมีการเปลี่ยน `room_type_id`, `check_in`, หรือ `check_out` จริงๆ
> - **Update prevention**: จะส่ง array ให้ Eloquent `update()` ก็ต่อเมื่อข้อมูลต่างจาก DB 
> - ปรับ payload `PUT /bookings/{bookingId}/rooms` (batch) ให้ return relation กลับมาครบ (`addon`, `roomType`, `room`) เหมือน endpoint เดี่ยว
> 
> **Files Changed:**
> - `app/Http/Controllers/Api/V1/BookingController.php` (updateRoom, updateRooms)
> - `tests/Feature/BookingTest.php` (เพิ่ม 2 tests สำหรับเช็ค smart diffing และ payload shape)
> - `docs/api_guide.md` (เพิ่ม note ⚡ Smart Diffing)

## 🚧 Paused Rate Limiting (Throttle) on Booking Creation & Add Rooms Endpoints (2026-08-20)

> **Pause throttle for development/testing** — ปลด middleware `throttle:5,1` ชั่วคราวออกจาก:
> - `POST /api/v1/bookings`
> - `POST /api/v1/bookings/{bookingId}/rooms`
>
> **Files Changed:**
> - `routes/api.php` (remove `throttle:5,1` on both routes + comment marker)
> - `tests/Feature/BookingTest.php` (update `test_create_booking_route_has_rate_limiting`)

## ✅ Return `booking_rooms` in Create Booking Response & Support Guest `firstName`/`lastName`/`email`/`phone` (2026-08-20)

> **Response & Schema Improvements** — ปรับปรุงการจองห้องพัก 2 ส่วนหลัก:
> 1. `POST /api/v1/bookings` (และ `POST /api/v1/bookings/{id}/rooms`) ส่งคืน `booking_rooms` array พร้อม eager loaded relations (`addon`, `room_type`, `room`) และ `confirmation` ใน response ทันที (ไม่ต้องยิง `GET /bookings/{id}` ซ้ำ)
> 2. แยกชื่อผู้เข้าพัก `guests.*` เป็น `firstName` และ `lastName` (รองรับทั้ง camelCase และ snake_case `first_name`/`last_name` พร้อม legacy `name` fallback) + เพิ่ม optional `email` และ `phone`
>
> **Files Changed:**
> - `app/Http/Requests/StoreBookingRequest.php`, `AddBookingRoomsRequest.php`, `UpdateBookingRoomRequest.php`, `UpdateBookingRoomsRequest.php`, `StoreBookingRoomRequest.php`
> - `app/Http/Controllers/Api/V1/BookingController.php` (`createBooking`, `addRooms`, `applyUserFilter`)
> - `app/Http/Controllers/Api/V1/FrontDeskController.php` (walk-in guest validation)
> - `app/Models/BookingRoom.php` (`getPrimaryGuestNameAttribute` accessors)
> - `tests/Feature/BookingTest.php`
> - `test_scripts/test_create_booking_remote.php` (remote live domain test script)
> - `docs/api_guide.md` & `cline.md`
>
> **Testing:**
> - PHPUnit: `259 passed (610 assertions)`
## ✅ Remove `guests.*.is_ku_member` + Drop `booking_rooms.has_children` (2026-08-21)

> **Schema Cleanup & Simplification** — ลบฟิลด์ที่ไม่จำเป็นออกจากการจองห้องพัก:
> 1. **`guests.*.is_ku_member`**: ลบ validation rules ออกจากทุก FormRequest และ Controller พร้อมเพิ่ม `stripGuestFields()` เพื่อ strip `is_ku_member` ออกจาก guests JSON ก่อนบันทึกหรือทำ dirty diffing (หาก client เก่าส่งมาจะถูก ignore เงียบๆ) — **คง `users.is_ku_member` ระดับ User ไว้ตามเดิม**
> 2. **`booking_rooms.has_children`**: ลบ validation rules, Model `$fillable` และ `$casts` (`PgBoolean`), และสร้าง migration drop column `has_children` ออกจากตาราง `booking_rooms`
> 3. **`addons.breakfast`**: คงไว้ทั้งหมด (nullable/optional) ตามเดิม
>
> **Files Changed:**
> - `app/Http/Requests/StoreBookingRequest.php`, `AddBookingRoomsRequest.php`, `StoreBookingRoomRequest.php`, `UpdateBookingRoomRequest.php`, `UpdateBookingRoomsRequest.php`
> - `app/Http/Controllers/Api/V1/BookingController.php`, `FrontDeskController.php`
> - `app/Models/BookingRoom.php`
> - `database/migrations/2026_08_21_100000_drop_has_children_from_booking_rooms_table.php` (New migration)
> - `tests/Feature/BookingTest.php`, `FrontDeskTest.php`, `StatusChangeLogTest.php`, `tests/Unit/BookingStateTest.php`, `tests/Unit/RoomAllocator/RoomAllocatorIntegrationTest.php`
> - `docs/api_guide.md`, `docs/booking-verify-flow.md`, `docs/database-er.md`
> - `postman/KU_HOME_API.postman_collection.json`
> - `test_scripts/api_guide.php`, `api_test_chain.php`, `api_test_remote.php`, `test_create_booking_remote.php`, `test_batch_rooms_multi_remote.php`, `test_draft_ops_remote.php`
>
> **Testing:**
> - PHPUnit: Full suite `259 passed (609 assertions)`


## ✅ Refactor `/unavailable-dates` เป็นราย room type (2026-08-23)

> **Breaking Change (response shape)** — `GET /v1/unavailable-dates` เดิมคืน flat list `unavailable_dates` วันที่ **ทุก room type เต็มพร้อมกัน** (รวมทุกประเภทเป็น list เดียว) → ตอนนี้คืน **แยกราย room type** ผ่าน `room_types[]` เหมือน `/availability-per-day` และ `/availability-ranges`
>
> **Response ใหม่:**
> ```
> room_types: [
>   { room_type_id, name_en, name_th, unavailable_dates: [Y-m-d, ...] }
> ]
> ```
> - sold-out logic ต่อ type = `total_rooms > 0 && occupied >= total_rooms` — เหมือน `availabilityRanges` เป๊ะ (type ไม่มีห้องขาย `total=0` → `unavailable_dates: []` ไม่ถือว่า sold-out ทุกวัน)
> - ลบ degenerate guard เดิม ("ไม่มี room type / sum(total)=0 → คืน `[]`") — per-type logic + เงื่อนไข `total > 0` จัดการกรณีนี้เองตามธรรมชาติ
> - ถ้า frontend อยากได้พฤติกรรมเดิม (วันที่จองไม่ได้เลยทุกประเภท) → intersect `unavailable_dates` ของทุก type ฝั่ง client
> - validation (`start_date`/`end_date` required) + DoS guard (cap 366 คืน) + occupied matrix approach คงเดิมทุกอย่าง
>
> **Files Changed:**
> - `app/Http/Controllers/Api/V1/RoomController.php` — rewrite `unavailableDates()` (comment header อธิบาย diff กับ `availabilityRanges`)
> - `tests/Feature/RoomTest.php` — rewrite 2 tests เดิมเป็น per-type + เพิ่ม `test_unavailable_dates_type_with_no_sellable_rooms_is_never_sold_out`
> - `docs/api_guide.md` — อัปเดต section `/unavailable-dates` + ระบุ BREAKING
> - `postman/KU_HOME_API.postman_collection.json` — rename entry "Flat" → "Per-Type"
>
> **Testing:**
> - `php artisan test --filter=RoomTest` — `19 passed (60 assertions)` (รวม unavailable-dates 4 tests)

## ✅ Mock Endpoint + Default Window (today → +6 เดือน) สำหรับ Availability Endpoints (2026-08-24)

> **Feature & DX Improvement** — เพิ่ม mock sold-out intervals endpoint สำหรับ frontend testing และปรับ default date window สำหรับ 3 availability endpoints:
> 1. **`GET /api/v1/mock/availability-ranges` (Mock endpoint):**
>    - สร้าง `MockController@availabilityRanges` พร้อม `🚧 DRAFT / TESTING`
>    - ไม่อ่านข้อมูล DB — วันที่คำนวณสัมพันธ์กับ `Carbon::today()` (ไม่มีวันหมดอายุ, window = today → today+30):
>      - **Superior** (fake UUID `...0001`): 2 intervals (`[today+5, today+8]`, `[today+15, today+18]`)
>      - **Deluxe** (fake UUID `...0002`): 1 interval (`[today+10, today+12]`)
>      - **Suite** (fake UUID `...0003`): 0 intervals (`[]`)
>    - Response shape & message ตรงกับ endpoint จริงทุกประการ
>    - 🗑️ **Deletion plan (ตัดสินใจแบบ doc-only — ไม่ใส่ env gate, 24/08/26):** ลบ endpoint นี้ (controller + route + tests + docs) เมื่อ frontend ย้ายไปใช้ `/availability-ranges` จริง — **อย่าปล่อยขึ้น production**
> 2. **Default Window `today → today+6 เดือน` เมื่อไม่ส่ง params:**
>    - แก้ไข 3 endpoints ใน `RoomController`: `/availability-per-day`, `/availability-ranges`, `/unavailable-dates`
>    - ใช้ `$request->mergeIfMissing(['start_date' => today])` และ `$request->mergeIfMissing(['end_date' => start + 6 เดือน])` ก่อน validation
>    - หากส่ง `start_date` อย่างเดียว → `end_date` default เป็น `start_date + 6 เดือน`
>    - Validation rules & 422 error shape คงเดิมทุกกรณี
>
> **Files Changed:**
> - `app/Http/Controllers/Api/V1/MockController.php` (New controller)
> - `app/Http/Controllers/Api/V1/RoomController.php` (`availabilityPerDay`, `availabilityRanges`, `unavailableDates`)
> - `routes/api.php` (Register mock route)
> - `tests/Feature/RoomTest.php` (Update default window tests + add mock endpoint test)
> - `docs/api_guide.md` & `cline.md`
>
> **Testing:**
> - `php artisan test --filter=RoomTest` — `22 passed (81 assertions)`
> - Full PHPUnit suite: `263 passed (634 assertions)`

## ✅ Booking Container เพิ่ม state `pending` ก่อน `paid` (2026-08-25)

> **State Machine Change** — booking container มี state `pending` แล้ว mirror กับ `BookingConfirmation` flow:
> ก่อนหน้านี้ user ส่งสลิป = booking `draft → paid` + `is_paid=true` **ทันทีทั้งที่ยังไม่มีใครตรวจ** — ทำให้ 'paid' โกหก (และถ้า admin reject สลิป booking จะค้าง `paid` ตลอดไป = dead-end)

**Flow ใหม่:**
1. user `POST /bookings/{id}/confirm` → confirmation `pending` + booking `draft → pending` (ยังไม่ set is_paid)
2. admin verify → confirmation `verified` + booking `pending → paid → confirmed` + `is_paid=true` (ทำใน transaction เดียว)
3. admin reject → confirmation `rejected` + booking `pending → draft` (กลับ draft ให้ user ส่งสลิปใหม่ = row ใหม่)

**Transition map ที่เปลี่ยน (`Booking::transitionStatus()`):**
- เพิ่ม: `draft → pending` (user, guest, admin) · `pending → paid` (admin) · `pending → draft` (admin)
- **ตัด `user`/`guest` ออกจาก `draft → paid`** — เหลือ admin, system เท่านั้น (เงินสดหน้าเคาน์เตอร์ FrontDesk / webhook อนาคต) — user ต้องผ่าน pending เสมอ

**จุดที่ไม่เปลี่ยน (ตรวจสอบแล้ว):**
- Availability queries ทั้งหมด (`draft,confirmed,checked_in`) เช็คที่ **BR-level** status — BR state machine ไม่กระทบ
- `CleanupExpiredDrafts` ยังลบเฉพาะ `draft` — **pending ที่หมด deadline ไม่ถูกลบ** (เงินอาจโอนแล้ว รอ admin ตรวจ; reject แล้วกลับ draft จะถูกเก็บเอง)
- `PUT /bookings/update/{id}` validation `in:draft,paid,confirmed,complete` — ไม่เพิ่ม `pending` (pending เกิดจาก slip submission เท่านั้น)
- แก้/ลบ booking rooms ยังจำกัดเฉพาะ `draft` — booking ระหว่าง `pending` แก้ไม่ได้ (สลิกกำลังรอตรวจ)
- FrontDesk `recordPayment` `draft → paid` (role admin) ยังใช้ได้ตามเดิม

**Legacy data:** ก่อน deploy ถ้ามี booking ค้าง `paid` ที่ confirmation ยัง `pending` — verify ยังทำงาน (branch `if status === 'paid'` เก็บไว้ → confirmed ได้เลย)

**Files Changed:**
- `app/Models/Booking.php` (transitionStatus map + docblock)
- `app/Http/Controllers/Api/V1/BookingConfirmationController.php` (confirm/verify/reject + is_paid ย้ายไป set ตอน verify)
- `tests/Unit/BookingStateTest.php` (pending transitions + user draft→paid ต้อง fail แล้ว)
- `tests/Feature/BookingConfirmationTest.php` (assert ใหม่ + full-flow test)
- `tests/Feature/StatusChangeLogTest.php` (draft→paid by user → draft→pending by user)
- `docs/api_guide.md`, `AGENTS.md`, `cline.md`

**Testing:**
- Full PHPUnit suite: `270 passed (650 assertions)`

## ✅ Booking Container เพิ่ม state `verify_error` แทนการกลับ `draft` เมื่อ admin reject สลิป (2026-08-25)

> **State Machine Refinement** — เมื่อ admin reject สลิป (`PUT /booking-confirmations/{id}/reject`) booking จะเปลี่ยนเป็น `verify_error` แทนการกลับเป็น `draft`
> เพื่อแยกแยะสถานะ "สลิปมีปัญหา/ไม่ผ่าน" ออกจาก "การจองใหม่ที่ยังไม่เคยส่งสลิป"

**Flow:**
1. user `POST /bookings/{id}/confirm` → confirmation `pending` + booking `draft → pending` (หรือ `verify_error → pending`)
2. admin verify → confirmation `verified` + booking `pending → paid → confirmed` + `is_paid=true`
3. admin reject → confirmation `rejected` + booking `pending → verify_error` (รอ user ส่งสลิปใหม่)
4. user re-submit → booking `verify_error → pending` (ส่งใหม่ได้เสมอแม้ payment_deadline ผ่านไปแล้ว)

**จุดสำคัญ:**
- `verify_error` **ไม่ถูก `CleanupExpiredDrafts` ลบ** (ห้องยังถูก hold ไว้ตาม availability และรอ user ส่งสลิปใหม่เมื่อไหร่ก็ได้)
- `POST /bookings/{id}/confirm` ตรวจ `payment_deadline` เฉพาะสถานะ `draft` เท่านั้น — สถานะ `verify_error` ได้รับการยกเว้นให้ส่งใหม่ได้ตลอด
- ทางออกเดียวของ `verify_error` คือการส่งสลิปใหม่ (`verify_error → pending`)

**Files Changed:**
- `app/Models/Booking.php` (`transitionStatus` validTransitions map + docblock)
- `app/Http/Controllers/Api/V1/BookingConfirmationController.php` (confirm guard รับ `['draft', 'verify_error']` + skip deadline guard สำหรับ `verify_error` + reject transition ไป `verify_error`)
- `tests/Unit/BookingStateTest.php` (test valid `pending → verify_error`, `verify_error → pending`, and invalid transition guards)
- `tests/Feature/BookingConfirmationTest.php` (assert reject gives `verify_error`, re-submit from `verify_error`, and re-submit after deadline expired)
- `docs/api_guide.md`, `AGENTS.md`, `docs/booking-verify-flow.md`, `docs/database-er.md`, `postman/KU_HOME_API.postman_collection.json`, `cline.md`

**Testing:**
- Full PHPUnit suite: `278 passed (662 assertions)`


## ✅ Addon early check-in / late check-out: default rate 100 THB + response booleans + late_checkOut typo family fix (2026-08-26)

> **3 เรื่องใน task เดียว** — ปรับ default rate, เพิ่ม boolean ใน response ให้ format เหมือนตอน create, และคุม booking-room format ให้เหมือนกันทุก endpoint
> (พบบั๊กพันธุ์เดียวกันซ้อนอยู่ 3 จุด แก้ครบในรอบเดียว)

**1) Default rate ปรับลด:**
- `early_checkin` / `late_checkout`: 30000 → **10000 satang (100 THB)** ทั้งคู่
- แก้ทั้ง `GlobalRateSeeder` + data migration `2026_08_26_100000_update_early_late_addon_default_rates.php` (DB เดิมไม่ต้อง fresh reseed; down คืน 30000)
- ไม่แต้ `addons` rows เดิมราย booking (นโยบาย freeze ราคาตอนคิดแล้ว)

**2) Response booleans (format เหมือนตอน create):**
- `Addon::$appends = ['early_checkin', 'late_checkout']` + accessors derive จาก `*_price > 0`
- ทุก API ที่ serialize addon (create/add/update/batch-update/getBookings/showById/assign-rooms) ได้ boolean ชื่อ field เดียวกับ input `addons.early_checkin` / `addons.late_checkout` อัตโนมัติ

**3) Booking-room format เดียวกันทุก API:**
- `autoAssignRooms` เดิม load แค่ `bookingRooms.room` → ตอนนี้ `addon` + `roomType` + `room` ครบเหมือน endpoint อื่นทุกตัว

**🐞 Bug fix — `lateCheckOut_price` typo family (พบระหว่างเขียน regression test):**
- Column จริงคือ `late_checkOut_price` (c เล็ก) แต่โค้ดเขียน `lateCheckOut_price` (C ใหญ่) 3 จุดใน `BookingController`:
  1. `updateRoom`/`updateRooms` fallback เวลาไม่ส่ง `addons` key → อ่านไม่เจอ → **late checkout ถูก reprice เป็น 0 เงียบๆ**
  2. `$addonData` array key ผิด → dirty-check เทียบของผิด → **แก้ราคา late checkout ผ่าน update ห้องไม่ได้เลยตลอดมา**
  3. `recalculateBookingTotal()` → **ราคา late checkout หายจาก total_amount ทุกครั้งที่คิดยอดใหม่** (บั๊กเงินจริง)
- แก้ทั้ง 3 จุดเป็น `late_checkOut_price`

**Files Changed:**
- `database/seeders/GlobalRateSeeder.php` (rate 10000 ทั้งคู่)
- `database/migrations/2026_08_26_100000_update_early_late_addon_default_rates.php` (ใหม่ — data migration)
- `app/Models/Addon.php` ($appends + accessors)
- `app/Http/Controllers/Api/V1/BookingController.php` (typo 3 จุด + autoAssignRooms relations)
- `tests/Feature/BookingTest.php` (response booleans + regression ไม่ส่ง addons ต้องคงราคา + เปิด/ปิดผ่าน addons ต้องเขียนได้)
- `tests/Feature/GlobalRateSeederTest.php` (ใหม่ — ตรวจค่า seed)
- `docs/api_guide.md`, `cline.md`

**Testing:**
- Full PHPUnit suite: `287 passed (696 assertions)` (เดิม 285 — เพิ่ม 3 tests ใหม่ ลบ 1 รวม)


## ✅ Booking Room JSON format refactor across all APIs (2026-08-26)

> **Refactor Booking Room JSON response format across all endpoints** — frontend ใช้ `room_type_id` / `room_id` lookup ข้อมูลห้องเองโดยตรง จึงตัด `room_type` / `room` nested object ออก และย้าย `early_checkin` / `late_checkout` boolean ขึ้นมาอยู่ที่ระดับ `booking_room`

**1)ย้าย boolean early_checkin / late_checkout ขึ้นระดับ booking_room:**
- `BookingRoom::$appends = ['early_checkin', 'late_checkout']` + accessors `getEarlyCheckinAttribute()` / `getLateCheckoutAttribute()` derive จาก `$this->addon?->early_checkIn_price > 0` และ `$this->addon?->late_checkOut_price > 0`
- `Addon` ถอด `$appends` + accessors ออก (เก็บเฉพาะ field ราคาและจำนวน)

**2) ซ่อน room_type / room relations จาก JSON serialization:**
- `BookingRoom::$hidden = ['roomType', 'room']` ป้องกันการหลุดของ relation object ในทุก response
- `BookingController` trim eager loading 7 จุด (`getBookings`, `addRooms`, `updateRoom`, `updateRooms`, `createBooking`, `showById`, `autoAssignRooms`) ให้โหลดเฉพาะ `bookingRooms.addon` (คง `recalculateBookingTotal` ที่ใช้ `$br->roomType` ภายใน)

**Files Changed:**
- `app/Models/BookingRoom.php` ($appends, $hidden, accessors)
- `app/Models/Addon.php` (ลบ $appends และ accessors)
- `app/Http/Controllers/Api/V1/BookingController.php` (trim eager loading 7 จุด)
- `tests/Feature/BookingTest.php` (อัปเดต 4 tests: `test_authenticated_user_can_create_booking`, `test_create_booking_returns_early_late_boolean_addons`, `test_update_room_without_addons_key_keeps_early_late_prices`, `test_batch_update_booking_rooms_returns_mutated_rooms`)
- `test_scripts/api_guide.php` (step 8b ใช้ `room_id` ตรงๆ)
- `test_scripts/test_create_booking_remote.php` (เช็ค `room_type_id` + booleans)
- `docs/api_guide.md` (อัปเดต 7 response examples + schema tables)
- `cline.md`


## ✅ Implement Discount System v2.1 (2026-08-27)

> **Implement Discount System v2.1** — ระบบส่วนลดสำหรับห้องพักแบบครบวงจร (Admin CRUD, Quota Pools, Lifecycle Redemptions, Real-time Repricing, Preview Endpoints) ตามสเปก D1–D13

### 📋 Key Decisions & Architecture (D1–D13)
- **D1 ฐานคำนวณส่วนลด:** เฉพาะค่าห้องพัก (`room_amount = rate × nights`) — ค่า addon (breakfast, extra bed, early check-in, late check-out) ไม่ถูกหักส่วนลด
- **D2 Discount Types:** `percent` (1–100), `fixed` (satang ต่อ booking_room), `set_room_price` (satang ราคาห้องต่อคืน)
- **D3 Targeting:** `room_type_ids` (JSON nullable) — `null` = ใช้ได้ทุกประเภทห้อง
- **D4 Windows:** `usable_from/until` (datetime vs now) + `stay_from/until` (date vs check_in/check_out)
- **D5–D6 Quotas & All-or-Nothing:** 1 eligible booking_room = 1 slot; ทั้ง global (`max_uses`) และ per-user (`max_uses_per_user`) นับรวม `held` + `used`; ถ้า quota เหลือไม่พอ $K$ ห้อง จะปฏิเสธ 422 ทั้งชุด
- **D7–D8 Lifecycle & Auto-Release:** apply บน draft → `held` (คงค้างตลอด `pending` / `verify_error` / resubmit); เข้า `paid` หรือ `confirmed` → `used` ถาวรผ่าน hook ใน `Booking::transitionStatus()`; คืน slot อัตโนมัติด้วย DB Foreign Key cascade เมื่อลบ booking, booking_room หรือโค้ด
- **D9 Single Source of Truth:** `DiscountService` (`app/Services/Discount/DiscountService.php`) จัดการ quota, reprice, apply, remove ทั้งหมด
- **D10 Known Behavior:** booking `verify_error` ที่ user ทิ้งไว้ = HELD slot ค้าง (เหมือนห้องที่ค้าง)
- **D11 Oversell Protection:** pessimistic lock (`lockForUpdate()`), post-insert assertion ใน transaction เดียวกัน

### 📁 Files Changed
- **Migrations:** ✨ `database/migrations/2026_08_27_110000_create_discount_system_tables.php` (ตาราง `discounts`, `discount_redemptions`, เพิ่ม `discount_code` ใน `bookings` และ `room_amount`, `discount_amount` ใน `booking_rooms`)
- **Models:** ✨ `app/Models/Discount.php` (`PgBoolean` cast, uppercase code mutator) · ✨ `app/Models/DiscountRedemption.php` · ✏️ `app/Models/Booking.php` (fillable + hook `transitionStatus`) · ✏️ `app/Models/BookingRoom.php` (fillable + integer casts)
- **Services:** ✨ `app/Services/Discount/DiscountService.php` (`applyToDraft`, `removeFromDraft`, `isEligible`, `computeForRoom`, `reprice`)
- **Controllers:** ✨ `app/Http/Controllers/Api/V1/DiscountController.php` (`preview`, `index`, `store`, `update`, `toggleActive`) · ✏️ `app/Http/Controllers/Api/V1/BookingController.php` (ลบ draft `validateDiscount`, เพิ่ม `setDiscountCode`, `destroyDiscountCode`, integrate ใน `createBooking`, `addRooms`, `updateRoom`, `updateRooms`, `destroyRoom`, `recalculateBookingTotal`)
- **Requests:** ✏️ `app/Http/Requests/StoreBookingRequest.php` (เพิ่ม `discount_code` rule & messages)
- **Routes:** ✏️ `routes/api.php` (ลบ draft route, เพิ่ม `POST /discounts/validate`, `PUT/DELETE /bookings/{id}/discount-code`, admin routes `/discounts*`)
- **Seeders:** ✨ `database/seeders/DiscountSeeder.php` (`WELCOME10`) · ✏️ `database/seeders/DatabaseSeeder.php`
- **Tests:** ✨ `tests/Feature/DiscountTest.php` (26 test cases ครบทุก scenario)
- **Docs:** ✏️ `docs/api_guide.md` · ✏️ `AGENTS.md` · ✏️ `cline.md`

### 🗄️ Migration Required
⚠️ **ต้องรัน `php artisan migrate`** (หรือ `migrate:fresh --seed` บน dev)

### 🧪 Test Results
- `php artisan test` — **313 passed (810 assertions)** (100% green full suite)
- `vendor/bin/pint --dirty` — ผ่าน (clean code style)

### 🔧 Scrutinize Fixes (2026-08-27, ต่อจาก review) — bug #42–#45

> ผลจากการ scrutinize แบบ end-to-end: พบ 7 findings, แก้ 5 ข้อ + เพิ่ม regression tests 7 cases (`DiscountTest` → **33 passed**)

- **#42 Validation error กลายเป็น HTTP 500:** `BookingController::setDiscountCode()` เรียก `$request->validate()` ใน try/catch `\Exception` — `ValidationException` มี `getCode()=0` ตกไป branch 500. **Fix:** rethrow `ValidationException` ก่อน generic catch (คืน 422 มาตรฐาน Laravel)
- **#43 Admin rename โค้ดที่มี hold = draft พัง:** `bookings.discount_code` เป็น string snapshot — rename แล้ว `reprice()`/re-apply หาโค้ดไม่เจอ → hold ค้างแต่ส่วนลดหาย แก้ draft ไม่ได้. **Fix:** closure rule ใน `DiscountController::update()` ปฏิเสธ (422) การเปลี่ยน `code` เมื่อ `$discount->redemptions()->exists()` — ส่งชื่อเดิม (case-insensitive match) ยังเป็น no-op ได้; rename ได้ปกติเมื่อไม่มี redemption. *ระยะยาว: พิจารณา FK `discount_id` แทน string*
- **#44 Half-set stay window:** validation เดิมยอมรับ `stay_from` อย่างเดียว แต่ `isEligible()` ตีความเป็น "ไม่ eligible ทุกห้อง" → preview 200 แต่ apply 422. **Fix:** `required_with` บังคับเป็นคู่ทั้ง store/update
- **#45 Duplicate code ต่าง case → 500:** DB unique rule จับไม่ได้ (`welcome10` vs `WELCOME10`) — mutator uppercase ก่อน insert เลยชน QueryException. **Fix:** closure rule เช็ค case-insensitive ทั้ง store/update (update exclude ตัวเอง)
- **Dead code cleanup:** ลบ `recalculateBookingTotal()` (ไม่มี caller แล้วหลังย้ายไป `DiscountService::reprice()`), ลบ `$totalAmount`/`$subtotal` ที่ unused ใน `createBooking` (เก็บ `$addedAmount` ใน addRooms — ยังใช้ใน response)
- **Known behavior (documented แทน fix):** admin ปิด/หมดอายุโค้ดที่ถืออยู่ = hold+ส่วนลดค้างใน draft แต่แก้ห้อง 422 จน user DELETE โค้ด — เขียน frontend contract ไว้ใน `docs/api_guide.md` แล้ว
- **Tests:** ✏️ `tests/Feature/DiscountTest.php` +7 regression cases (missing-code 422, non-owner 403, non-draft 422, duplicate-case 422, rename-blocked/rename-ok, stay-window pair)
- Final: `php artisan test` — **320 passed (837 assertions)** · `vendor/bin/pint --dirty` — ผ่าน

### 🌐 Real-domain Verification (2026-08-27) — Discount v2.1 LIVE ✅

> 🎟️ สร้าง `test_scripts/test_discount_remote.php` (pattern เดียวกับ `test_*_remote.php`: env `KUHOME_BASE_URL`, register throwaway user, admin login) — รันสำเร็จ **19/19 checks** บน `https://ku-home.ku.ac.th/backend/api/v1`

- **ยืนยันว่า v2.1 deploy แล้วจริง:** CRUD `/discounts`, preview quota, apply/remove code + พฤติกรรม fix #42–#45 (422 ทุกกรณี boundary) ตอบถูกต้องบน domain จริงทั้งหมด
- คณิตเงินเป๊ะบน rate จริงของ server: 2000 → 1500 (25%), `room_amount`/`discount_amount` snapshot ครบ, DELETE code คืนยอดเต็ม
- ⚠️ **บทเรียน WAF/throttle:** domain KU throttles ถี่กว่า throttle config ใน app (โดน 429 ง่ายใน burst) → script ต้อง pacing ~1.5s/request + retry-once-หลังพัก 65s; remote scripts ต่อๆ ไปควรใช้ wrapper pattern เดียวกัน
- ⚠️ **PHP gotcha ที่ไม่ควรซ้ำ:** `?? 'x' === null` fail เสมอเมื่อ success case ของ field คือ `null` (JSON `discount_code:null`) — assert field-null ด้วย `array_key_exists()` + `=== null` เท่านั้น
- Cleanup ท้าย run: draft booking hard-delete ✓, โค้ด throwaway toggle inactive ✓ (rows user/code inactive ค้าง 1-2 แถว/run = by design, FK restrict ป้องกัน DELETE)


## ✅ Early/Late Check-in/out คิดรายชั่วโมง (100 ฿/ชม.) + ลบ boolean ทั้งระบบ (2026-08-27)

> **Hourly Early/Late Check-in/out Refactor** — เปลี่ยนการคิดค่า early check-in / late check-out จาก flat ราคาเดียวต่อห้อง เป็นสูตรรายชั่วโมง (`ราคา = จำนวนชั่วโมง (int 0–5) × ราคา/ชม. จาก global_rates`) พร้อมลบ boolean ทั้งระบบตามเอกสาร [`docs/early-late-hourly-plan.md`](./docs/early-late-hourly-plan.md)

### 🎯 การเปลี่ยนแปลงหลัก
1. **สูตรคิดเงินรายชั่วโมง:**
   - `global_rates` code `early_checkin` / `late_checkout` (10000 satang = 100 ฿) ตีความใหม่เป็นราคาต่อชั่วโมง (ไม่ต้องแก้ค่าในตาราง)
   - `Addon` table เพิ่มคอลัมน์ `early_hours` (int, default 0) และ `late_hours` (int, default 0)
2. **ลบ boolean ทั้งระบบ (Breaking Change):**
   - **Input:** `addons.early_checkin` / `addons.late_checkout` เปลี่ยน type จาก `boolean` → `integer` (0–5 ชม.) ส่ง `true`/`false` จะได้ HTTP `422 Unprocessable Content`
   - **Response:** ลบ `$appends = ['early_checkin', 'late_checkout']` และ accessors `getEarlyCheckinAttribute()` / `getLateCheckoutAttribute()` ออกจาก `BookingRoom` — response ไม่มี boolean บน booking_room แล้ว (ดูจาก `addon.early_hours` / `addon.late_hours` แทน)
3. **Helper ศูนย์กลางใน `BookingController`:**
   - เพิ่ม private helper `resolveEarlyLate(?array $addonInput, ?Addon $existing = null): array` จัดการ fallback เมื่อ partial update ไม่ส่ง `addons` key มา
   - รองรับทั้ง 4 code paths: `createBooking`, `addRooms`, `updateRoom`, `updateRooms` (batch)

### 📁 Files Changed
- **Migration:** ✨ `database/migrations/2026_08_27_120000_add_early_late_hours_to_addons_table.php` (เพิ่ม `early_hours`, `late_hours` และ backfill แถวเดิมที่มีราคา > 0 ให้เป็น 1 ชม.)
- **Models:**
  - ✏️ `app/Models/Addon.php` (เพิ่ม `early_hours`, `late_hours` ใน `$fillable` และ `$casts`)
  - ✏️ `app/Models/BookingRoom.php` (ลบ `$appends` และ accessors `getEarlyCheckinAttribute`, `getLateCheckoutAttribute`)
- **Requests:** ✏️ `StoreBookingRequest.php`, `AddBookingRoomsRequest.php`, `UpdateBookingRoomRequest.php`, `UpdateBookingRoomsRequest.php` (กฎ `['nullable', 'integer', 'min:0', 'max:5', ...]` + ข้อความเตือนภาษาไทย)
- **Controllers:** ✏️ `app/Http/Controllers/Api/V1/BookingController.php` (คิดราคาแบบรายชั่วโมง 4 จุด + helper `resolveEarlyLate`)
- **Tests:** ✏️ `tests/Feature/BookingTest.php` (อัปเดต test เดิม + เพิ่ม tests ใหม่: boolean rejection 422, range validation 422, batch update repricing)
- **Docs & Scripts:**
  - ✏️ `docs/api_guide.md` (ปรับ schema tables, validation rules, request/response samples)
  - ✏️ `docs/database-er.md` (เพิ่ม columns ใน entity `ADDONS`)
  - ✏️ `docs/booking-verify-flow.md` (อัปเดต payload)
  - ✏️ `test_scripts/api_guide.php`, `test_scripts/api_test_remote.php`, `test_scripts/test_create_booking_remote.php` (เปลี่ยน payload/assertions เป็น hourly)

### 🗄️ Migration Required
⚠️ **รัน `php artisan migrate`** (additive migration — ปลอดภัยสำหรับ dev/prod)

### 🧪 Test Results
- `php artisan test` — **323 passed (853 assertions)** (100% green full suite)
- `vendor/bin/pint --dirty` — ผ่าน (clean code style)



## ✅ Bed Type Rename: double|twin → twin|king_size (2026-08-27)

> 🏨 เปลี่ยนชุดค่า bed_type ของระบบ + สลับชนิดเตียงต่อชั้น — floor 8 = `king_size` ทุกห้อง, ชั้นอื่น = `twin`

### 🎯 Why
- ผู้ใช้ (owner) สั่งเปลี่ยน vocabulary: เดิม `{double, twin}` → ใหม่ `{twin, king_size}` พร้อมกันกับการสลับชนิดห้องรายชั้น
- Semantic mirror ของของเดิมเป๊ะ: `bed_preference` ขอเฉพาะ "ชนิดพิเศษประจำชั้น 8" ได้ห้องเดียว — เดิมคือ 'twin', ใหม่คือ 'king_size' (null = any คงเดิม)

### 🔁 Value Mapping (Breaking Change ต่อ API consumer)
| | เดิม | ใหม่ |
|---|---|---|
| ห้องชั้น 5,6,7,9 (`bed_type`) | `'double'` | `'twin'` |
| ห้องชั้น 8 (`bed_type`) | `'twin'` | `'king_size'` |
| `booking_rooms.bed_preference` ที่ request ได้ | `'in:twin'` | `'in:king_size'` |

- Algorithm ไม่แตะ logic เลย — `matchesBedPreference()` เป็น generic equality อยู่แล้ว, `CostCalculator` penalty (+100) คงเดิม, `BookingPriority` generalize `$hasTwin` → `$hasBedPref` (`!== null`, sort position เดิม)
- `RoomDto::fromModel` default fallback → `'twin'`
- ⚠️ **Frontend (ku-home) ต้องเปลี่ยน payload จาก `"twin"` → `"king_size"`**

### 📁 Files Changed
- **Migration:** ✨ `database/migrations/2026_08_27_160317_change_bed_types_to_twin_king_size.php` — widen varchar(8)→varchar(16) เฉพาะ pgsql (พร้อม `SET DEFAULT 'twin'`) + backfill data (rooms ทุกห้อง → twin, ชั้น 8 → king_size, bed_preference 'twin' → 'king_size')
- **Legacy migrations edited** (เพื่อให้ `migrate:fresh` ได้ schema ตรง vocabulary ใหม่): `2026_07_13_105530` (width 16 + default 'twin'), `2026_07_13_105531` (width 16)
- **Seeder:** ✏️ `RoomSeeder.php` — ternary ชั้น 8 = king_size, อื่นๆ twin
- **Requests:** ✏️ `UpdateBookingRoomRequest.php`, `UpdateBookingRoomsRequest.php` — `in:king_size` + message
- **Allocator:** ✏️ `Dto/RoomDto.php`, `Dto/BookingRequestDto.php`, `CostCalculator.php` (comments), `BookingPriority.php` (hasBedPref)
- **Tests:** ✏️ `RoomAllocatorIntegrationTest.php` (rename test → `test_king_size_preference_picks_floor_8_rooms`), `CostCalculatorTest.php`, `TopologyTest.php`, `tests/Feature/BookingTest.php`
- **Docs:** ✏️ `docs/api_guide.md` (examples/validation tables), `cline.md` (entry นี้) · Historical R&D docs (`docs/algo_test/*`) ไม่แก้

### 🗄️ Migration Required
⚠️ **รัน `php artisan migrate`** — backfill data ทั้ง rooms และ booking_rooms (DB เดิมโดน update ค่าทันที, dev/prod path เดียวกัน)

### ➕ Follow-up: `bed_preference` บน POST endpoints (2026-08-28)
- **What:** `POST /bookings` (`StoreBookingRequest`) และ `POST /bookings/{id}/rooms` (`AddBookingRoomsRequest`) รับ + persist `booking_rooms.*.bed_preference` แล้ว (`'bed_preference' => $roomRequest['bed_preference'] ?? null` ใน `BookingController@createBooking` + `@addRooms`) — ก่อนหน้านี้ field นี้รับได้เฉพาะทาง PUT endpoints
- **Vocabulary:** `in:king_size` ตรงกับ PUT และ migration (ชุดงานแรกเขียน `in:twin` ซึ่งเป็น vocabulary เก่า — แก้ให้ตรงก่อน commit)
- **Tests:** ✨ 4 เคสใหม่ใน `BookingTest` (persist + reject ทั้ง create/add) — reject case ใช้ค่าเก่า `'twin'`/`'double'` เป็น regression guard ของ rename


## ✅ Early/Late Check-in/out ขยายเพดานจาก 5 → 7 ชั่วโมง (2026-08-28)

> 🕐 เพิ่ม maximum ของ `addons.early_checkin` / `addons.late_checkout` จาก **5 ชม. → 7 ชม.** (owner request) — สูตรราคาคงเดิม (`ราคา = ชม. × rate/ชม. จาก global_rates`) แค่ขยายช่วง input

### 📁 Files Changed
- **Requests:** ✏️ `StoreBookingRequest.php`, `AddBookingRoomsRequest.php`, `UpdateBookingRoomRequest.php`, `UpdateBookingRoomsRequest.php` — rule `max:5` → `max:7` + ข้อความเตือนภาษาไทยเปลี่ยนช่วงเป็น `0-7` ทุกจุด (ทั้ง closure fail message และ `messages()`)
- **Controllers:** ✏️ `app/Http/Controllers/Api/V1/BookingController.php` (comment ของ helper `resolveEarlyLate` 0-5 → 0-7 — logic คิดเงินไม่แตะเลย เพราะคูณตรงจากชั่วโมงที่ validate แล้ว)
- **Tests:** ✏️ `tests/Feature/BookingTest.php::test_create_booking_rejects_early_hours_out_of_range` — reject case เปลี่ยนจาก `6` (เกิน 5) → `8` (เกิน 7) เพื่อให้ยังทดสอบเส้นบนได้จริง
- **Docs:** ✏️ `docs/api_guide.md` — validation tables + pricing notes + `early_hours`/`late_hours` response tables (0–5 → 0–7 ทั้ง 7 จุด) · `docs/early-late-hourly-plan.md` ทิ้งไว้เป็น historical record (ระบุช่วงเก่า 0–5 ตามวันที่เขียน)

### 📝 Notes
- ไม่มี migration — คอลัมน์ `early_hours`/`late_hours` เป็น int ธรรมดา รับค่าใหม่ได้เลย
- จุดคุมเพดานอยู่ที่ **Form Request validation เท่านั้น** (controller ไม่มี clamp ซ้ำ) — ถ้าอนาคตจะปรับเพดานอีก แก้ 4 ไฟล์ Request + test reject case + docs


## ✅ Scrutinize Fixes (2026-08-28) — bug #46, #47 & Nit (Completed)

> 🔧 แก้ไข findings จากการ scrutinize (`docs/scrutinize-2026-08-28-handoff.md`) ครบถ้วน พร้อมเพิ่ม regression tests ครอบคลุมทุกจุด

### 🔴 #46 (RESOLVED): `PUT /discounts/{id}` เปลี่ยน `type` โดยไม่ส่ง `value` → ส่วนลด 100%
- **สาเหตุ:** `value` rule เดิมใช้ `sometimes` ทำให้เมื่อ request ส่ง `type` แต่ไม่ส่ง `value` การตรวจ closure percent 1–100 ถูกข้าม → ค่า satang เดิมถูกใช้เป็น percent
- **Fix:** เปลี่ยน rule `value` ใน `DiscountController::update()` เป็น `'required_with:type'` (ตัด `sometimes` ออกเพื่อให้ `required_with` ทำงานเมื่อส่ง `type`) + เพิ่ม custom validation error message `'value.required_with' => 'เปลี่ยนประเภทส่วนลดต้องส่ง value มาพร้อมกันเสมอค่ะ'`
- **Tests:** ✨ `tests/Feature/DiscountTest.php::test_update_discount_type_swap_without_value_is_rejected` (+ edge cases: percent range clamp, standalone field updates)

### 🟡 #47 (RESOLVED): draft ที่ถือโค้ด — แก้ห้องโดน rollback + error กำกวม
- **สาเหตุ:** การ reconcile ส่วนลดใน `addRooms`, `updateRoom`, `updateRooms` เรียก `applyToDraft()` ซึ่งถ้าทุกห้องหลุด eligibility จะคืน 422 ทั่วไปโดยไม่บอกทางแก้ไข
- **Fix:** สกัด helper `BookingController::reconcileDiscount(Booking $booking)` รวมจุด reconcile ทั้ง 3 endpoints — ดักจับ 422 แล้วแปลงเป็น error message ชี้ทางออกชัดเจน: `'การแก้ไขทำให้การจองไม่เข้าเกณฑ์โค้ด '.$booking->discount_code.' อีกต่อไป — กรุณาลบโค้ดส่วนลดก่อน (DELETE /bookings/'.$booking->id.'/discount-code) แล้วลองแก้ไขอีกครั้งค่ะ'`
- **Tests:** ✨ `tests/Feature/BookingTest.php` (`test_update_room_ineligible_for_discount_returns_actionable_error`, `test_batch_update_rooms_ineligible_for_discount_returns_actionable_error`)

### ⚪ Nit (RESOLVED): `resolveEarlyLate` dead fallback
- **Fix:** เปลี่ยน `$existing?->early_hours ?? 1` และ `$existing?->late_hours ?? 1` เป็น `?? 0` ใน `BookingController::resolveEarlyLate()`

### 🧪 Test Results
- `php artisan test` — **332 passed (900 assertions)** (100% green full suite)
- `vendor/bin/pint --dirty` — ผ่าน (clean code style)



## ✅ Fix: PUT booking room แล้ว addon ไม่อัปเดต — alias `early_hours`/`late_hours` + key-level PATCH (2026-09-01)

### 🐞 อาการ (report จากฝั่ง frontend)
- frontend (`ku-home` `bookings.ts::updateBookingRoomsInBooking`) ส่ง `addons: { early_hours: 1, late_hours: 3, early_checkIn_price: 20000, late_checkOut_price: 10000 }` มาที่ `PUT /bookings/{id}/rooms` (และรายห้อง)
- API ไม่มี rule ของ key `early_hours`/`late_hours` → validation ตัดทิ้ง → canonical keys หายไปทั้งหมด → ชั่วโมงไม่ถูกอัปเดต (response ยังค่าเดิม)

### 🔧 สาเหตุ + แนวทางแก้ (ฝั่ง backend รองรับ payload ของ frontend)
1. **Alias validation:** เพิ่ม `addons.early_hours` / `addons.late_hours` (integer 0-7 + reject boolean เหมือน canonical) ใน 4 Form Requests: `StoreBookingRequest`, `AddBookingRoomsRequest`, `UpdateBookingRoomRequest`, `UpdateBookingRoomsRequest`
2. **`BookingController::resolveEarlyLate()`:** ลำดับ resolve ต่อ key = **canonical (`early_checkin`/`late_checkout`) → alias (`early_hours`/`late_hours`) → คงค่าเดิมจากแถว addon**; เมื่อไม่ส่ง `addons` key มาเลยยังใช้ legacy fallback เดิม (ดู `early_checkIn_price` > 0)
3. **Key-level PATCH semantics:** ส่ง `addons` มาแบบ partial (เช่นมีแต่ hours ไม่มี breakfast) = key ที่ไม่ส่ง **คงค่าเดิม** ไม่ reset เป็น 0 — ตรงกับ comment เดิมในโค้ด "ใช้ค่าใหม่ถ้าส่งมา ไม่งั้นค่าเดิมจาก Addon row"; จะปิด addon ต้องส่ง `0` ชัดๆ (เดิมส่ง partial แล้ว breakfast โดนลบเงียบ ๆ = landmine)
4. **ราคาที่ client ส่งมา (`early_checkIn_price` ฯลฯ) ยังถูก ignore** — reprice จาก `global_rates` ฝั่ง server เสมอ (กัน price manipulation #20)

### 📁 Files Changed
- `app/Http/Requests/{StoreBookingRequest,AddBookingRoomsRequest,UpdateBookingRoomRequest,UpdateBookingRoomsRequest}.php` — alias rules + messages
- `app/Http/Controllers/Api/V1/BookingController.php` — `resolveEarlyLate()` + breakfast key-level fallback (updateRoom + updateRooms)
- `tests/Feature/BookingTest.php` — ✨ 5 tests: alias รายห้อง (คง breakfast), batch ตาม payload จริงจาก report, canonical ชนะ alias, alias เกิน 7 ชม. 422, create ผ่าน alias
- `docs/api_guide.md` — ตาราง validation 3 จุด + หมายเหตุ semantics

### 🧪 Test Results
- `php artisan test` — **337 passed (911 assertions)** (100% green)



## 🗺️ Wayfinder map เปิดใหม่: Booking per-room amount (2026-09-03, charting — ยังไม่มีการแก้โค้ด)

- **โจทย์:** "booking total_amount → add amount to each booking room" — ปัจจุบัน `booking_rooms` มี `room_amount`/`discount_amount` (ยุค discount v2.1) แต่ไม่มี per-room total ที่รวม addon; `bookings.total_amount` (net) เขียนโดย chokepoint เดียว `DiscountService::reprice()` ยกเว้น `FrontDeskController::walkIn` ที่ bypass
- **Map:** `wayfinder/booking-room-amount/map.md` (tracker = local-markdown, ไม่มี gh CLI) + tickets T1–T5 (`tickets/`) — T1 (ความหมายของ `amount`: net/gross/no-column) เป็นประตูบานของทุกตั๋ว, T2 walkIn, T3 backfill, T4 API/docs contract, T5 spec รวมสำหรับ hand-off
- **สถานะ:** ✅ เดิน map จบแล้ว (2026-09-03) — T1–T4, T6 ผู้ใช้ยืนยันครบทุก decision (รายละเอียดอยู่ `## Resolution` ของแต่ละ ticket); T5 spec รวมอยู่**หัวข้อถัดไปด้านล่าง** — ยังไม่มีการแก้โค้ด

## 📐 Implementation Spec: `booking_rooms.amount` (net ต่อห้อง) — wayfinder "Booking per-room amount" (2026-09-03, ✅ implemented — รายงานผลอยู่หัวข้อ "✅ Landed" ด้านล่าง)

> รวบ decision T1–T6 เป็นสเปกที่ implement ได้ทันทีโดยไม่ต้องตัดสินใจใหม่ — ห้ามฝืน invariant ที่ว่าด้านล่าง

### 🎯 ความหมาย + Invariant (T1, T6)

- คอลัมน์ใหม่ `booking_rooms.amount` (integer satang, default 0) = **ยอดสุทธิต่อห้อง**:
  `amount = room_amount − discount_amount + extra_bed_price + breakfast_price + early_checkIn_price + late_checkOut_price`
- **Invariant: Σ booking_rooms.amount == bookings.total_amount** (ทั้งสองฝั่ง net) — บังคับด้วย **test-only** ไม่มี runtime guard และ**ห้าม**ทำ model observer (repo ไม่มี events/listeners โดยตั้งใจ)

### 📁 ลำดับงาน + รายการแฟ้ม

1. **Migration add column** — `booking_rooms.amount` (`integer`, `default 0`, วางหลัง `discount_amount`): รันด้วย `php artisan migrate` ปกติได้ทั้ง SQLite/PostgreSQL (ห้าม SQLite-only SQL, idempotent-friendly) · **ไม่มี backfill** — ยังไม่มีข้อมูลจริง deploy ด้วย `migrate:fresh --seed` ได้ (T3) · ถ้าอนาคต prod มีข้อมูลสะสมก่อน deploy ให้เติม defensive backfill จาก stored data (`room_amount − discount_amount + addon`) ก่อนแล้วจดที่หัวข้อนี้
2. **`app/Models/BookingRoom.php`** — `$fillable` += `'amount'`, `$casts` += `'amount' => 'integer'` (field ride along ทุก response อัตโนมัติ เพราะ repo ไม่มี API Resources — T4)
3. **`app/Services/Discount/DiscountService.php::reprice()`** — ใน loop เดียวกับ `room_amount`/`discount_amount`: คำนวณ `$amount = $roomAmount - $discountAmount + ($br->addon?->extra_bed_price ?? 0) + ($br->addon?->breakfast_price ?? 0) + ($br->addon?->early_checkIn_price ?? 0) + ($br->addon?->late_checkOut_price ?? 0)` → ใส่ `'amount' => $amount` ใน `$br->update([...])` และเปลี่ยน `$total += ...` เป็น `$total += $amount` — **สูตรอยู่จุดเดียว** (T1)
4. **`app/Http/Controllers/Api/V1/FrontDeskController.php::walkIn()`** — หลัง `BookingRoom::create()` **ก่อน transition ทั้งหมด** (booking ยัง `draft`): `app(DiscountService::class)->reprice($booking);` (T2 — พิสูจน์แล้ว `reprice()` ไม่มี guard สถานะ, walk-in ไม่มีโค้ดส่วนลด → `amount = room_amount = rate × nights`) · แนะนำ: ตัดการคำนวณ `$totalAmount` เขียนมือ, create booking ด้วย `total_amount => 0` แล้วให้ `reprice()` เขียนแทน (ทุกอย่างใน transaction เดิม rollback ได้)
5. **`app/Http/Controllers/Api/V1/BookingController.php`** — **ไม่ต้องเขียน `amount` เองที่อื่นเลย**: create/addRooms/updateRoom/updateRooms(batch)/destroyRoom ไหลผ่าน `reconcileDiscount()`/`destroyRoom` → `reprice()` ครบแล้ว · เท่านั้นตรวจ response ที่คำนวณซ้ำ (`added_amount` = gross ของห้องที่เพิ่ม — คง naming เดิมได้ เทียบเคียงตอนเขียน docs)
6. **Tests (T6 test-only invariant)** — ทำ helper เช่น `assertAmountInvariant(Booking $b): void` (query fresh จาก DB: Σ `booking_rooms.amount` vs `bookings.total_amount`) แล้วเรียกหลังทุก mutation:
   - `BookingTest`: create / addRooms / updateRoom / batch update / destroyRoom / early-late addons
   - `DiscountTest`: set โค้ด / remove โค้ด / eligible เฉพาะบางห้อง
   - `FrontDeskTest`: walk-in มี `room_amount`/`amount` ครบ + invariant ผ่าน (T2)
   - `PaymentTest`: regression — flow เดิมไม่กระทบ
7. **Docs (T4)** — `docs/api_guide.md`: booking_room schema + สูตร amount + คำเตือน `Σ(amount) == total_amount` ให้ frontend อ้างอิงได้ · `docs/database-er.md`: คอลัมน์ใหม่ · design decision ชุดนี้ = หัวข้อนี้

### ⚠️ ข้อควรระวัง

- เงินเป็น **integer satang ตลอด** ห้าม float/decimal; ไม่มี boolean column ใหม่จึงไม่แตะเรื่อง `PgBoolean`
- `reprice()` อ่าน rate จาก `GlobalRate` **ปัจจุบัน**ทุกครั้ง (พฤติกรรมเดิม) — walk-in เรียก reprice จึงได้เลขเดียวกับยอดที่เคยคำนวณเอง
- ห้าม bypass `transitionStatus()` ตอนแตะ walkIn (state machine + audit log เดิม)
- **Fog ค้าง (นอก scope implement):** frontend `ku-home` — additive field ประกาศผ่าน release note แล้วภายหลังเช็ค consumer ที่อ่าน `total_amount`/`room_amount`/`added_amount`

## ✅ Landed: `booking_rooms.amount` (net ต่อห้อง) — implement spec ด้านบนเสร็จ (2026-09-03)

> สเปก "📐 Implementation Spec: `booking_rooms.amount`" หัวข้อก่อนหน้า → **implemented ครบตามลำดับงาน 7 ขั้น** ไม่มี deviation จาก decision T1–T6

### 📁 Files Changed

- `database/migrations/2026_09_03_100000_add_amount_to_booking_rooms_table.php` — **ใหม่**: `booking_rooms.amount` integer default 0 (after `discount_amount`) · รัน `php artisan migrate` บน SQLite local ผ่าน (ไม่มี backfill ตาม T3)
- `app/Models/BookingRoom.php` — `$fillable`/`$casts` += `amount` (integer)
- `app/Services/Discount/DiscountService.php::reprice()` — สูตร `amount = room_amount − discount_amount + addon 4 รายการ` อยู่**จุดเดียว** (loop เดียวกับ room_amount/discount_amount) และ `$total += $amount`
- `app/Http/Controllers/Api/V1/FrontDeskController.php::walkIn()` — ตัดการคำนวณ `$totalAmount` เขียนมือ (ไม่ใช้ `GlobalRate` ในไฟล์นี้แล้ว), create booking ด้วย `total_amount => 0` แล้วเรียก `app(DiscountService::class)->reprice($booking)` **หลัง** BR::create **ก่อน** transition ทั้งหมด (T2)
- `app/Http/Controllers/Api/V1/BookingController.php` — **ไม่แตะ** (ทุก mutation path ไหลผ่าน `reconcileDiscount()`/`reprice()` อยู่แล้ว)
- `tests/TestCase.php` — helper ใหม่ `assertAmountInvariant(Booking $b)` (query สดจาก DB: Σ `booking_rooms.amount` vs `bookings.total_amount`) ใช้ได้ทุก test ที่ extends `Tests\TestCase`
- `tests/Feature/BookingTest.php` — invariant + amount ชัดๆ: create / addRooms / updateRoom(reprice addons) / early-late hours / batch update / destroyRoom
- `tests/Feature/DiscountTest.php` — invariant + amount: percent (set โค้ด) / addon ไม่โดนลด (100% + breakfast) / partial eligibility ราย room type / remove โค้ด (amount กลับ gross)
- `tests/Feature/FrontDeskTest.php` — walk-in: `room_amount == amount == 3000` + invariant (T2) · **จุ๊ยเดียวนอกสเปก:** เปลี่ยน `createRoom()` จาก `rand()` → counter ตาม precedent `BookingTest` (full suite เคยพังแบบสุ่มจาก room_number ชนกัน — จดไว้เพราะแก้ระหว่าง implement นี้)
- `tests/Feature/PaymentTest.php` — **ไม่แตะ** — payment flow (`POST /payments`) ไม่ผ่าน booking_rooms จึงไม่มีจุด assert invariant; regression = suite เดิมยังเขียว
- `docs/api_guide.md` — ตัวอย่าง response สองที่ (put/delete discount-code) เพิ่ม `amount` + note สูตร/invariant/read-only ให้ frontend + ตาราง BookingRoom model reference เพิ่ม `room_amount`/`discount_amount`/`amount`
- `docs/database-er.md` — BOOKING_ROOMS entity เพิ่ม 3 คอลัมน์เงิน (room_amount, discount_amount, amount)

### 🧪 Test Results

- `php artisan test` — **337 passed (939 assertions)** 100% เขียว · `vendor/bin/pint --dirty` — PASS 8 ไฟล์
- Invariant `Σ booking_rooms.amount == bookings.total_amount` ถูก assert ครบ 10 mutation จุด (6 ของ BookingTest + 4 ของ DiscountTest + walk-in)

### 🌫️ Fog คงเหลือ (นอก scope)

- frontend `ku-home` — field `amount` เป็น additive (ride along ทุก response ที่มี booking_rooms เพราะ repo ไม่มี API Resources) · ภายหลังต้องเช็ค consumer ที่อ่าน `total_amount`/`room_amount`/`added_amount` (`added_amount` ยังเป็น gross ของห้องที่เพิ่ม — naming คงเดิมตาม T4)

## 📐 Room-Type `rates` Object & Money Policy (2026-09-03, ✅ Landed)

> แผนงานจาก `wayfinder/room-type-rates/` (Tickets 01–06) — implement จบสมบูรณ์ 100%

### 🎯 เป้าหมายและนโยบายการเงิน (Money Policy)
- **ปัญหาเดิม:** Frontend ต้อง mock rate card เอง และการ seed ข้อมูลเดิมใช้เลขหลักบาท (`1000`, `1800`, `3500`) ทำให้เมื่อคำนวณการจองด้วย satang ยอดเงินจะเพี้ยน อีกทั้งไม่มีการแสดงเรท KU member, group rates, และ monthly rates
- **Money Policy (ยืนยันแล้ว):**
  - **Database Storage:** เก็บในหน่วย **integer satang** เสมอ (เช่น `100000` satang = 1,000.00 THB) รวมถึง `extra_bed_price` (0, 50000, 60000)
  - **Wire Format (Room-Type Boundary):** ทุก endpoint ที่ส่งคืน room-type จะ serialize ข้อมูลเงิน (`rates` object และ `extra_bed_price`) เป็น **2-decimal-places decimal baht string** (เช่น `"1000.00"`, `"500.00"`)
  - **Booking/Payment Math:** การคำนวณยอดจอง, addon, discount และ per-room amount ยังคงเป็น integer satang เหมือนเดิม ไม่ได้รับผลกระทบ
- **Breaking Change:** ถอด virtual integer field `daily_rate` ออกจากการ serialize ของ `RoomType` โดยแทนที่ด้วย `rates` object:
  ```json
  "rates": {
    "daily":   { "general": "1000.00", "ku_member": "800.00" },
    "group":   { "min_5_rooms": "750.00", "min_10_rooms": "750.00" },
    "monthly": "15000.00"
  }
  ```

### 📁 สรุปไฟล์ที่มีการเปลี่ยนแปลง
1. **Helper & Model:**
   - `app/Support/Money.php` — **ใหม่**: `Money::satangToBaht(int $satang): string` แปลง satang เป็น baht string ด้วย pure integer math (`intdiv`, `%`, `sprintf`) ปราศจาก float precision artifacts
   - `app/Models/RoomType.php` — `$appends = ['rates']`, ซ่อน `dailyRateRow` และ `rateRows`, เพิ่ม `rates` accessor (พร้อม zero fallback `"0.00"`), accessor/mutator `extra_bed_price` (wire baht string, storage satang), เพิ่ม relation `rateRows(): HasMany`
   - `app/Models/GlobalRate.php` — อัปเดต `getRoomRate()` ให้รองรับ query ด้วย optional parameter `$code` สำหรับ group rates
   - `app/Http/Controllers/Api/V1/GlobalRateController.php` — เพิ่ม `daily_ku` ใน validation allowlist และเก็บรักษา `code` เมื่ออัปเดต group rates
2. **Controller (ทั้ง 7 Endpoints):**
   - `app/Http/Controllers/Api/V1/RoomController.php` — eager-load `rateRows` และรวม `rates` object ใน:
     - `allRoomTypes` (`GET /room-types`)
     - `getRoomTypeById` (`GET /room-types/{id}`)
     - `availability` (`GET /availability` — ทั้ง top-level และ embedded `room_type`)
     - `availabilityPerDay` (`GET /availability-per-day`)
     - `availabilityRanges` (`GET /availability-ranges`)
     - `unavailableDates` (`GET /unavailable-dates`)
     - `unavailableRanges` (`GET /unavailable-ranges`)
3. **Database Migration & Seeder:**
   - `database/migrations/2026_09_03_110000_drop_code_unique_from_global_rates_table.php` — **ใหม่**: ปลดล็อก unique index บน `code` ใน `global_rates` เพื่อให้แต่ละ room type สามารถแชร์ code `min_5_rooms`/`min_10_rooms` ได้
   - `database/seeders/RoomSeeder.php` — authored ด้วยเลขบาททศนิยม และบันทึกเป็น integer satang (5 rows ต่อ room type: daily, daily_ku, group min_5_rooms, group min_10_rooms, month) พร้อม `extra_bed_price` เป็น satang (0, 50000, 60000)
4. **Tests:**
   - `tests/Unit/Support/MoneyTest.php` — **ใหม่**: Unit test สำหรับ `Money::satangToBaht` ครอบคลุม 0, เลขหลักเดียว, เลขมาตรฐาน, ค่าหลักล้าน, และค่าติดลบ
   - `tests/Feature/RoomTypeRatesListTest.php` — **ใหม่**: Feature test สำหรับ `GET /room-types` และ `GET /availability`
   - `tests/Feature/RoomTypeRatesCalendarTest.php` — **ใหม่**: Feature test สำหรับทั้ง 4 calendar endpoints พร้อมทดสอบ N+1 prevention (query log count)
   - `tests/Feature/RoomSeederTest.php` — **ใหม่**: Feature test ทดสอบการ seed และ assert ค่า satang จริงในฐานข้อมูล
   - `tests/Feature/RoomTest.php` — เพิ่มเทสต์ `getRoomTypeById` ทดสอบโครงสร้าง rates, extra_bed_price, และ zero fallback
5. **Documentation:**
   - `docs/api_guide.md` — อัปเดตตัวอย่าง response ทั้ง 7 endpoints และปรับปรุง RoomType DB Model reference พร้อมอธิบายนโยบายการเงิน

### 🚨 Migration Required
- **คำสั่งที่ต้องรันบนเซิร์ฟเวอร์/dev:** `php artisan migrate:fresh --seed` เพื่อปรับโครงสร้าง `global_rates` และ seed ข้อมูลเรทห้องพักชุดใหม่ 5 เรทต่อประเภทห้อง


