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
| Public | None | — | Login, register, room availability, room types, create booking, booking lookup, guest payment request, payment webhook |
| Authenticated | `auth:sanctum` | — | Logout, view own bookings, user profile, discount validation |
| Admin | `auth:sanctum` | `role:admin` | User CRUD, booking status management, room status management, payment requests, housekeeping tasks, front-desk operations |

### Draft / Testing Routes (🚧)
These routes exist but are **not for production use**:
- `POST /upload-image` — Image upload system incomplete
- `POST /bookings/validate-discount` — Discount system incomplete (only `WELCOME10`)
- `POST /bookings/lookup` — Guest booking lookup (uses inline validation, no FormRequest)
- `POST /bookings/{id}/request-payment` — Guest payment request (uses inline validation, no FormRequest)

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
- **Fillable**: user_id, confirmation, source, status, check_in, check_out, guest_title, guest_name, guest_email, guest_phone, guest_nationality, is_ku_member, children, total_amount, is_paid, payment_deadline
- **Casts**: check_in→date, check_out→date, is_ku_member→boolean, total_amount→integer, is_paid→boolean, payment_deadline→datetime, children→integer
- **Status Field**: string, managed by `transitionStatus()` state machine
- **Confirmation**: Generated via `generateUniqueConfirmation()` — atomic counter with `booking_sequences` table (format: `YYYYMM-XXXXX`)

#### Room (`app/Models/Room.php`)
- **Primary Key**: UUID
- **Status Field**: string (all lowercase), managed by `transitionStatusTo()` state machine
- **Additional fields**: status_updated_at, status_updated_by, built_in_extra_beds

#### RoomType (`app/Models/RoomType.php`)
- **Primary Key**: UUID
- **Casts**: extra_bed_enabled→boolean

#### Payment (`app/Models/Payment.php`)
- **Primary Key**: UUID (HasUuids trait)
- **Fillable**: booking_id, amount, payment_method, status, reference_number, received_by
- **Casts**: amount→integer (satang/cents) ✅ #30 Fixed

#### Receipt (`app/Models/Receipt.php`)
- **Primary Key**: UUID (HasUuids trait)
- **Fillable**: receipt_no, booking_id, payment_id, amount, billing_name, billing_address
- **Casts**: amount→integer (satang/cents) ✅ #30 Fixed

## State Machines

### Booking Status Flow
```
draft ──> paid ──> confirmed ──> checked_in ──> checked_out
  │         │          │
  │         │          ├──> cancelled
  │         │          └──> no_show
  │         └──> cancelled
  ├──> checked_in (admin walk-in only)
  └──> deleted

Role restrictions:
  draft → paid          : user, guest, admin, system (webhook)
  draft → checked_in    : admin only (walk-in)
  draft → deleted       : user, guest, admin
  paid → confirmed      : admin only
  paid → cancelled      : admin only
  confirmed → cancelled : admin only
  confirmed → checked_in: admin only
  confirmed → no_show   : admin only
  checked_in → checked_out : admin only
```

**Important**: Webhook only transitions `draft → paid`. Admin must manually confirm to `confirmed`.

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
- `DailyRoomMaintenance` (`app/Console/Commands/DailyRoomMaintenance.php`) — Scheduled daily. Resets room statuses for daily maintenance.
- `CleanupExpiredDrafts` (`app/Console/Commands/CleanupExpiredDrafts.php`) — Scheduled daily at 02:00. Transitions expired draft bookings (past `payment_deadline`) to `deleted` via `system` role.

## Database
- **Current**: PostgreSQL (Supabase)
- **Future**: Organization's own server
- **Migrations**: 15+ migrations covering all entities
- **Seeders**: `DatabaseSeeder`, `RoomSeeder`, `UserSeeder`
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
| `tests/Feature/BookingTest` | 17 | CRUD, lookup, search via `?term=`, validation, status transitions, guest draft prevention, rate limiting |
| `tests/Feature/FrontDeskTest` | 6 | Walk-in, check-in, check-out, record payment, room type mismatch validation |
| `tests/Feature/PaymentTest` | 6 | Payment request, webhook, guest email verification |
| `tests/Feature/RoomTest` | 9 | List rooms/types, availability, status update + transitions, availability query consistency |
| `tests/Feature/RouteProtectionTest` | 19 | Public vs authenticated vs admin route access |
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
| #33 | `DB::raw('TRUE')` PostgreSQL-specific → `true` (portable) | 2026-06-05 |
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

## 🔴 Known Unsolved Problems

> ปัญหาที่ตรวจพบจาก Scrutinize Report แต่ยังไม่ได้แก้ไข (อัปเดต: 2026-06-05)

### 🔴 Blocker (ร้ายแรง — deploy จริงไม่ได้)

- **#4: Webhook ไม่มี HMAC signature verification** — `POST /payment/webhook` ไม่มีการตรวจสอบลายเซ็น ใครก็ปลอมการชำระเงินได้
  - ต้องเพิ่ม: HMAC signature check (`X-Webhook-Signature` header) หรือ shared secret token
  - ไฟล์: `PaymentController.php:159`
  - ⏳ **รอหัวหน้าคุยเรื่อง Payment Gateway** — ยังไม่รู้ว่าใช้ Gateway อะไร, signature format ยังไง

### 🟠 Major (สำคัญ — ควรแก้ก่อน production)

- **#20: Addon prices มาจาก client — trust client** — `StoreBookingRequest` ยอมรับ `breakfast_price`, `early_checkIn_price`, `late_checkOut_price` จาก request โดยตรง
  - Client ส่ง `breakfast_price: 0` → ได้ breakfast ฟรี
  - Server ควร lookup ราคาจาก database เอง
  - ไฟล์: `StoreBookingRequest.php:37-39`, `BookingController.php:204-206`

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

