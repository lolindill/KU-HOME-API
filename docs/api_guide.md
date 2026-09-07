# 🏨 KU HOME API Guide

> **Complete API Reference for Frontend Developers**
> Base URL: `/api/v1/`
> Auth: Laravel Sanctum (Bearer Token)

---

## 📑 Table of Contents

1. [Quick Start](#quick-start)
2. [Authentication](#authentication)
3. [User Management](#user-management)
4. [Rooms & Room Types](#rooms--room-types)
5. [Bookings](#bookings-core)
6. [Front Desk Operations](#front-desk-operations)
7. [Payments & Webhooks](#payments--webhooks)
8. [Global Rates](#global-rates)
9. [Dashboard / Housekeeping](#dashboard--housekeeping)
10. [DB Models Reference](#db-models-reference)
11. [State Machines](#state-machines)
12. [Appendix](#appendix)

---

## Quick Start

### Base URL

```
https://ku-home.ku.ac.th/backend/api/v1
```

### Authentication

All protected routes require a Bearer token in the `Authorization` header:

```
Authorization: Bearer <access_token>
```

Tokens are issued by `POST /api/v1/login` or `POST /api/v1/register` via Laravel Sanctum.

### Standard Response Format

**Success** — always includes `status: "success"`:

```json
{
  "status": "success",
  "message": "Human-readable message",
  ...data fields
}
```

**Error** — always includes `status: "error"`:

```json
{
  "status": "error",
  "message": "Error description (often Thai)"
}
```

**Info** — special case for non-error informational responses:

```json
{
  "status": "info",
  "message": "..."
}
```

### Common HTTP Status Codes

| Code | Meaning                  | When                              |
|------|--------------------------|-----------------------------------|
| 200  | OK                       | Successful GET/PUT                |
| 201  | Created                  | Resource created                  |
| 400  | Bad Request              | Business rule violation           |
| 401  | Unauthorized             | Missing/invalid token             |
| 403  | Forbidden                | Wrong role or ownership           |
| 404  | Not Found                | Resource doesn't exist            |
| 422  | Unprocessable Entity     | Validation failed / rule conflict |
| 500  | Internal Server Error    | Unexpected server error           |

### Pagination Format

Used by `GET /bookings` and `GET /users`:

```json
{
  "status": "success",
  "bookings": [ ...array of items... ],
  "pagination": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 73
  }
}
```

### Roles

| Role          | Description                                                |
|---------------|------------------------------------------------------------|
| `admin`       | Full access — manage users, bookings, rooms, housekeeping  |
| `user`        | Member — book & manage own bookings                        |
| `guest`       | Guest — limited access (legacy/transient)                  |
| `ku_member`   | KU member — eligible for member rates                      |
| `staff`       | Staff — operational access                                 |
| `housekeeping`| Housekeeping team — cleaning task access                   |
| `system`      | System role — automated transitions (e.g. webhook, cron)   |

> ⚠️ **Note**: Guests/non-members can **no longer** use the booking system. All users must login.

---

## Authentication

### POST `/register` — Register new member

🔒 **Public** (no auth)

**Request Body:**
```json
{
  "name": "Somchai Jaidee",
  "email": "somchai@example.com",
  "password": "SecurePass123!",
  "password_confirmation": "SecurePass123!"
}
```

**Response `201`:**
```json
{
  "status": "success",
  "message": "Registration successful",
  "access_token": "1|abcdef1234567890...",
  "token_type": "Bearer"
}
```

**Validation Rules:**
| Field                   | Rule                                    |
|-------------------------|-----------------------------------------|
| `name`                  | required, string, max 255               |
| `email`                 | required, email, unique:users           |
| `password`              | required, string, min 8                    |

---

### POST `/login` — Login

🔒 **Public** · ⏱ Rate-limited: 5 requests/minute

**Request Body:**
```json
{
  "email": "somchai@example.com",
  "password": "SecurePass123!"
}
```

**Response `200`:**
```json
{
  "status": "success",
  "message": "Login successful",
  "access_token": "1|abcdef1234567890...",
  "token_type": "Bearer"
}
```

**Response `401`:**
```json
{
  "status": "error",
  "message": "อีเมลหรือรหัสผ่านไม่ถูกต้องค่ะ"
}
```

---

### POST `/logout` — Logout

🔒 **Auth required**

Revokes the current access token.

**Response `200`:**
```json
{
  "status": "success",
  "message": "Logout successful"
}
```

---

## User Management

### GET `/me` — Get current user profile

🔒 **Auth required**

**Response `200`:**
```json
{
  "status": "success",
  "message": "User profile fetched successfully",
  "user": {
    "id": "uuid-string",
    "name": "Somchai Jaidee",
    "email": "somchai@example.com",
    "role": "user",
    "ver": false,
    "created_at": "2026-06-19T10:00:00.000000Z",
    "updated_at": "2026-06-19T10:00:00.000000Z"
  }
}
```

---

### PUT `/profile` — Update own profile

🔒 **Auth required**

> 🛡️ Users **cannot** change their own `role` or `ver` (verification). These must go through admin endpoints.

**Request Body (all fields optional):**
```json
{
  "name": "Somchai Newname",
  "password": "NewPassword123!"
}
```

**Response `200`:**
```json
{
  "status": "success",
  "message": "Profile updated successfully",
  "user": { ...updated user object... }
}
```

---

### GET `/users` — List all users (Admin)

🔒 **Admin only**

**Query Params:**
- Pagination automatic (15 per page)

**Response `200`:**
```json
{
  "status": "success",
  "message": "Users fetched successfully",
  "users": {
    "data": [ ...array of user objects... ],
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

---

### POST `/users` — Create user (Admin)

🔒 **Admin only**

**Request Body:**
```json
{
  "name": "New User",
  "email": "new@example.com",
  "password": "Password123!",
  "role": "user",
  "ver": false
}
```

**Response `201`:**
```json
{
  "status": "success",
  "message": "สร้างผู้ใช้เรียบร้อยแล้ว",
  "user": { ...user object... }
}
```

---

### GET `/users/{id}` — Get user by ID (Admin)

🔒 **Admin only**

**Path Params:** `id` (UUID)

**Response `200`:**
```json
{
  "status": "success",
  "message": "User fetched successfully",
  "user": { ...user object... }
}
```

**Response `404`:** User not found

---

### PUT `/users/{id}` — Update user (Admin)

🔒 **Admin only**

**Request Body (all optional):**
```json
{
  "name": "Updated Name",
  "email": "updated@example.com",
  "password": "NewPassword!",
  "role": "admin",
  "ver": true
}
```

**Response `200`:**
```json
{
  "status": "success",
  "message": "อัปเดตข้อมูลสำเร็จเรียบร้อยแล้ว",
  "user": { ...updated user... }
}
```

---

### DELETE `/users/{id}` — Delete user (Admin)

🔒 **Admin only**

**Response `200`:**
```json
{
  "status": "success",
  "message": "ลบผู้ใช้เรียบร้อยแล้ว"
}
```

---

### PUT `/users/{id}/verify` — Toggle user verification (Admin)

🔒 **Admin only**

**Request Body:**
```json
{
  "ver": true
}
```

**Response `200`:**
```json
{
  "status": "success",
  "message": "ยืนยันตัวตนผู้ใช้สำเร็จ",
  "user": { ...user with updated ver... }
}
```

---

## Rooms & Room Types

### GET `/rooms` — List all rooms

🔒 **Public**

**Response `200`:**
```json
{
  "status": "success",
  "message": "All rooms fetched successfully",
  "total_rooms": 50,
  "rooms": [
    {
      "id": "uuid",
      "room_number": "101",
      "room_type_id": "uuid",
      "room_type_name": "Standard",
      "status": "available",
      "status_updated_at": "2026-06-19T08:00:00.000000Z"
    }
  ]
}
```

---

### GET `/rooms/status` — Room status overview

🔒 **Public**

**Query Params:**
- `status` (optional) — Filter by status: `available`, `occupied`, `checkout_makeup`, `prep_checkin`, `maintenance`, `reserved_closed`

**Response `200`:**
```json
{
  "status": "success",
  "message": "Room status list fetched successfully",
  "total_rooms": 12,
  "rooms": [
    {
      "room_number": "101",
      "room_type": "Standard",
      "status": "available",
      "last_updated": "2 hours ago"
    }
  ]
}
```

---

### GET `/rooms/{id}` — Get room by ID

🔒 **Public**

**Response `200`:**
```json
{
  "status": "success",
  "message": "Room fetched successfully",
  "room": {
    "id": "uuid",
    "room_number": "101",
    "room_type_id": "uuid",
    "room_type_name": "Standard",
    "status": "available",
    "status_updated_at": "2026-06-19T08:00:00.000000Z"
  }
}
```

**Response `404`:** Room not found

---

### PUT `/rooms/{id}/status` — Update room status

🔒 **Admin only**

Uses **state machine** — invalid transitions are rejected (see [Room State Machine](#room-state-machine)).

**Request Body:**
```json
{
  "status": "maintenance"
}
```

**Allowed `status` values:** `available`, `occupied`, `checkout_makeup`, `prep_checkin`, `maintenance`, `reserved_closed`

**Response `200`:**
```json
{
  "status": "success",
  "message": "Room status updated to 'maintenance' successfully!",
  "room_id": "uuid",
  "room_number": "101",
  "old_status": "available",
  "new_status": "maintenance",
  "status_updated_at": "2026-06-19T10:30:00.000000Z",
  "status_updated_by": "admin-uuid"
}
```

---

### GET `/room-types` — List all room types

🔒 **Public**

**Response `200`:**
```json
{
  "status": "success",
  "message": "All room types fetched successfully",
  "total_types": 3,
  "room_types": [
    {
      "id": "uuid",
      "name_en": "Superior",
      "name_th": "ห้องซูพีเรียร์",
      "description": "ห้องซูพีเรียร์ขนาด 28 ตร.ม.",
      "max_guests": 2,
      "extra_bed_enabled": false,
      "max_extra_beds": 0,
      "extra_bed_price": "0.00",
      "rates": {
        "daily": {
          "general": "1000.00",
          "ku_member": "800.00"
        },
        "group": {
          "min_5_rooms": "750.00",
          "min_10_rooms": "750.00"
        },
        "monthly": "15000.00"
      },
      "created_at": "...",
      "updated_at": "..."
    }
  ]
}
```

---

### GET `/room-types/{id}` — Get room type by ID

🔒 **Public**

**Response `200`:**
```json
{
  "status": "success",
  "message": "Room type fetched successfully",
  "room_type": {
    "id": "uuid",
    "name_en": "Deluxe",
    "name_th": "ห้องดีลักซ์",
    "description": "ห้องดีลักซ์",
    "max_guests": 2,
    "extra_bed_enabled": true,
    "max_extra_beds": 1,
    "extra_bed_price": "500.00",
    "rates": {
      "daily": {
        "general": "1200.00",
        "ku_member": "1000.00"
      },
      "group": {
        "min_5_rooms": "900.00",
        "min_10_rooms": "750.00"
      },
      "monthly": "18000.00"
    },
    "created_at": "...",
    "updated_at": "..."
  }
}
```

---

### GET `/availability` — Check room availability

🔒 **Public**

**Query Params:**
- `check_in` (optional, date, ≥ today) — default: today
- `check_out` (optional, date, > check_in) — default: tomorrow
- `max_guests` (optional, integer, ≥ 1) — กรองเอาเฉพาะ room type ที่รองรับจำนวนแขก >= ค่าที่ส่ง (เทียบกับคอลัมน์ `max_guests` ของ room type)

**Semantics:**
- `available_rooms` = `max(0, rooms_count − booked_rooms_count)`
  - `rooms_count` = ห้องที่ `status='available'` ของ room type นั้น (snapshot ณ ตอนนี้)
  - `booked_rooms_count` = booking_rooms ที่ status ∈ (draft, confirmed, checked_in) และ overlap `[check_in, check_out)` — half-open `check_in < checkOut AND check_out > checkIn`
- `room_type` object embed ฟิลด์เต็ม (`max_guests`, `extra_bed_*`, `rates`) เพื่อให้ frontend มีข้อมูลครบโดยไม่ต้องเรียก `/room-types` แยก (ทั้ง top-level และ embedded `room_type` มี `rates` object ในรูป baht string ทศนิยม 2 ตำแหน่ง)

**Response `200`:**
```json
{
  "status": "success",
  "message": "Room availability fetched successfully",
  "room_types": [
    {
      "room_type_id": "uuid",
      "name_en": "Superior",
      "name_th": "ห้องซูพีเรียร์",
      "available_rooms": 8,
      "rates": {
        "daily": {
          "general": "1000.00",
          "ku_member": "800.00"
        },
        "group": {
          "min_5_rooms": "750.00",
          "min_10_rooms": "750.00"
        },
        "monthly": "15000.00"
      },
      "room_type": {
        "id": "uuid",
        "name_en": "Superior",
        "name_th": "ห้องซูพีเรียร์",
        "max_guests": 2,
        "extra_bed_enabled": false,
        "max_extra_beds": 0,
        "extra_bed_price": "0.00",
        "rates": {
          "daily": {
            "general": "1000.00",
            "ku_member": "800.00"
          },
          "group": {
            "min_5_rooms": "750.00",
            "min_10_rooms": "750.00"
          },
          "monthly": "15000.00"
        }
      },
      "search_criteria": {
        "check_in": "2026-06-19",
        "check_out": "2026-06-20",
        "max_guests": 2
      }
    }
  ]
}
```
> 💡 `max_guests` ใน `search_criteria` เป็น `null` ถ้าไม่ได้ส่งมา
> ⚠️ `rooms_count` ใช้ `status='available'` (ต่างจาก `/availability-per-day` ที่ใช้ `status NOT IN (maintenance, reserved_closed)` — inconsistency ที่รู้กันอยู่)

---

### GET `/availability-per-day` — Per-day availability calendar

🔒 **Public**

คืนจำนวนห้องว่างรายวัน × ทุก room type สำหรับทำ calendar view (แต่ละค่า = จำนวนห้องที่ขายได้ "คืนนั้น"). ทำงานคู่กับ `/availability` ที่คืนเลขเดียวต่อ range.

**Query Params:**
- `start_date` (optional, date, ≥ today) — default: `today`
- `end_date` (optional, date, ≥ start_date) — default: `start_date + 6 เดือน` (~183 วัน)
- 🛡️ Cap: `(end_date − start_date) ≤ 365 คืน` (สูงสุด 366 วัน) — เกินปฏิเสธด้วย `422`

**Semantics:**
- แต่ละค่า = `max(0, total_rooms − occupied)` ของคืนนั้น
- `total_rooms` = ห้องที่ `status NOT IN (maintenance, reserved_closed)` (ตรงกับ `/bookings` createBooking)
- `occupied` = booking_rooms ที่ status ∈ (draft, confirmed, checked_in) และ overlap คืนนั้น — half-open `check_in ≤ D < check_out` (วัน check-out ว่างเสมอ)

**Response `200`:**
```json
{
  "status": "success",
  "message": "Per-day availability fetched successfully",
  "start_date": "2026-08-10",
  "end_date": "2026-08-12",
  "room_types": [
    {
      "room_type_id": "uuid",
      "name_en": "Superior",
      "name_th": "ห้องซูพีเรียร์",
      "rates": {
        "daily": {
          "general": "1000.00",
          "ku_member": "800.00"
        },
        "group": {
          "min_5_rooms": "750.00",
          "min_10_rooms": "750.00"
        },
        "monthly": "15000.00"
      },
      "2026-08-10": 39,
      "2026-08-11": 39,
      "2026-08-12": 40
    },
    {
      "room_type_id": "uuid",
      "name_en": "Deluxe",
      "name_th": "ห้องดีลักซ์",
      "rates": {
        "daily": {
          "general": "1200.00",
          "ku_member": "1000.00"
        },
        "group": {
          "min_5_rooms": "900.00",
          "min_10_rooms": "750.00"
        },
        "monthly": "18000.00"
      },
      "2026-08-10": 50,
      "2026-08-11": 50,
      "2026-08-12": 50
    }
  ]
}
```
> 💡 Key รายวัน (`YYYY-MM-DD`) เป็น dynamic ตามช่วงที่ส่งมา

**Response `422`:**
```json
// start_date ก่อนวันนี้ หรือ format วันที่ไม่ถูกต้อง
{
  "status": "error",
  "message": "The start date field must be a date after or equal to today.",
  "errors": { "start_date": ["The start date field must be a date after or equal to today."] }
}

// ช่วงเกิน 365 คืน
{
  "status": "error",
  "message": "Date range cannot exceed 366 days"
}
```

**ตัวอย่าง curl:**
```bash
curl -s -H "Accept: application/json" \
  "http://localhost:8000/api/v1/availability-per-day?start_date=2026-08-10&end_date=2026-08-12"
```

> ⚠️ **ข้อจำกัด (consistent กับ booking-time):** `total_rooms` คือ snapshot ณ วันนี้ของห้องที่ขายได้ (ไม่ใช่ future-aware maintenance schedule). ถ้าวันนี้ห้อง status=`maintenance` แต่อนาคตซ่อมเสร็อ calendar ก็ยังตัดห้องนั้นออก → ตรงกับการกดจองจริง ป้องกัน over-promise.

---

### GET `/availability-ranges` — Sold-out intervals per room type

🔒 **Public**

คืน intervals (ช่วงติดกัน) ของวันที่ห้องเต็ม (sold-out) **แยกราย room_type** ในรูป `{start_date, end_date}`. เบากว่า `/availability-per-day` เพราะไม่คืนจำนวนห้องว่างรายวัน — ใช้สำหรับปฏิทิน frontend disable วันที่จองไม่ได้เฉพาะประเภทนั้น.

**Query Params:** เหมือน `/availability-per-day` (`start_date`/`end_date` optional — default `today` → `+6 เดือน`, cap 365 คืน)

**Response `200`:**
```json
{
  "status": "success",
  "message": "Availability ranges fetched successfully",
  "start_date": "2026-08-10",
  "end_date": "2026-08-20",
  "room_types": [
    {
      "room_type_id": "uuid",
      "name_en": "Superior",
      "name_th": "ห้องซูพีเรียร์",
      "rates": {
        "daily": {
          "general": "1000.00",
          "ku_member": "800.00"
        },
        "group": {
          "min_5_rooms": "750.00",
          "min_10_rooms": "750.00"
        },
        "monthly": "15000.00"
      },
      "intervals": [
        { "start_date": "2026-08-12", "end_date": "2026-08-13" }
      ]
    }
  ]
}
```
> 💡 `total_rooms = 0` (type ที่ไม่มีห้องขาย) ไม่ถือว่า sold-out ที่นี่ → คืน `intervals: []` (frontend มัก disable เฉพาะวันที่เคยมีห้องแต่หมดแล้ว)

---

### GET `/unavailable-dates` — Flat list วันที่ sold-out ราย room type

🔒 **Public**

คืน flat list วันที่ **sold-out** **แยกราย room_type**. เหมือน `/availability-ranges` ทุกอย่าง ยกเว้น (1) คืนวันราบไม่กลุ่มติดกันเป็น interval. ใช้สำหรับปฏิทิน frontend disable วันที่จองไม่ได้เฉพาะประเภทนั้น.

> ⚠️ **BREAKING (2026-08-23):** เดิมคืน flat list `unavailable_dates` วันที่ **ทุก room type เต็มพร้อมกัน** (รวมทุกประเภทเป็น list เดียว) — ตอนนี้แยกราย room type ผ่าน `room_types[]` แทน ถ้า frontend อยากได้พฤติกรรมเดิม (วันที่จองไม่ได้เลย) ให้ intersect `unavailable_dates` ของทุก type ที่มีห้องขายฝั่ง client เอง

**Query Params:** เหมือน `/availability-per-day` (`start_date`/`end_date` optional — default `today` → `+6 เดือน`, cap 365 คืน)

**Semantics:**
- วัน "จองไม่ได้" ของ type = `total_rooms > 0 && occupied >= total_rooms` (sold-out logic เหมือน `/availability-ranges` เป๊ะ)
- `total_rooms` / `occupied` semantics เหมือน `/availability-per-day` ทุกอย่าง
- `total_rooms = 0` (type ที่ไม่มีห้องขาย) ไม่ถือว่า sold-out → คืน `unavailable_dates: []` (เหมือน `intervals: []` ของ `/availability-ranges`)

**Response `200`:**
```json
{
  "status": "success",
  "message": "Unavailable dates fetched successfully",
  "start_date": "2026-08-10",
  "end_date": "2026-08-20",
  "room_types": [
    {
      "room_type_id": "uuid",
      "name_en": "Superior",
      "name_th": "ห้องซูพีเรียร์",
      "rates": {
        "daily": {
          "general": "1000.00",
          "ku_member": "800.00"
        },
        "group": {
          "min_5_rooms": "750.00",
          "min_10_rooms": "750.00"
        },
        "monthly": "15000.00"
      },
      "unavailable_dates": ["2026-08-12", "2026-08-13"]
    }
  ]
}
```

**Response `422`:** validation errors — เหมือน `/availability-per-day`

**ตัวอย่าง curl:**
```bash
curl -s -H "Accept: application/json" \
  "http://localhost:8000/api/v1/unavailable-dates?start_date=2026-08-10&end_date=2026-08-20"
```

---

### GET `/unavailable-ranges` — Sold-out intervals ราย room type (auto window)

🔒 **Public**

คืน intervals (ช่วงติดกัน) ของวันที่ห้องเต็ม (sold-out) **แยกราย room_type** ในรูป `{start, end}` — เหมือน `/availability-ranges` แต่ **ไม่รับ query param**: endpoint คำนวณช่วงสแกนเอง ใช้สำหรับ frontend โหลด "วันที่จองไม่ได้" ทั้งระบบโดยไม่ต้องรู้ช่วงล่วงหน้า.

**Query Params:** ❌ ไม่รับ — ช่วงสแกนคำนวณอัตโนมัติ
- `start` = `today + 3 วัน`
- `end` = `max(check_out)` จาก `booking_rooms` ที่ `status IN (draft, confirmed, checked_in)` (booking ที่ยัง active — ชุดเดียวกับที่นับลด availability)
- **DoS guard:** ถ้า `end` ไกลเกินไป จะถูก clamp ที่ `start + 365 วัน` (กัน booking ปีหน้าทำให้ลูปสแกนยาว)

**Semantics:**
- ต่างจาก `/availability-ranges` ตรงที่ (1) ไม่รับ input, (2) key ของ interval เป็น **`{start, end}`** (ไม่ใช่ `{start_date, end_date}`)
- sold-out logic เหมือน `/availability-ranges` เป๊ะ: `total_rooms > 0 && occupied >= total_rooms`
- `total_rooms` / `occupied` semantics เหมือน `/availability-per-day` ทุกอย่าง
- **Edge case — ไม่มี booking เลย:** ไม่สามารถ lock `end` ของ window ได้ → คืน `start: null`, `end: null`, และ `intervals: []` ทุก room type
- **Edge case — ทุก booking checkout ก่อน today+3:** window กลายเป็น `end < start` → `CarbonPeriod` ว่าง → `intervals: []` โดยธรรมชาติ (คืน `start`/`end` ตามจริง)

**Response `200` (มี booking):**
```json
{
  "status": "success",
  "message": "Unavailable ranges fetched successfully",
  "start": "2026-08-17",
  "end": "2026-09-05",
  "room_types": [
    {
      "room_type_id": "uuid",
      "name_en": "Superior",
      "name_th": "ห้องซูพีเรียร์",
      "rates": {
        "daily": {
          "general": "1000.00",
          "ku_member": "800.00"
        },
        "group": {
          "min_5_rooms": "750.00",
          "min_10_rooms": "750.00"
        },
        "monthly": "15000.00"
      },
      "intervals": [
        { "start": "2026-08-20", "end": "2026-08-22" },
        { "start": "2026-08-25", "end": "2026-08-27" }
      ]
    }
  ]
}
```

**Response `200` (ไม่มี booking เลย):**
```json
{
  "status": "success",
  "message": "Unavailable ranges fetched successfully",
  "start": null,
  "end": null,
  "room_types": [
    {
      "room_type_id": "uuid",
      "name_en": "Superior",
      "name_th": "ห้องซูพีเรียร์",
      "rates": {
        "daily": {
          "general": "1000.00",
          "ku_member": "800.00"
        },
        "group": {
          "min_5_rooms": "750.00",
          "min_10_rooms": "750.00"
        },
        "monthly": "15000.00"
      },
      "intervals": []
    }
  ]
}
```
> 💡 `total_rooms = 0` (type ที่ไม่มีห้องขาย) ไม่ถือว่า sold-out → คืน `intervals: []` เสมอ (เหมือน `/availability-ranges`)
> ⚠️ ไม่มี response `422` เพราะไม่มี input ให้ validate

**ตัวอย่าง curl:**
```bash
curl -s -H "Accept: application/json" \
  "http://localhost:8000/api/v1/unavailable-ranges"
```

---

### GET `/mock/availability-ranges` — Mock sold-out intervals (Frontend testing)

🚧 **DRAFT / TESTING** · 🔒 **Public**

> 🗑️ **Deletion plan:** ลบ endpoint นี้ (route + `MockController` + tests + section นี้) เมื่อ frontend ย้ายไปใช้ `/availability-ranges` จริงแล้ว — **อย่าปล่อยขึ้น production**

Mock data ของ sold-out intervals ราย room type สำหรับ frontend นำไปใช้พัฒนา/ทดสอบ UI ปฏิทินโดยไม่ต้องเซ็ตอัป DB หรือสร้าง booking ล่วงหน้า. วันที่คำนวณสัมพันธ์กับ `today` เสมอ (ไม่มีวันหมดอายุ).

**Query Params:** ❌ ไม่รับ — ข้อมูลถูกจำลองคงที่

**Mock Data Scenario:**
- Window: `start_date` = `today`, `end_date` = `today + 30 วัน`
- **Superior** (`00000000-0000-4000-8000-000000000001`): 2 intervals → `[today+5, today+8]`, `[today+15, today+18]`
- **Deluxe** (`00000000-0000-4000-8000-000000000002`): 1 interval → `[today+10, today+12]`
- **Suite** (`00000000-0000-4000-8000-000000000003`): 0 intervals → `[]` (จำลองกรณีห้องว่างตลอดทั้งเดือน)

**Response `200`:**
```json
{
  "status": "success",
  "message": "Availability ranges fetched successfully",
  "start_date": "2026-08-24",
  "end_date": "2026-09-23",
  "room_types": [
    {
      "room_type_id": "00000000-0000-4000-8000-000000000001",
      "name_en": "Superior",
      "name_th": "ห้องซูพีเรียร์",
      "intervals": [
        { "start_date": "2026-08-29", "end_date": "2026-09-01" },
        { "start_date": "2026-09-08", "end_date": "2026-09-11" }
      ]
    },
    {
      "room_type_id": "00000000-0000-4000-8000-000000000002",
      "name_en": "Deluxe",
      "name_th": "ห้องดีลักซ์",
      "intervals": [
        { "start_date": "2026-09-03", "end_date": "2026-09-05" }
      ]
    },
    {
      "room_type_id": "00000000-0000-4000-8000-000000000003",
      "name_en": "Suite",
      "name_th": "ห้องสวีท",
      "intervals": []
    }
  ]
}
```

**ตัวอย่าง curl:**
```bash
curl -s -H "Accept: application/json" \
  "http://localhost:8000/api/v1/mock/availability-ranges"
```

---

## Bookings (Core)

### GET `/bookings` — List bookings

🔒 **Auth required**

- **Admin**: sees all bookings
- **User**: sees only own bookings

**Query Params:**

| Param       | Type   | Description                                          |
|-------------|--------|------------------------------------------------------|
| `term`      | string | Search by user name, UUID, or guest name             |
| `check_in`  | date   | Filter start date                                    |
| `check_out` | date   | Filter end date                                      |
| `room_type` | uuid   | Filter by room type (or `all` for no filter — default)|
| `per_page`  | int    | Items per page (default: 15)                         |

**Response `200`:**
```json
{
  "status": "success",
  "message": "ดึงข้อมูลสำเร็จแล้วค่ะนายท่าน! ✨",
  "user": "requester-uuid",
  "search_criteria": {
    "term": null,
    "check_in": null,
    "check_out": null,
    "room_type": "all"
  },
  "bookings": [
    {
      "id": "booking-uuid",
      "user_id": "user-uuid",
      "confirmation": "202606-00001",
      "source": "online",
      "status": "draft",
      "total_amount": 2400,
      "is_paid": false,
      "payment_deadline": "2026-06-19T11:00:00.000000Z",
      "created_at": "...",
      "updated_at": "...",
      "booking_rooms": [
        {
          "id": "br-uuid",
          "booking_id": "booking-uuid",
          "room_type_id": "rt-uuid",
          "room_id": null,
          "check_in": "2026-06-20",
          "check_out": "2026-06-22",
          "guests": [
            { "title": "Mr.", "name": "Somchai", "nationality": "Thai" }
          ],
          "billing_address": null,
          "billing_comment": null,
          "addon": { ...addon... }
        }
      ],
      "user": { ...user object (admin only)... }
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

---

### POST `/bookings` — Create booking

🔒 **Auth required** · ⏱ Rate-limited: 5 requests/minute

> ⚠️ **Key constraint**: User cannot have another active `draft` booking (with unexpired payment deadline).

**Request Body:**
```json
{
  "source": "online",
  "booking_rooms": [
    {
      "room_type_id": "rt-uuid",
      "check_in": "2026-06-20",
      "check_out": "2026-06-22",
      "guests": [
        {
          "title": "Mr.",
          "firstName": "Somchai",
          "lastName": "Jaidee",
          "email": "somchai.j@ku.th",
          "phone": "0812345678",
          "nationality": "Thai"
        }
      ],
      "billing_address": null,
      "billing_comment": null,
      "bed_preference": "king_size",
      "addons": {
        "extra_bed": 0,
        "breakfast": 2,
        "early_checkin": 2,
        "late_checkout": 0
      }
    },
    {
      "room_type_id": "rt-uuid",
      "check_in": "2026-06-21",
      "check_out": "2026-06-23"
    }
  ]
}
```

> 🌟 **Refactor (02/07/26)**: `check_in`/`check_out` moved from booking-level to **per-room** (`booking_rooms.*`). Each room can now have its own dates. To book multiple rooms with identical dates, set the same dates on each entry.  
> 🌟 **Refactor (20/08/26)**: Guest names support `firstName` and `lastName` (or `first_name`/`last_name`), alongside optional `email` and `phone`.  
> 🏨 **Phase 1**: `bed_preference` รองรับ `king_size` หรือ `null` (default: any) — เป็น hard constraint สำหรับ allocation algorithm (`king_size` = ห้องชั้น 8; 🌟 rename 27/08/26 เดิม `twin`)  
> 🕐 **(27/08/26)**: `early_checkin` และ `late_checkout` เปลี่ยนเป็น **integer (0–7 ชม.)** คิดราคาแบบรายชั่วโมง (ราคา = ชม. × rate/ชม., สูงสุด 7 ชม.).  
> ⚠️ **Breaking Change**: การส่ง boolean `true`/`false` จะได้ `422 Unprocessable Content`. ค่า boolean บน booking_room response ถูกยกเลิก — ดูจาก `addon.early_hours` / `addon.late_hours` แทน  
> 🔧 **(01/09/26)**: รับ alias `addons.early_hours` / `addons.late_hours` (ชื่อ column ใน DB — frontend ส่งมาแบบนี้) แล้ว `early_checkin`/`late_checkout` ชนะเสมอถ้าส่งทั้งคู่. ส่ง `addons` มาแบบ partial (ไม่ส่ง key ไหน) = key นั้น **คงค่าเดิม** จากแถว addon ไม่ reset เป็น 0. ราคาที่ client ส่งมา (`early_checkIn_price` ฯลฯ) ถูก ignore และคิดใหม่ฝั่ง server เสมอ
> 🛏️ **(04/09/26) Input format = Output format**: เตียงเสริมย้ายเข้า `addons` object — canonical คือ `booking_rooms.*.addons.extra_bed` (ตรงกับ `addon.extra_bed` ตอน response). `booking_rooms.*.extra_beds` (หัวห้อง) ยังส่งได้ในฐานะ **legacy alias** (backward-compat) แต่ถ้าส่งมาทั้งคู่ **canonical ชนะเสมอ**. ราคา (`extra_bed_price`) server คิดจาก `global_rates` เสมอ — client ส่งราคาไม่ได้

**Validation Rules:**

| Field                                       | Rule                                          |
|---------------------------------------------|-----------------------------------------------|
| `source`                                    | required, in: `online`, `admin`, `line`       |
| `booking_rooms`                             | required, array                               |
| `booking_rooms.*.room_type_id`              | required, uuid, exists in room_types          |
| `booking_rooms.*.check_in`                  | required, date, ≥ today                       |
| `booking_rooms.*.check_out`                 | required, date, > booking_rooms.*.check_in    |
| `booking_rooms.*.addons.extra_bed`          | nullable, integer, min 0 (canonical 🛏️)       |
| `booking_rooms.*.extra_beds`                | nullable, integer, min 0 (⚠️ legacy alias — canonical ชนะ) |
| `booking_rooms.*.guests`                    | nullable, array                               |
| `booking_rooms.*.guests.*.title`            | nullable, string, max 50                      |
| `booking_rooms.*.guests.*.firstName`        | nullable, string, max 255 (or `first_name`)   |
| `booking_rooms.*.guests.*.lastName`         | nullable, string, max 255 (or `last_name`)    |
| `booking_rooms.*.guests.*.name`             | nullable, string, max 255 (legacy fallback)   |
| `booking_rooms.*.guests.*.email`            | nullable, string, email, max 255              |
| `booking_rooms.*.guests.*.phone`            | nullable, string, max 50                      |
| `booking_rooms.*.guests.*.nationality`      | nullable, string, max 100                     |
| `booking_rooms.*.bed_preference`            | nullable, in: `king_size` (ห้องชั้น 8)        |
| `booking_rooms.*.billing_address`           | nullable, string, max 255                     |
| `booking_rooms.*.billing_comment`           | nullable, string, max 255                     |
| `booking_rooms.*.addons.breakfast`          | nullable, integer, min 0                      |
| `booking_rooms.*.addons.early_checkin`      | nullable, integer (0–7)                       |
| `booking_rooms.*.addons.late_checkout`      | nullable, integer (0–7)                       |
| `booking_rooms.*.addons.early_hours`        | nullable, integer (0–7) — alias ของ `early_checkin` |
| `booking_rooms.*.addons.late_hours`         | nullable, integer (0–7) — alias ของ `late_checkout` |

> 💡 **Pricing**: Server calculates all prices from `global_rates` and `global_rates.default_price` for addons. Client **cannot** send prices (prevents manipulation). Each entry in `booking_rooms` = exactly 1 room (no `quantity` multiplier — to book N identical rooms, send N entries).
>
> 🌟 **KU Member Pricing (07/09/26)**: สำหรับผู้ใช้ที่มี role `ku_member` ระบบจะใช้เรท `rate_type='daily_ku'` จาก `global_rates` คิดเงินโดยอัตโนมัติ (หากไม่มีหรือ inactive จะ fallback ไป `daily`) ส่วนผู้ใช้ทั่วไปจะใช้เรท `rate_type='daily'`. (`group` / `monthly` ยังคงเป็น display-only)

**Response `201`:**
```json
{
  "status": "success",
  "message": "Booking and Add-ons created successfully",
  "booking_id": "booking-uuid",
  "confirmation": "202608-00001",
  "total_amount": 2600,
  "payment_deadline": "2026-06-20T11:00:00.000000Z",
  "user_id": "user-uuid",
  "booking_rooms": [
    {
      "id": "br-uuid",
      "booking_id": "booking-uuid",
      "room_type_id": "rt-uuid",
      "room_id": null,
      "check_in": "2026-06-20",
      "check_out": "2026-06-22",
      "status": "draft",
      "bed_preference": "king_size",
      "guests": [
        {
          "title": "Mr.",
          "firstName": "Somchai",
          "lastName": "Jaidee",
          "email": "somchai.j@ku.th",
          "phone": "0812345678",
          "nationality": "Thai"
        }
      ],
      "billing_address": null,
      "billing_comment": null,
      "room_amount": 2000,
      "discount_amount": 0,
      "amount": 2600,
      "created_at": "2026-06-19T11:00:00.000000Z",
      "updated_at": "2026-06-19T11:00:00.000000Z",
      "addon": {
        "id": "addon-uuid",
        "booking_room_id": "br-uuid",
        "extra_bed": 0,
        "breakfast": 2,
        "early_checkIn_price": 200,
        "early_hours": 2,
        "late_checkOut_price": 0,
        "late_hours": 0,
        "extra_bed_price": 0,
        "breakfast_price": 400,
        "created_at": "2026-06-19T11:00:00.000000Z",
        "updated_at": "2026-06-19T11:00:00.000000Z"
      }
    }
  ]
}
```

**Response `422` (business rule failures):**
```json
{
  "status": "error",
  "message": "มีรายการจองที่รอชำระเงินอยู่ค่ะ กรุณาทำรายการเดิมให้เสร็จสิ้นก่อนนะคะ"
}
```

```json
{
  "status": "error",
  "message": "ขออภัยค่ะนายท่าน ห้องพักประเภทที่เลือกเต็มแล้วในช่วงเวลาดังกล่าวค่ะ"
}
```

---

### POST `/bookings/{bookingId}/rooms` — Add rooms to an existing booking

🌟 **(10/08/26)**: เพิ่มห้องเข้า booking ที่สร้างไว้แล้ว (**เฉพาะ `draft` state**) — reuse logic จาก `POST /bookings` (availability check + pricing + create BookingRoom/Addons) แต่ไม่สร้าง Booking ใหม่

🔒 **Auth required** · Ownership: **booking owner** or **admin** · ⏱ Rate-limited: 5 requests/minute

**Constraints:**
- Booking `status` ต้องเป็น `draft` เท่านั้น (จ่ายเงิน/confirm ไปแล้วเพิ่มไม่ได้) → `422`
- Availability check นับที่ BR-level (`draft`/`confirmed`/`checked_in` ที่ overlap) — **รวมห้องที่อยู่ใน booking นี้แล้วด้วย** กันจองเกิน capacity ตอนเพิ่มซ้ำ
- ไม่เรียก `RoomAllocator` — ห้องใหม่ถูกสร้างด้วย `room_id = null`, `status = 'draft'` (initial state รอจ่ายห้องตอน assign/check-in)
- `payment_deadline` **ไม่เปลี่ยน** (คง deadline เดิมของ booking) · `total_amount` **accumulate** (ยอดเดิม + ราคาห้องใหม่)

**Request Body** — โครงสร้างเหมือน `POST /bookings` เป๊ะ ยกเว้น**ไม่มี field `source`** (booking สร้างไปแล้ว):
```json
{
  "booking_rooms": [
    {
      "room_type_id": "rt-uuid",
      "check_in": "2026-08-20",
      "check_out": "2026-08-22",
      "bed_preference": "king_size",
      "guests": [
        {
          "title": "Mr.",
          "name": "Somchai Jaidee",
          "nationality": "Thai"
        }
      ],
      "billing_address": null,
      "billing_comment": null,
      "addons": {
        "extra_bed": 0,
        "breakfast": 2,
        "early_checkin": 0,
        "late_checkout": 0
      }
    }
  ]
}
```

**Validation Rules:** เหมือน `POST /bookings` ทุก field ของ `booking_rooms.*` (ดูตารางด้านบน) — 1 array entry = 1 ห้อง (ไม่มี `quantity`) · `check_in ≥ today` · `check_out > check_in` (รายห้อง)

> 💡 **Pricing**: Server คำนวณราคาจาก `global_rates` ทั้งหมด — client ส่งราคาเองไม่ได้ (เหมือน createBooking)

**Response `200`:**
```json
{
  "status": "success",
  "message": "เพิ่มห้องเข้าการจองเรียบร้อยแล้วค่ะ",
  "booking_id": "booking-uuid",
  "booking_rooms": [
    {
      "id": "new-br-uuid",
      "booking_id": "booking-uuid",
      "room_type_id": "rt-uuid",
      "room_id": null,
      "check_in": "2026-08-20",
      "check_out": "2026-08-22",
      "status": "draft",
      "guests": [
        {
          "title": "Mr.",
          "firstName": "Somchai",
          "lastName": "Jaidee",
          "email": "somchai.j@ku.th",
          "phone": "0812345678",
          "nationality": "Thai"
        }
      ],
      "room_amount": 2000,
      "discount_amount": 0,
      "amount": 2400,
      "addon": {
        "id": "addon-uuid",
        "booking_room_id": "new-br-uuid",
        "extra_bed": 0,
        "breakfast": 2,
        "early_checkIn_price": 0,
        "early_hours": 0,
        "late_checkOut_price": 0,
        "late_hours": 0,
        "breakfast_price": 400
      }
    }
  ],
  "added_amount": 2400,
  "total_amount": 4800,
  "payment_deadline": "2026-08-17 18:00:00"
}
```

**Errors:**

| Code | Cause                                              |
|------|----------------------------------------------------|
| 401  | ไม่ได้ล็อกอิน                                       |
| 403  | ไม่ใช่เจ้าของ booking และไม่ใช่ admin (`คุณไม่มีสิทธิ์แก้ไขการจองนี้ค่ะ`) |
| 404  | ไม่พบ booking ที่ระบุ                                |
| 422  | Booking ไม่ได้อยู่ในสถานะ draft / ห้องเต็มในช่วงวันนั้น   |
| 500  | Unexpected (ซ่อน message จริง + `Log::error`)          |

---

### PUT `/bookings/{bookingId}/rooms/{bookingRoomId}` — Update a booking room

🌟 **(17/08/26)**: แก้ไข booking room **รายห้อง** — ใช้ได้เฉพาะเมื่อ BR เป็น `draft` **และ** parent booking เป็น `draft` เท่านั้น

🔒 **Auth required** · Ownership: **booking owner** or **admin** · ⏱ Rate-limited: 5 requests/minute

**Constraints:**
- Booking `status = 'draft'` + BookingRoom `status = 'draft'` เท่านั้น → `422`
- แก้ได้ทุก field ของห้อง (วันที่ / ประเภทห้อง / guests / addons) — **ยกเว้น** `room_id` (ต้องผ่าน RoomAllocator) และ `status` (ต้องผ่าน transitionStatus)
- ถ้าแก้ `room_type_id`/`check_in`/`check_out` → **เช็ค availability ใหม่** (นับ existing overlap โดยตัดห้องตัวเองออกจาก count) → เต็ม = `422`
- **ราคาคิดใหม่ทั้งหมดที่ server** จาก `global_rates` (room rate × nights + extra_bed + addons) แล้ว update กลับลง `addons` row + คำนวณ `total_amount` ของ booking ใหม่ทั้งใบ
- ⚡ **Smart Diffing**: ตรวจสอบ field ที่เปลี่ยนจริง (รวมถึง date casts/boolean casts) — ถ้าวันที่และประเภทห้องเหมือนเดิมในฐานข้อมูล จะข้ามการเช็ค availability อันหนักหน่วง และจะสั่ง Execute SQL เฉพาะเมื่อมีข้อมูลเปลี่ยนแปลงจริง (ลดภาระ DB)
- `payment_deadline` **ไม่เปลี่ยน** (เหมือน addRooms)
- BR ต้องอยู่ใต้ booking ที่ระบุจริง — ใส่ BR id ของ booking อื่น = `404`

**Request Body** (flat — แก้ทีละห้อง, ส่งเฉพาะ field ที่จะแก้):
```json
{
  "check_in": "2026-08-22",
  "check_out": "2026-08-25",
  "guests": [
    { "title": "Mr.", "name": "Somchai Jaidee", "nationality": "Thai" }
  ],
  "bed_preference": "king_size",
  "billing_address": null,
  "billing_comment": null,
  "addons": { "extra_bed": 1, "breakfast": 2, "early_checkin": 0, "late_checkout": 0 }
}
```

**Validation Rules:**

| Field | Rule |
|-------|------|
| `room_type_id` | `sometimes` uuid exists:room_types,id |
| `check_in` | `sometimes` date `after_or_equal:today` |
| `check_out` | `sometimes` date `after:check_in` |
| `addons.extra_bed` | nullable integer ≥ 0 (canonical 🛏️) |
| `extra_beds` | nullable integer ≥ 0 (⚠️ legacy alias — canonical ชนะ) |
| `guests.*` | เหมือน `POST /bookings` |
| `bed_preference` | nullable `in:king_size` (ห้องชั้น 8) |
| `billing_address` / `billing_comment` | nullable string ≤ 255 |
| `addons.breakfast` | nullable integer ≥ 0 |
| `addons.early_checkin` / `addons.late_checkout` | nullable integer (0–7) |
| `addons.early_hours` / `addons.late_hours` | nullable integer (0–7) — alias (canonical ชนะถ้าส่งทั้งคู่) |

> 🔧 **(01/09/26)** Update semantics เป็น key-level PATCH: ส่ง `addons` มาเฉพาะบาง key = key ที่ไม่ส่ง **คงค่าเดิม** จากแถว addon (ไม่ reset เป็น 0) — จะปิด addon ต้องส่ง `0` ชัดๆ

**Response `200`:**
```json
{
  "status": "success",
  "message": "แก้ไขห้องเรียบร้อยแล้วค่ะ",
  "booking_id": "booking-uuid",
  "booking_room": {
    "id": "br-uuid-1",
    "booking_id": "booking-uuid",
    "room_type_id": "rt-uuid-1",
    "room_id": null,
    "check_in": "2026-08-22T00:00:00.000000Z",
    "check_out": "2026-08-25T00:00:00.000000Z",
    "guests": [
      {
        "title": "Mr.",
        "name": "Somchai Jaidee",
        "nationality": "Thai"
      }
    ],
    "billing_address": null,
    "billing_comment": null,
    "status": "draft",
    "bed_preference": "king_size",
    "created_at": "2026-08-19T02:23:13.000000Z",
    "updated_at": "2026-08-19T02:23:13.000000Z",
    "addon": {
      "id": "addon-uuid-1",
      "booking_room_id": "br-uuid-1",
      "extra_bed": 1,
      "breakfast": 2,
      "early_checkIn_price": 0,
      "early_hours": 0,
      "late_checkOut_price": 0,
      "late_hours": 0,
      "extra_bed_price": 300,
      "breakfast_price": 300,
      "created_at": "...",
      "updated_at": "..."
    }
  },
  "total_amount": 4900
}
```

**Errors:**

| Code | Cause |
|------|-------|
| 401  | ไม่ได้ล็อกอิน |
| 403  | ไม่ใช่เจ้าของ booking และไม่ใช่ admin |
| 404  | ไม่พบ booking / ไม่พบ BR / BR ไม่ได้อยู่ใต้ booking นี้ |
| 422  | Booking หรือ BR ไม่ใช่ draft / ห้องเต็มในช่วงวันใหม่ / validation |
| 500  | Unexpected (ซ่อน message จริง + `Log::error`) |

---

### PUT `/bookings/{bookingId}/rooms` — Batch update booking rooms

🌟 **(19/08/26)**: แก้ไข booking room **หลายห้องพร้อมกัน** — payload ต่างกันได้รายห้อง (1 array entry = การแก้ 1 ห้อง ระบุ `booking_room_id` ของตัวเอง) · **all-or-nothing**: ห้องใดห้องหนึ่ง fail = rollback ทั้งชุด

🔒 **Auth required** · Ownership: **booking owner** or **admin** · ⏱ Rate-limited: 5 requests/minute

> 🌟 Route นี้ (path ลงท้าย `/rooms` ไม่มี id) อยู่คู่กับ `PUT .../rooms/{bookingRoomId}` แบบรายห้อง ซึ่งยังใช้งานเหมือนเดิม

**Constraints:**
- Booking `status = 'draft'` + **ทุก** BookingRoom ใน batch เป็น `draft` เท่านั้น → `422`
- แก้ได้ทุก field เหมือนรายห้อง (วันที่ / ประเภทห้อง / guests / addons) — **ยกเว้น** `room_id` / `status`
- `booking_room_id` ซ้ำใน batch เดียวกันไม่ได้ (validation `distinct`) → `422`
- ถ้าห้องใดแก้ `room_type_id`/`check_in`/`check_out` → **เช็ค availability ใหม่จาก final state ของทั้ง batch**: existing count ตัดทุกห้องใน batch ออก + นับ batch overlaps รวมห้องที่ไม่ได้เปลี่ยน shape → เต็ม = `422` ทั้งชุด
- วันที่ตรวจจาก **effective values** (ค่าใหม่ถ้าส่งมา ไม่งั้นค่าเดิม) — ส่งแต่ `check_in` ทับ `check_out` เดิม (หรือกลับกัน) ก็ถูกจับ → `422`
- **ราคาคิดใหม่ทั้งหมดที่ server** รายห้อง + คำนวณ `total_amount` ของ booking ใหม่ทั้งใบ (ครั้งเดียว)
- ⚡ **Smart Diffing**: เทียบข้อมูลใหม่กับ DB จริง (รวม cast วันที่/boolean) — ห้องที่ข้อมูลไม่ต่างจากเดิมจะไม่เรียก SQL Update เลย, และถ้าไม่มีการเปลี่ยน shape (วันที่/ห้อง) ในทั้งชุด ก็จะข้ามการเช็ค availability
- `payment_deadline` **ไม่เปลี่ยน** (เหมือน addRooms)
- ทุก BR ต้องอยู่ใต้ booking ที่ระบุจริง — ใส่ BR id ของ booking อื่นสักอันเดียว = `404` ทั้ง batch (ไม่มีอะไรถูกแก้)

**Request Body** (ส่งเฉพาะ field ที่จะแก้รายห้อง):
```json
{
  "booking_rooms": [
    {
      "booking_room_id": "br-uuid-1",
      "check_in": "2026-08-22",
      "check_out": "2026-08-25"
    },
    {
      "booking_room_id": "br-uuid-2",
      "guests": [
        { "title": "Mr.", "name": "Somchai Jaidee", "nationality": "Thai" }
      ],
      "addons": { "breakfast": 2 }
    }
  ]
}
```

**Validation Rules:**

| Field | Rule |
|-------|------|
| `booking_rooms` | required, array, ≥ 1 entry |
| `booking_rooms.*.booking_room_id` | required, uuid, **distinct** (ห้ามซ้ำใน batch) |
| `booking_rooms.*.room_type_id` | `sometimes` uuid exists:room_types,id |
| `booking_rooms.*.check_in` | `sometimes` date `after_or_equal:today` |
| `booking_rooms.*.check_out` | `sometimes` date `after:booking_rooms.*.check_in` (+ effective-dates guard ใน controller ครอบเคส partial update) |
| `booking_rooms.*.addons.extra_bed` | nullable integer ≥ 0 (canonical 🛏️) |
| `booking_rooms.*.extra_beds` | nullable integer ≥ 0 (⚠️ legacy alias — canonical ชนะ) |
| `booking_rooms.*.guests.*` | เหมือน `POST /bookings` |
| `booking_rooms.*.bed_preference` | nullable `in:king_size` (ห้องชั้น 8) |
| `booking_rooms.*.billing_address` / `booking_rooms.*.billing_comment` | nullable string ≤ 255 |
| `booking_rooms.*.addons.breakfast` | nullable integer ≥ 0 |
| `booking_rooms.*.addons.early_checkin` / `booking_rooms.*.addons.late_checkout` | nullable integer (0–7) |
| `booking_rooms.*.addons.early_hours` / `booking_rooms.*.addons.late_hours` | nullable integer (0–7) — alias (canonical ชนะถ้าส่งทั้งคู่) |

> 🔧 **(01/09/26)** เหมือนรายห้อง: รับ alias hours + key-level PATCH (key ที่ไม่ส่งใน `addons` = คงค่าเดิม)

**Response `200`:**
```json
{
  "status": "success",
  "message": "แก้ไขห้องเรียบร้อยแล้วค่ะ",
  "booking_id": "booking-uuid",
  "booking_rooms": [
    {
      "id": "br-uuid-1",
      "booking_id": "booking-uuid",
      "room_type_id": "rt-uuid-1",
      "room_id": null,
      "check_in": "2026-08-22T00:00:00.000000Z",
      "check_out": "2026-08-25T00:00:00.000000Z",
      "guests": [ ... ],
      "billing_address": null,
      "billing_comment": null,
      "status": "draft",
      "bed_preference": "king_size",
      "created_at": "...",
      "updated_at": "...",
      "addon": {
        "id": "addon-uuid-1",
        "booking_room_id": "br-uuid-1",
        "extra_bed": 1,
        "breakfast": 0,
        "early_checkIn_price": 0,
        "early_hours": 0,
        "late_checkOut_price": 0,
        "late_hours": 0,
        "extra_bed_price": 300,
        "breakfast_price": 0,
        "created_at": "...",
        "updated_at": "..."
      }
    },
    {
      "id": "br-uuid-2",
      "booking_id": "booking-uuid",
      "room_type_id": "rt-uuid-2",
      "room_id": null,
      "check_in": "2026-08-22T00:00:00.000000Z",
      "check_out": "2026-08-23T00:00:00.000000Z",
      "guests": [ ... ],
      "billing_address": null,
      "billing_comment": null,
      "status": "draft",
      "bed_preference": null,
      "created_at": "...",
      "updated_at": "...",
      "addon": {
        "id": "addon-uuid-2",
        "booking_room_id": "br-uuid-2",
        "extra_bed": 0,
        "breakfast": 2,
        "early_checkIn_price": 0,
        "early_hours": 0,
        "late_checkOut_price": 0,
        "late_hours": 0,
        "extra_bed_price": 0,
        "breakfast_price": 300,
        "created_at": "...",
        "updated_at": "..."
      }
    }
  ],
  "total_amount": 7600
}
```

**Errors:**

| Code | Cause |
|------|-------|
| 401  | ไม่ได้ล็อกอิน |
| 403  | ไม่ใช่เจ้าของ booking และไม่ใช่ admin |
| 404  | ไม่พบ booking / มี BR อย่างน้อย 1 อันที่ไม่ได้อยู่ใต้ booking นี้ |
| 422  | Booking หรือ BR บางห้องไม่ใช่ draft / ห้องเต็มในช่วงวันใหม่ (final state ทั้ง batch) / `booking_room_id` ซ้ำ / validation |
| 500  | Unexpected (ซ่อน message จริง + `Log::error`) |

---

### DELETE `/bookings/{bookingId}/rooms/{bookingRoomId}` — Remove a booking room

🌟 **(17/08/26)**: ลบห้องออกจาก draft booking — hard delete BR + Addon แล้วคำนวณ `total_amount` ใหม่

🔒 **Auth required** · Ownership: **booking owner** or **admin** · ⏱ Rate-limited: 5 requests/minute

**Constraints:**
- Booking `status = 'draft'` + BookingRoom `status = 'draft'` เท่านั้น → `422`
- **ห้องสุดท้ายของ booking ลบไม่ได้** → `422` (ให้ใช้ `DELETE /bookings/{bookingId}` ลบทั้ง booking แทน — กันเกิด draft เปล่าที่ไปล็อกโควตา "มี draft ค้าง" ของผู้ใช้)
- เขียน audit log `booking_room: draft → deleted` ใน `status_change_logs` ก่อนลบ
- BR ต้องอยู่ใต้ booking ที่ระบุจริง — ใส่ BR id ของ booking อื่น = `404`

**Response `200`:**
```json
{
  "status": "success",
  "message": "ลบห้องออกจากการจองเรียบร้อยแล้วค่ะ",
  "booking_id": "booking-uuid",
  "remaining_rooms": 1,
  "total_amount": 3000
}
```

**Errors:**

| Code | Cause |
|------|-------|
| 401  | ไม่ได้ล็อกอิน |
| 403  | ไม่ใช่เจ้าของ booking และไม่ใช่ admin |
| 404  | ไม่พบ booking / ไม่พบ BR / BR ไม่ได้อยู่ใต้ booking นี้ |
| 422  | Booking หรือ BR ไม่ใช่ draft / เป็นห้องสุดท้ายของ booking |
| 500  | Unexpected (ซ่อน message จริง + `Log::error`) |

---

### DELETE `/bookings/{bookingId}` — Delete a draft booking

🌟 **(17/08/26)**: เจ้าของ (หรือ admin) ลบ draft booking ของตัวเองได้ — hard delete cascade แบบเดียวกับ `CleanupExpiredDrafts` (Addon ทุกห้อง → BookingRoom ทุกห้อง → payments → confirmations → Booking)

🔒 **Auth required** · Ownership: **booking owner** or **admin** · ⏱ Rate-limited: 5 requests/minute

**Constraints:**
- Booking `status = 'draft'` เท่านั้น (จ่ายเงิน/ยืนยันแล้วลบไม่ได้) → `422`
- เป็น **hard delete** ไม่ใช่ transition ใหม่ใน state machine (ไม่มี `cancelled` เหมือนเดิม) — แต่เขียน audit log `booking: draft → deleted` เก็บไว้ใน `status_change_logs` ก่อนลบ
- ทำใน `DB::transaction` + `lockForUpdate` + re-check status — กัน race กับ confirm/verify ที่กำลังเปลี่ยน status พร้อมกัน

**Response `200`:**
```json
{
  "status": "success",
  "message": "ลบรายการจองเรียบร้อยแล้วค่ะนายท่าน 🗑️",
  "booking_id": "booking-uuid"
}
```

**Errors:**

| Code | Cause |
|------|-------|
| 401  | ไม่ได้ล็อกอิน |
| 403  | ไม่ใช่เจ้าของ booking และไม่ใช่ admin |
| 404  | ไม่พบ booking ที่ระบุ |
| 422  | Booking ไม่ได้อยู่ในสถานะ draft |
| 500  | Unexpected (ซ่อน message จริง + `Log::error`) |

---

### POST `/bookings/{id}/confirm` — Submit payment confirmation

🌟 **Refactor (24/07/26)**: User ส่งหลักฐานการชำระ (slip + time) → สร้าง `booking_confirmations` row + booking `draft → paid`. Replaces deprecated webhook flow.
🌟 **Refactor (25/08/26)**: booking `draft → pending` แทน (mirror กับ confirmation) — `paid` + `is_paid=true` จะเกิดตอน admin **verify** เท่านั้น
🌟 **Refactor (19/08/26)**: ลบ `payment_method` ออก — flow เหลือ "ส่งสลิป → รอแอดมินตรวจ" อย่างเดียว (`slip_image` บังคับเสมอ, `transfer_time` optional)
🌟 **Refactor (19/08/26 #2)**: สลิปย้ายไปเก็บบน **private disk** (`storage/app/private/slips/` — เว็บเปิดตรงๆ ไม่ได้) + metadata ลง `images` table (polymorphic) · ดูรูปผ่าน **signed URL อายุ 15 นาที** เท่านั้น (ดู [`GET /images/{id}/file`](#get-imagesidfile--serve-image-via-signed-url))

🔒 **Auth required** · Ownership: User (owner) or admin · **Throttle**: `5,1`

**Content-Type**: `multipart/form-data` (เพราะมีไฟล์ slip)

**Request Body**:

| Field             | Type      | Required | Description                                   |
|-------------------|-----------|----------|-----------------------------------------------|
| `slip_image`      | file      | ✅       | ไฟล์สลิป (jpeg/png/jpg, max 4MB) — บังคับเสมอ |
| `transfer_time`   | datetime  | ❌       | เวลาที่ลูกค้าแจ้งโอน (จากสลิป), ไม่ใช่อนาคต |

**Example**:
```bash
curl -X POST /api/v1/bookings/{id}/confirm \
  -H "Authorization: Bearer <token>" \
  -F "slip_image=@slip.jpg" \
  -F "transfer_time=2026-07-24T10:30:00Z"
```

**Response 201**:
```json
{
  "status": "success",
  "message": "ส่งหลักฐานการชำระเรียบร้อย — รอแอดมินตรวจสอบค่ะนายท่าน",
  "confirmation_id": "confirmation-uuid",
  "confirmation_status": "pending",
  "booking_status": "pending",
  "slip_image_url": "http://localhost/api/v1/images/<image-uuid>/file?expires=...&signature=..."
}
```

> 🖼️ `slip_image_url` เป็น **signed URL อายุ 15 นาที** — frontend ใช้ `<img src>` ตรงๆ ได้ทันที หมดอายุต้องขอ response ใหม่ (ไม่มี URL ถาวรสำหรับสลิปอีกต่อไป)

**Guards** (422 on failure):
- ❌ Booking ไม่ใช่ `draft` หรือ `verify_error` (หลัง reject booking จะเป็น `verify_error` เพื่อส่งใหม่)
- ❌ หมดเวลา (`payment_deadline` ผ่านแล้ว — ตรวจเฉพาะ `draft`, `verify_error` ส่งใหม่ได้แม้หมด deadline)
- ❌ มี confirmation `pending` อยู่แล้ว (1 pending max — กัน spam)
- ❌ ไม่ส่ง `slip_image` (บังคับเสมอ)

---

### PUT `/booking-confirmations/{id}/verify` — Admin verify slip

🔒 **Admin only** · Pending → verified + booking `pending → paid → confirmed` (+ `is_paid=true`)

**Request Body**: `{ "review_note": "optional reason" }`

**Response 200**: `{ "status": "success", "confirmation": { ..., "slip_image": { "path": "slips/...", "url": "<signed URL 15 นาที>", ... } }, "booking_status": "confirmed" }`

---

### PUT `/booking-confirmations/{id}/reject` — Admin reject slip

🔒 **Admin only** · Pending → rejected, **booking `pending → verify_error`** (รอ user ส่ง slip ใหม่ = row ใหม่)

**Request Body**: `{ "review_note": "slip ไม่ชัด" }`

**Response 200**: `{ "status": "success", "confirmation": { ..., "slip_image": {...} }, "booking_status": "verify_error" }`

---

### GET `/booking-confirmations/pending` — Admin dashboard list

🔒 **Admin only** · Paginated (15/page, FIFO oldest-first) · eager-loads `booking.user`, `reviewer`, `slipImage`

**Response 200**: `{ "status": "success", "confirmations": { paginated data } }`

---

### GET `/images/{id}/file` — Serve image via signed URL

🖼️ **(19/08/26)** — ให้บริการไฟล์รูป (สลิป) จาก private disk ผ่าน **signed URL อายุ 15 นาที**

🔓 **ไม่มี auth:sanctum โดยตั้งใจ** — `signature` ใน query string เป็นตัวยืนยันแทน · **Throttle**: `10,1`

- URL ถูกออกให้เฉพาะใน response ของผู้มีสิทธิ์ (เจ้าของ booking / admin) เท่านั้น เช่น `slip_image_url` จาก `POST /bookings/{id}/confirm` หรือ `confirmation.slip_image.url` จาก verify/reject/pending
- ⚠️ Route นี้ **exempt `RequireJsonAccept`** เฉพาะจุดเดียวในระบบ — เพื่อให้ `<img Accept: image/*>` ของ browser โหลดได้ (มิฉะนั้นโดน 406) — เหตุผลถูกบันทึกใน `cline.md`
- ไฟล์จริงอยู่ที่ `storage/app/private/slips/...` — ไม่มี symlink สาธารณะ

**Example**:
```bash
curl "http://localhost/api/v1/images/<image-uuid>/file?expires=1755600000&signature=abc123..."
```

**Response 200**: ไฟล์ภาพ stream inline (`Content-Type: image/jpeg` ฯลฯ)

**Errors**:
- `403` — ลายเซ็นไม่ถูกต้อง / ถูกแกะ / หมดอายุ (เกิน 15 นาที)
- `404` — Image row มีแต่ไฟล์ถูกลบไปแล้ว (เช่น ผ่านรอบ cleanup)

**Retention (cleanup รอบ 02:30 ทุกวัน — `app:cleanup-images`)**:
- สลิปของ confirmation `rejected` เก่ากว่า `SLIP_RETENTION_DAYS` (default 30) วัน → ลบรูป (คง confirmation row ตาม audit trail)
- สลิป `verified` เก็บไว้ทั้งหมด (หลักฐานการเงิน — ห้ามลบอัตโนมัติ)
- ไฟล์/row กำพร้า (ไม่มี row คุม / confirmation หายไปแล้ว) → เก็บกวาดทิ้ง

---

### GET `/bookings/{id}` — Get booking by ID

🔒 **Auth required** · Ownership: User can only view own bookings (admin sees all)

**Path Params:** `id` (UUID, format: 36 chars)

**Response `200`:**
```json
{
  "status": "success",
  "message": "ดึงข้อมูลการจองเรียบร้อยแล้วค่ะ! ✨",
  "booking": {
    "id": "booking-uuid",
    "user_id": "user-uuid",
    "confirmation": "202606-00001",
    "source": "online",
    "status": "paid",
    "total_amount": 2400,
    "is_paid": true,
    "payment_deadline": "...",
    "user": { ...user... },
    "booking_rooms": [
      {
        "id": "br-uuid",
        "booking_id": "booking-uuid",
        "room_type_id": "rt-uuid",
        "room_id": "room-uuid-or-null",
        "check_in": "2026-06-20",
        "check_out": "2026-06-22",
        "guests": [...],
        "billing_address": null,
        "billing_comment": null,
        "addon": {...}
      }
    ]
  }
}
```

**Response `403`:** Trying to view another user's booking
**Response `404`:** Booking not found

---

### PUT `/bookings/update/{id}` — Update booking status (Admin)

🔒 **Admin only**

Uses **state machine** — see [Booking State Machine](#booking-state-machine).

**Request Body:**
```json
{
  "status": "confirmed"
}
```

**Allowed `status` values:** *(container-level)*
`draft`, `paid`, `confirmed`, `complete`

> 🌟 **Refactor (25/06/26)**: `checked_in`/`checked_out`/`no_show` moved to **BookingRoom-level**. The booking container only tracks `draft` → `paid` → `confirmed` → `complete`.

**Response `200`:**
```json
{
  "status": "success",
  "message": "อัปเดตสถานะเป็น confirmed โดยคุณ admin เรียบร้อยแล้วค่ะ",
  "booking_id": "booking-uuid",
  "booking_status": "confirmed"
}
```

**Response `422`:** Invalid state transition
```json
{
  "status": "error",
  "message": "ไม่อนุญาตให้เปลี่ยนสถานะจาก 'draft' ไปเป็น 'checked_out' ตาม Flow ระบบค่ะนายท่าน"
}
```

---

### PUT `/bookings/{bookingId}/assign-rooms` — Auto-assign rooms (Admin)

🔒 **Admin only** (or booking owner)

Assigns actual room numbers to booking_rooms that don't have one yet. Booking must be `paid` or `confirmed`.

**Response `200`:**
```json
{
  "status": "success",
  "message": "หนูจัดการระบุเลขห้องอัตโนมัติให้จำนวน 2 ห้องเรียบร้อยแล้วค่ะนายท่าน! 🎉",
  "booking": {
    "id": "booking-uuid",
    "booking_rooms": [
      {
        "id": "br-uuid",
        "room_type_id": "rt-uuid",
        "room_id": "room-uuid",
        "addon": { ... }
      }
    ]
  }
}
```

> 🌟 **(26/08/26, 27/08/26)**: `booking_rooms` ใน response นี้คืน format เดียวกันกับ endpoint อื่นๆ ทุกตัว (`addon` relation — ซ่อน `room_type`/`room` object)

**Response `422`:**
- Booking not in `paid`/`confirmed` status
- No rooms available for the requested type

---

### GET `/bookings/{id}/status-logs` — Booking state-change audit log (Admin)

🔒 **Admin only**

Returns the audit trail of status transitions for a booking — both the **booking container** and every **booking_room** under it, ordered oldest → newest.

Each log row captures **who** changed **what** **when**:
| Field | Type | Description |
|---|---|---|
| `entity_type` | string | `booking` \| `booking_room` |
| `entity_id` | uuid | the id of the booking or booking_room that changed |
| `from_status` | string | previous status |
| `to_status` | string | new status |
| `role` | string | role that authorized the transition (`user`/`guest`/`admin`/`system`/...) |
| `causer_id` | uuid\|null | `Auth::id()` of the user who triggered it — **null for system/queue transitions** (e.g. auto `confirmed → complete` via `syncStatusFromRooms`) |
| `note` | string\|null | reserved for future context |
| `created_at` | timestamp | when the transition occurred |

> 📝 Logs are written inside `transitionStatus()` (the single chokepoint). A transition that fails validation (throws 422/403) writes **no** log. Log rows participate in the caller's DB transaction and roll back together on failure.

**Response `200`:**
```json
{
  "status": "success",
  "message": "Status change logs retrieved",
  "booking_id": "booking-uuid",
  "logs": [
    {
      "id": "log-uuid",
      "entity_type": "booking",
      "entity_id": "booking-uuid",
      "from_status": "draft",
      "to_status": "paid",
      "role": "user",
      "causer_id": "user-uuid",
      "note": null,
      "created_at": "2026-08-04T10:00:00.000000Z"
    },
    {
      "id": "log-uuid-2",
      "entity_type": "booking_room",
      "entity_id": "br-uuid",
      "from_status": "confirmed",
      "to_status": "checked_in",
      "role": "admin",
      "causer_id": "admin-uuid",
      "note": null,
      "created_at": "2026-08-04T11:30:00.000000Z"
    }
  ]
}
```

**Response `404`:** booking id not found.

---

### POST `/discounts/validate` — Validate discount code & preview quota 🎟️

🔒 **Auth required** · Rate limit: `10,1`

Preview a discount code and check live quota remaining without side effects (does NOT hold or reserve a slot).

**Request Body:**
```json
{
  "code": "SUPER50",
  "check_in": "2026-09-10",
  "check_out": "2026-09-12"
}
```
> `check_in` and `check_out` are optional. If provided, the code's `stay_from` / `stay_until` window is validated.

**Response `200`:**
```json
{
  "status": "success",
  "message": "โค้ดใช้งานได้ค่ะนายท่าน! ✨",
  "discount": {
    "id": "9d1f3c2e-4b6a-7d8e-9f0a-1b2c3d4e5f6a",
    "code": "SUPER50",
    "type": "percent",
    "value": 50,
    "room_type_ids": null,
    "usable_from": "2026-09-01T00:00:00.000000Z",
    "usable_until": "2026-09-30T23:59:59.000000Z",
    "stay_from": "2026-09-01",
    "stay_until": "2026-12-20",
    "max_uses": 10,
    "max_uses_per_user": 3,
    "is_active": true,
    "created_at": "2026-08-27T11:00:00.000000Z",
    "updated_at": "2026-08-27T11:00:00.000000Z"
  },
  "quota": {
    "global_used": 3,
    "global_max": 10,
    "global_remaining": 7,
    "per_user_used": 1,
    "per_user_max": 3,
    "per_user_remaining": 2
  }
}
```

---

### PUT `/bookings/{bookingId}/discount-code` — Apply / Change discount code 🎟️

🔒 **Auth required** (Owner or Admin) · Rate limit: `5,1`

Applies or swaps a discount code on an existing `draft` booking. Holds quota slots for eligible rooms and reprices the booking.

**Request Body:**
```json
{
  "code": "SUPER50"
}
```

> 🛡️ **Validation (2026-08-27):** missing/empty `code` → **`422`** standard Laravel validation errors (`{"message":..., "errors": {"code": [...]}}`) — ไม่ใช่ 500. Non-owner → `403`, non-draft booking → `422`.

**Error responses:** `401` (ยังไม่ล็อกอิน) · `403` (ไม่ใช่เจ้าของ/admin) · `404` (ไม่พบ booking) · `422` (validation / โค้ดหมดอายุ / ปิดใช้งาน / โควตาไม่พอ / ไม่มีห้อง eligible / สถานะไม่ใช่ draft)

> ⚠️ **Frontend contract (2026-08-27):** ถ้า admin **ปิดใช้งานหรือหมดอายุ** (`usable_until`) โค้ดที่ booking ถืออยู่ภายหลัง — hold + ส่วนลดเดิม**ยังค้างอยู่ใน draft** (reprice ไม่ re-validate) แต่การ `PUT .../rooms/*` จะโดน `422` ("โค้ดหมดเขต/ปิดใช้งาน") เพราะ re-apply ใหม่ไม่ผ่าน → ทางออกของ user คือ `DELETE /bookings/{bookingId}/discount-code` ก่อนแก้ไขห้อง

**Response `200`:**
```json
{
  "status": "success",
  "message": "ใส่โค้ดส่วนลดเรียบร้อยแล้วค่ะ! 🎟️",
  "booking": {
    "id": "9d1f3c2e-...",
    "confirmation": "202608-00001",
    "status": "draft",
    "discount_code": "SUPER50",
    "total_amount": 260000,
    "booking_rooms": [
      {
        "id": "...",
        "room_type_id": "...",
        "check_in": "2026-09-10",
        "check_out": "2026-09-12",
        "room_amount": 400000,
        "discount_amount": 200000,
        "amount": 260000,
        "addon": {
          "breakfast": 2,
          "breakfast_price": 60000,
          "extra_bed": 0,
          "extra_bed_price": 0,
          "early_checkIn_price": 0,
          "late_checkOut_price": 0
        }
      }
    ]
  }
}
```

---

### DELETE `/bookings/{bookingId}/discount-code` — Remove discount code 🎟️

🔒 **Auth required** (Owner or Admin) · Rate limit: `5,1`

Removes the discount code from a `draft` booking, releases held redemption slots, and reprices the booking to full rate.

**Response `200`:**
```json
{
  "status": "success",
  "message": "ลบโค้ดส่วนลดออกจากการจองเรียบร้อยแล้วค่ะ 🎟️",
  "booking": {
    "id": "...",
    "discount_code": null,
    "total_amount": 460000,
    "booking_rooms": [
      {
        "id": "...",
        "room_amount": 400000,
        "discount_amount": 0,
        "amount": 400000
      }
    ]
  }
}
```

> 🧾 **Per-room `amount` (2026-09-03):** ทุก `booking_room` มี field `amount` (integer satang) = **ยอดสุทธิต่อห้อง**:
> `amount = room_amount − discount_amount + extra_bed_price + breakfast_price + early_checkIn_price + late_checkOut_price`
> Server คำนวณที่จุดเดียว (`DiscountService::reprice()`) ครบทุก flow รวมถึง walk-in — **invariant: Σ `booking_rooms.amount` == `bookings.total_amount`** (ทั้งสองฝั่ง net) frontend จึงไม่ต้องบวกเอง และห้ามส่ง `amount` เข้ามาเอง (read-only, server-computed)

---

### Discount Management Endpoints (Admin only) 🎟️

🔒 **Admin only**

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/discounts` | List all discounts (optional `?is_active=true/false`, `?code=CODE`, `?search=TERM`) |
| `GET` | `/discounts/{code}` | Get discount by code name or UUID (`200 OK` / `404 Not Found`) |
| `POST` | `/discounts` | Create a new discount (`201 Created`) |
| `PUT` | `/discounts/{id}` | Update discount attributes |
| `PATCH` | `/discounts/{id}/toggle` | Toggle `is_active` status |

> ❄️ **Note:** Discounts cannot be deleted (`DELETE` endpoint does not exist) to protect financial audit history. Soft toggle is used instead.

> 🔍 **Lookup & Search:**
> - `GET /discounts?code=SUMMER50` — กรองรายการตามชื่อโค้ดแบบ exact match (case-insensitive)
> - `GET /discounts?search=SUMMER` — ค้นหาโค้ดที่มีคำว่า SUMMER (partial match)
> - `GET /discounts/SUMMER50` หรือ `GET /discounts/{uuid}` — ดึงข้อมูลโค้ดรายตัวโดยตรง (คืน object `discount`)

> 🛡️ **Update rules (2026-08-27):**
> - `code` **rename ได้เฉพาะโค้ดที่ยังไม่มี redemption ผูกอยู่** (ไม่มี hold/used) — `bookings.discount_code` เป็น string snapshot การ rename ขณะมี hold จะทำให้ draft ใช้งานไม่ได้ → ตอบ `422`. ถ้าต้องการหยุดใช้โค้ด ใช้ `PATCH /discounts/{id}/toggle` แทน
> - `stay_from` / `stay_until` ต้องส่ง**มาเป็นคู่เสมอ** (`required_with` กันไว้ทั้ง store/update) — half-set window ถูกปฏิเสธ `422`
> - ความซ้ำของ `code` เช็คแบบ **case-insensitive** (`welcome10` ≡ `WELCOME10`) — ส่งซ้ำต่าง case → `422` validation error

---

## Front Desk Operations

> All endpoints in this section are **Admin only**.

### POST `/front-desk/walk-in` — Walk-in booking

🔒 **Admin only**

Creates a booking + immediately checks in. Used when a guest arrives at the hotel without a prior booking.

**Request Body:**
```json
{
  "verified_by": "admin-uuid",
  "nights": 2,
  "room_id": "room-uuid",
  "guests": [
    {
      "title": "Mr.",
      "name": "Walk-in Guest",
      "nationality": "Thai"
    }
  ],
  "billing_address": null,
  "billing_comment": null
}
```

**Validation Rules:**

| Field                    | Rule                                |
|--------------------------|-------------------------------------|
| `verified_by`            | required, uuid, exists in users     |
| `nights`                 | required, integer, min 1            |
| `room_id`                | required, uuid, exists in rooms     |
| `guests`                 | nullable, array                     |
| `guests.*.title`         | nullable, string, max 50            |
| `guests.*.name`          | nullable, string, max 255           |
| `guests.*.nationality`   | nullable, string, max 100           |
| `billing_address`        | nullable, string, max 255           |
| `billing_comment`        | nullable, string, max 255           |

> Room must be in `available` or `prep_checkin` status.

**Response `201`:**
```json
{
  "status": "success",
  "message": "Walk-in booking and Check-in completed!",
  "booking_id": "booking-uuid",
  "room_number": "101"
}
```

**Response `400`:**
```json
{
  "status": "error",
  "message": "Room number 101 is not ready for walk-in. Current status: maintenance"
}
```

---

### POST `/front-desk/{bookingId}/check-in` — Check-in guest

🔒 **Admin only**

Assigns rooms (if not yet assigned) and transitions booking to `checked_in`.

**Request Body:**
```json
{
  "assigned_rooms": ["room-uuid-1", "room-uuid-2"]
}
```

> - `assigned_rooms` is optional but if provided, **count must match** the number of booking_rooms
> - Each room's `room_type_id` must match the booked room type
> - Can send single UUID as string; server will normalize to array

**Response `200`:**
```json
{
  "status": "success",
  "message": "Check-in completed successfully! 🎉",
  "booking_id": "booking-uuid",
  "booking_status": "checked_in",
  "room_updates": [
    {
      "room_number": "101",
      "new_status": "occupied"
    }
  ]
}
```

---

### POST `/front-desk/{bookingId}/check-out` — Check-out guest

🔒 **Admin only**

Requires all payments to be completed. Auto-creates housekeeping tasks.

**Request Body:**
```json
{
  "verified_by": "admin-uuid",
  "notes": "Guest requested late checkout cleanup"
}
```

**Response `200`:**
```json
{
  "status": "success",
  "message": "Check-out completed successfully. สร้างงานให้ทีมแม่บ้านเรียบร้อยค่ะ!",
  "booking_id": "booking-uuid",
  "booking_status": "checked_out",
  "room_updates": [
    {
      "room_number": "101",
      "room_status": "checkout_makeup",
      "housekeeping_task_id": "task-uuid"
    }
  ]
}
```

**Response `400` (unpaid balance):**
```json
{
  "status": "error",
  "message": "ยังมีรายการค้างชำระอยู่ 1200 บาทค่ะนายท่าน กรุณารับชำระเงินก่อนนะคะ"
}
```

---

### POST `/front-desk/{bookingId}/mark-no-show` — Mark guest as no-show

🔒 **Admin only**

Marks one or more rooms as `no_show` (BookingRoom-level). Supports **partial no-show** — omit `booking_room_ids` to mark *all* rooms, or specify individual rooms to mark only those. The booking container auto-syncs to `complete` when every room has reached a terminal state (`checked_out` or `no_show`).

**Request Body:**
```json
{
  "verified_by": "admin-uuid",
  "booking_room_ids": ["br-uuid-1"]
}
```

**Validation Rules:**

| Field                  | Rule                                |
|------------------------|-------------------------------------|
| `verified_by`          | required, uuid, exists in users     |
| `booking_room_ids`     | nullable, array                     |
| `booking_room_ids.*`   | required, uuid, exists in booking_rooms |

> 🛡️ **Guards:**
> - Booking must **not** already be `complete`
> - Every targeted room must be in `confirmed` status (draft/checked_in/checked_out/no_show rooms are rejected)
> - If the booking container is still `paid`, it is transitioned to `confirmed` first so a paid-but-absent booking can still be closed

**Response `200` (partial — some rooms still active):**
```json
{
  "status": "success",
  "message": "Mark No-Show สำเร็จแล้วค่ะนายท่าน",
  "booking_id": "booking-uuid",
  "booking_status": "confirmed"
}
```

**Response `200` (all rooms terminal — container auto-completes):**
```json
{
  "status": "success",
  "message": "Mark No-Show สำเร็จแล้วค่ะนายท่าน",
  "booking_id": "booking-uuid",
  "booking_status": "complete"
}
```

**Response `422`:**
```json
{
  "status": "error",
  "message": "ไม่สามารถ mark No-Show ได้ค่ะนายท่าน เนื่องจากห้องมีสถานะ 'checked_in' (ต้องเป็น 'confirmed' เท่านั้น) กรุณาตรวจสอบอีกครั้งนะคะ"
}
```

---

### POST `/front-desk/{bookingId}/payment` — Record payment

🔒 **Admin only**

Records a completed payment. Auto-marks booking `is_paid=true` and transitions `draft → paid` if applicable. Creates a receipt when fully paid.

**Request Body:**
```json
{
  "amount": 2400,
  "reference_number": "CASH-2026-001",
  "received_by": "admin-uuid"
}
```

**Validation Rules (StorePaymentRequest):**

| Field              | Rule                                                |
|--------------------|-----------------------------------------------------|
| `booking_id`       | required, uuid, exists in bookings                  |
| `amount`           | required, integer, min 0                            |
| `reference_number` | nullable, string                                    |
| `received_by`      | nullable, uuid, exists in users                     |

**Response `201`:**
```json
{
  "status": "success",
  "message": "Payment recorded successfully!",
  "payment": {
    "id": "payment-uuid",
    "booking_id": "booking-uuid",
    "amount": 2400,
    "status": "completed",
    "reference_number": "CASH-2026-001",
    "received_by": "admin-uuid"
  },
  "booking_is_paid": true,
  "booking_status": "paid"
}
```

---

## Payments & Webhooks

> ⚠️ **DEMO / Not Production-Ready:** Payment & Webhook endpoints below are functional demos. Schema/enum values (`status`) may change when the real payment gateway is integrated.
> 🌟 **Refactor (19/08/26)**: `payment_method` ถูกลบออกจากทุก flow แล้ว — การชำระเงินเหลือ "ส่งสลิป → รอแอดมินตรวจ" ผ่าน `POST /bookings/{id}/confirm`

### POST `/payments` — Request payment (Admin)

🔒 **Admin only**

Creates a `pending` payment and returns a mock payment URL.

**Request Body:**
```json
{
  "booking_id": "booking-uuid"
}
```

**Response `200`:**
```json
{
  "status": "success",
  "message": "Payment request created",
  "payment_id": "payment-uuid",
  "amount": 2400,
  "payment_url": "https://gateway.mockbank.com/pay/payment-uuid"
}
```

**Response `400`:**
```json
{
  "status": "error",
  "message": "This booking is already paid."
}
```

---

### POST `/payment/webhook` — Payment gateway callback

> ❄️ **FROZEN (24/07/26)** — Deprecated. Returns `410 GONE`.
> ใช้ `POST /bookings/{id}/confirm` (user submit slip) + `PUT /booking-confirmations/{id}/verify` (admin verify) แทน
> ผ่าน `booking_confirmations` table (1:N history + state machine pending→verified|rejected)

🔒 **Public** (called by payment gateway, signature verification TBD)

**Request Body:**
```json
{
  "payment_id": "payment-uuid",
  "status": "success",
  "reference_number": "BANK-REF-001"
}
```

**Response `200` (success):**
```json
{
  "status": "success",
  "message": "Payment processed successfully. Booking is now paid — waiting for admin to confirm."
}
```

**Response `422` (expired):**
```json
{
  "status": "error",
  "message": "หมดเวลาชำระเงินแล้วค่ะ ไม่สามารถดำเนินการได้"
}
```

> On `status: "success"`, the system:
> 1. Updates payment → `completed`
> 2. Sets booking `is_paid = true`
> 3. Transitions booking `draft → paid` (system role)
> 4. ~~Generates a receipt~~ ❄️ FROZEN — receipts table deprecated (24/07/26)

---

## Global Rates

> 🌟 **(22/07/26 renamed from "Addon Rates" / `/addon-rates`)** — ตารางเดียว (`global_rates`) เก็บทั้ง **room rate** (`rate_type` = `daily` / `daily_ku` / `group` / `month` — ผูก `room_type_id`) และ **addon rate** (`rate_type` = `addon` — ใช้ `code` เป็น key). ทุก row มี `rate_type` + `room_type_id` (null สำหรับ addon) เสมอ.

### GET `/global-rates` — List all rates (room + addon)

🔒 **Public**

**Query params (optional):** `?rate_type=daily|daily_ku|group|month|addon` · `?room_type_id={uuid}` — filter ได้ทั้งคู่

> 🌟 **(26/08/26, 27/08/26)** Seeded addon defaults (satang integers): `breakfast` 20000 (200 THB), `early_checkin` 10000 (100 THB **ต่อชั่วโมง**), `late_checkout` 10000 (100 THB **ต่อชั่วโมง**), `extra_bed` 50000 (500 THB) — early/late คิดราคาตามสูตรรายชั่วโมง (int 0–7). Room rates ต่อ room type seed ผ่าน `RoomSeeder` (ดู `rates` object ใน [Rooms & Room Types](#rooms--room-types))

**Response `200`:**
```json
{
  "status": "success",
  "message": "ดึงรายการ Global Rates เรียบร้อยแล้วค่ะ! ✨",
  "rates": [
    {
      "id": "rate-uuid",
      "rate_type": "addon",
      "room_type_id": null,
      "code": "breakfast",
      "name_en": "Breakfast",
      "name_th": "อาหารเช้า",
      "default_price": 20000,
      "is_active": true
    },
    {
      "id": "rate-uuid",
      "rate_type": "daily",
      "room_type_id": "room-type-uuid",
      "code": null,
      "name_en": "Superior Daily General",
      "name_th": "ห้องซูพีเรียร์ (ราคารายวัน)",
      "default_price": 100000,
      "is_active": true
    },
    {
      "id": "rate-uuid",
      "rate_type": "group",
      "room_type_id": "room-type-uuid",
      "code": "min_5_rooms",
      "name_en": "Superior Group (5+ Rooms)",
      "name_th": "ห้องซูพีเรียร์ (ราคาหมู่คณะ 5 ห้องขึ้นไป)",
      "default_price": 75000,
      "is_active": true
    }
  ]
}
```

---

### GET `/global-rates/{id}` — Get rate by ID

🔒 **Public**

**Response `200`:**
```json
{
  "status": "success",
  "message": "ดึงข้อมูล Global Rate เรียบร้อยแล้วค่ะ! ✨",
  "rate": { ...rate object... }
}
```

---

### PUT `/global-rates/{id}` — Update rate (Admin)

🔒 **Admin only**

**Request Body (all optional):**
```json
{
  "rate_type": "addon",
  "room_type_id": null,
  "code": "breakfast",
  "name_en": "Breakfast Buffet",
  "name_th": "บุฟเฟ่ต์อาหารเช้า",
  "default_price": 18000,
  "is_active": true
}
```

> ⚠️ **Consistency rule (บังคับที่ server):** `rate_type` กับ `code`/`room_type_id` ต้องสอดคล้องกัน —
> `addon` → ต้องมี `code`, server force `room_type_id = null` · `group` → ต้องมี `room_type_id` + `code` (`min_5_rooms` / `min_10_rooms`) · `daily` / `daily_ku` / `month` → ต้องมี `room_type_id`, server force `code = null`

**Response `200`:**
```json
{
  "status": "success",
  "message": "อัปเดต Global Rate เรียบร้อยแล้วค่ะ! ✨",
  "rate": { ...updated rate... }
}
```

---

### PATCH `/global-rates/{id}/toggle` — Toggle rate active state (Admin)

🔒 **Admin only**

**Response `200`:**
```json
{
  "status": "success",
  "message": "ปิดใช้งาน Global Rate เรียบร้อยแล้วค่ะ! ✨",
  "rate": { ...rate with is_active toggled... }
}
```

---

## Dashboard / Housekeeping

> All endpoints in this section are **Admin only**.

### GET `/dashboard/cleaning-tasks` — List housekeeping tasks

🔒 **Admin only**

Returns tasks with status `pending` or `in_progress`.

**Response `200`:**
```json
{
  "status": "success",
  "message": "Housekeeping tasks fetched successfully",
  "pending_tasks": 3,
  "tasks": [
    {
      "task_id": "task-uuid",
      "room_number": "101",
      "room_type": "Standard",
      "task_status": "pending",
      "notes": "Auto-generated from Check-out",
      "requested_at": "2026-06-19 10:30"
    }
  ]
}
```

---

### PUT `/dashboard/cleaning-tasks/{roomId}` — Update cleaning status

🔒 **Admin only**

**Path Params:** `roomId` (UUID)

**Request Body:**
```json
{
  "status": "done",
  "verified_by": "admin-uuid"
}
```

**Allowed `status` values:** `in_progress`, `done`

> When `status: "done"`, the room is automatically transitioned to `available`.

**Response `200`:**
```json
{
  "status": "success",
  "message": "Housekeeping status updated successfully!",
  "task_id": "task-uuid",
  "new_task_status": "done",
  "room_id": "room-uuid",
  "new_room_status": "available"
}
```

---

## DB Models Reference

### User

| Field         | Type      | Description                            |
|---------------|-----------|----------------------------------------|
| `id`          | UUID      | Primary key                            |
| `name`        | string    | Full name                              |
| `email`       | string    | Unique email                           |
| `password`    | string    | Hashed (bcrypt)                        |
| `role`        | string    | `user`, `guest`, `ku_member`, `staff`, `admin`, `housekeeping`, `system` (default: `user`) |
| `ver`         | boolean   | Verified status (default: false)       |
| `created_at`  | timestamp |                                        |
| `updated_at`  | timestamp |                                        |

---

### Booking

| Field              | Type      | Description                                              |
|--------------------|-----------|---------------------------------------------------------|
| `id`               | UUID      | Primary key                                             |
| `user_id`          | UUID      | FK → users (booking owner — staff for walk-in)          |
| `confirmation`     | string    | Format: `YYYYMM-XXXXX` (auto-generated, unique)         |
| `source`           | enum      | `online` / `admin` / `line`                             |
| `status`           | enum      | Container-level: `draft`, `paid`, `confirmed`, `complete` |
| `total_amount`     | integer   | In baht (no decimals — integer since 2026-06-05)        |
| `is_paid`          | boolean   | Default: false                                          |
| `payment_deadline` | datetime  | For draft bookings (24h from creation)                  |
| `created_at`       | timestamp |                                                         |
| `updated_at`       | timestamp |                                                         |

**Relationships:**
- `belongsTo User` — owner
- `hasMany BookingRoom` — the rooms in this booking
- `hasMany BookingConfirmation` — payment proof history (1:N, replaces payments/receipts)

> 🌟 **Refactor (18/06/26)**: Guest name/title/nationality moved from `bookings` to `booking_rooms.guests` JSON. `bookings` no longer stores guest info.

---

### BookingRoom

| Field          | Type      | Description                                              |
|----------------|-----------|---------------------------------------------------------|
| `id`           | UUID      | Primary key                                             |
| `booking_id`   | UUID      | FK → bookings                                           |
| `room_type_id` | UUID      | FK → room_types (what was booked)                       |
| `room_id`      | UUID      | FK → rooms (nullable — assigned at check-in)            |
| `check_in`     | date      | 🌟 Per-room check-in date (Refactor 02/07/26)            |
| `check_out`    | date      | 🌟 Per-room check-out date (Refactor 02/07/26)           |
| `status`       | enum      | BR-level: `draft`, `confirmed`, `checked_in`, `checked_out`, `no_show` |
| `guests`       | JSON      | Array of `{title, name, firstName, lastName, email, phone, nationality}` |
| `billing_address` | string | Billing address (nullable)                          |
| `billing_comment` | string | Billing note/comment (nullable)                     |
| `room_amount`  | integer   | Gross room price = rate × nights (satang, server-computed 27/08/26) |
| `discount_amount` | integer | Discount applied to this room (satang, server-computed) |
| `amount`       | integer   | 🧾 Net total per room (satang, server-computed 03/09/26): `room_amount − discount_amount + addon รวมทุกอย่าง` — invariant `Σ booking_rooms.amount == bookings.total_amount` |
| `created_at`   | timestamp |                                                         |
| `updated_at`   | timestamp |                                                         |

**Guests JSON Structure:**
```json
[
  {
    "title": "Mr.",
    "firstName": "Somchai",
    "lastName": "Jaidee",
    "nationality": "Thai"
  },
  {
    "title": "Ms.",
    "firstName": "Suda",
    "lastName": "Jaidee",
    "nationality": "Thai"
  }
]
```

**Relationships:**
- `belongsTo Booking`
- `belongsTo RoomType`
- `belongsTo Room` (nullable)
- `hasOne Addon`

---

### Room

| Field               | Type      | Description                                              |
|---------------------|-----------|---------------------------------------------------------|
| `id`                | UUID      | Primary key                                             |
| `room_number`       | string    | Human-readable room number (e.g. "101")                 |
| `room_type_id`      | UUID      | FK → room_types                                         |
| `status`            | enum      | See [Room State Machine](#room-state-machine)           |
| `status_updated_at` | timestamp | When status last changed                                |
| `status_updated_by` | UUID      | Who changed status (user_id)                            |
| `builtin_extra_beds`| integer   | Bed capacity of the room                                |
| `created_at`        | timestamp |                                                         |
| `updated_at`        | timestamp |                                                         |

**Relationships:**
- `belongsTo RoomType`
- `hasMany BookingRoom`

---

### RoomType

| Field                | Type      | Description                              |
|----------------------|-----------|------------------------------------------|
| `id`                 | UUID      | Primary key                              |
| `name_en`            | string    | English name                             |
| `name_th`            | string    | Thai name                                |
| `description`        | string    | Description (nullable)                   |
| `max_guests`         | integer   | Max guests per room                      |
| `extra_bed_enabled`  | boolean   | Whether extra beds are allowed (default: false) |
| `max_extra_beds`     | integer   | Max extra beds allowed (default: 0)      |
| `extra_bed_price`    | string    | Price per extra bed as 2-dp baht string (e.g. `"500.00"` on wire; storage integer satang `50000`) |
| `rates`              | object    | **Virtual** — canonical rates object `{daily: {general, ku_member}, group: {min_5_rooms, min_10_rooms}, monthly}` in 2-dp decimal baht strings resolved from `global_rates`. Fallback `"0.00"` if missing/inactive. |
| `created_at`         | timestamp |                                          |
| `updated_at`         | timestamp |                                          |

> 💡 **Room Rates & Money Policy (03/09/26):**
> - **Money Policy:** Database storage strictly uses **integer satang** (e.g. `100000` satang = 1,000.00 THB). Booking math, payment totals, and discounts remain in integer satang. At the room-type API edge (wire), all rate values and `extra_bed_price` are serialized as **2-decimal-places decimal baht strings** (e.g. `"1000.00"`, `"500.00"`).
> - **Rate Types in `global_rates`:**
>   - `daily` (code `null`): Daily rate for general guests (`rates.daily.general`)
>   - `daily_ku` (code `null`): Daily rate for KU members / university personnel (`rates.daily.ku_member`)
>   - `group` (code `min_5_rooms`): Group rate for 5+ rooms (`rates.group.min_5_rooms`)
>   - `group` (code `min_10_rooms`): Group rate for 10+ rooms (`rates.group.min_10_rooms`)
>   - `month` (code `null`): Long-stay monthly rate (`rates.monthly`)
> - The legacy virtual field `daily_rate` is deprecated and dropped from JSON serialization in favor of `rates`.

**Relationships:**
- `hasMany Room`
- `hasMany BookingRoom`
- `hasMany GlobalRate` (via `rateRows()`)
- `hasOne GlobalRate` (daily rate, via `dailyRateRow()`)

---

### Addon

| Field                  | Type    | Description                              |
|------------------------|---------|------------------------------------------|
| `id`                   | UUID    | Primary key                              |
| `booking_room_id`      | UUID    | FK → booking_rooms (one-to-one)          |
| `extra_bed`            | integer | Number of extra beds requested           |
| `extra_bed_price`      | integer | Total price for extra beds (baht)        |
| `breakfast`            | integer | Number of breakfasts                     |
| `breakfast_price`      | integer | Total breakfast price (baht)             |
| `early_checkIn_price`  | integer | Early check-in total price (satang)      |
| `early_hours`          | integer | Early check-in hours (0–7, default: 0)   |
| `late_checkOut_price`  | integer | Late check-out total price (satang)      |
| `late_hours`           | integer | Late check-out hours (0–7, default: 0)   |
| `created_at`           | timestamp |                                        |
| `updated_at`           | timestamp |                                        |

**Relationships:**
- `belongsTo BookingRoom`

---

### AddonRate

| Field            | Type    | Description                                       |
|------------------|---------|---------------------------------------------------|
| `id`             | UUID    | Primary key                                       |
| `code`           | string  | Unique code: `breakfast`, `early_checkin`, etc.   |
| `name_en`        | string  | English name                                      |
| `name_th`        | string  | Thai name (nullable)                              |
| `default_price`  | integer | Unit price (baht) — used in server-side pricing   |
| `is_active`      | boolean | Whether this addon can be selected                |
| `created_at`     | timestamp |                                                 |
| `updated_at`     | timestamp |                                                 |

**Seeded codes:** `breakfast`, `early_checkin`, `late_checkout`, `extra_bed`

---

### Payment

> ⚠️ **DEMO** — Payment schema is provisional. Fields may change when real gateway integration lands.
> 🌟 **(19/08/26)** — `payment_method` dropped: flow เหลือสลิปอย่างเดียว

| Field              | Type      | Description                                              |
|--------------------|-----------|---------------------------------------------------------|
| `id`               | UUID      | Primary key                                             |
| `booking_id`       | UUID      | FK → bookings                                           |
| `amount`           | integer   | In baht (integer since 2026-06-05)                      |
| `status`           | enum      | `pending` / `completed` / `failed`                      |
| `reference_number` | string    | Bank/gateway reference (nullable)                       |
| `received_by`      | UUID      | FK → users (admin who received cash, nullable)          |
| `created_at`       | timestamp |                                                         |
| `updated_at`       | timestamp |                                                         |

**Relationships:**
- `belongsTo Booking`

---

### Receipt

> ❄️ **FROZEN (24/07/26)** — Deprecated read-only table. ไม่สร้าง receipt row ใหม่อีกต่อไป
> ใช้ `booking_confirmations` table แทน (POST /bookings/{id}/confirm + admin verify)
> ⚠️ **DEMO** — Receipt generation is tied to the demo payment flow. Fields may change with production gateway integration.

| Field          | Type      | Description                                       |
|----------------|-----------|---------------------------------------------------|
| `receipt_no`   | string    | Format: `REC-YYYYMM-XXXXX` (auto-generated)       |
| `booking_id`   | UUID      | FK → bookings                                     |
| `payment_id`   | UUID      | FK → payments                                     |
| `amount`       | integer   | In baht                                           |
| `billing_name` | string    | From `primary_guest_name` or user.name            |
| `created_at`   | timestamp |                                                   |
| `updated_at`   | timestamp |                                                   |

**Relationships:**
- `belongsTo Booking`
- `belongsTo Payment`

---

### BookingConfirmation

🌟 **(24/07/26)** — Replaces payments/receipts. 1:N with bookings (history of every payment proof submitted, even rejected ones).
🌟 **(19/08/26)** — `payment_method` dropped: flow เหลือ "ส่งสลิป → รอแอดมินตรวจ" (`slip_image` บังคับ, `transfer_time` optional)
🌟 **(19/08/26 #2)** — `slip_image` column **ถูกลบแล้ว** — รูปสลิปอยู่ใน `images` table ผ่าน morph (`slipImage()`)

| Field              | Type      | Description                                              |
|--------------------|-----------|---------------------------------------------------------|
| `id`               | UUID      | Primary key                                              |
| `booking_id`       | UUID      | FK → bookings (1:N, not unique)                          |
| `transfer_time`    | timestamp | Time customer reported transfer (from slip)              |
| `status`           | string    | `pending`, `verified`, `rejected` (default: pending)     |
| `reviewed_by`      | UUID      | FK → users (admin who reviewed)                          |
| `reviewed_at`      | timestamp | When admin reviewed                                     |
| `review_note`      | string    | Reason (optional, mostly for reject)                     |
| `created_at`       | timestamp |                                                          |
| `updated_at`       | timestamp |                                                          |

**Relationships:**
- `belongsTo Booking`
- `belongsTo User` (reviewer)
- `morphOne Image` (`slipImage`) — รูปสลิปบน private disk + signed URL (ดู `images` table ด้านล่าง)

---

### Image

🖼️ **(19/08/26)** — ระบบรูปจริงจัง (แทน draft เดิม) · polymorphic: ใช้กับสลิปวันนี้, housekeeping ฯลฯ ต่อได้

| Field           | Type      | Description                                            |
|-----------------|-----------|--------------------------------------------------------|
| `id`            | UUID      | Primary key                                            |
| `path`          | string    | ตำแหน่งไฟล์บน disk เช่น `slips/abc123.jpg`             |
| `disk`          | string    | filesystem disk (default `local` = `storage/app/private`) |
| `mime_type`     | string    | เช่น `image/jpeg` (nullable)                           |
| `size`          | bigint    | ขนาดไฟล์ bytes (nullable)                              |
| `original_name` | string    | ชื่อไฟล์ที่ผู้ใช้อัปโหลด (nullable)                    |
| `uploaded_by`   | UUID      | FK → users (nullOnDelete)                              |
| `imageable_id`  | UUID      | morph target id (เช่น booking_confirmation id)         |
| `imageable_type`| string    | morph target class (เช่น `App\Models\BookingConfirmation`) |

**Relationships:** `morphTo imageable` · `belongsTo User` (uploader)

**พฤติกรรมพิเศษ:** serialize แล้วมี appended `url` (signed URL อายุ 15 นาที) · ลบ row = ลบไฟล์บน disk อัตโนมัติ (hook `deleting`)

---

### HousekeepingTask

| Field           | Type      | Description                                       |
|-----------------|-----------|---------------------------------------------------|
| `id`            | UUID      | Primary key                                       |
| `room_id`       | UUID      | FK → rooms                                        |
| `status`        | enum      | `pending` / `in_progress` / `done`                |
| `notes`         | text      | Cleaning notes (nullable)                         |
| `checked_out_at`| datetime  | When guest checked out (trigger)                  |
| `completed_at`  | datetime  | When task finished (nullable)                     |
| `created_at`    | timestamp |                                                   |
| `updated_at`    | timestamp |                                                   |

**Relationships:**
- `belongsTo Room`

---

## State Machines

### Booking State Machine (Container)

> 🌟 **Refactor (03/07/26):** Booking = container เก็บสถานะ payment/admin flow เท่านั้น
> `checked_in`/`checked_out`/`no_show` ย้ายไปอยู่ที่ **BookingRoom** (BR-level) แล้ว
> 🌟 **Refactor (25/08/26):** เพิ่ม `pending` ก่อน `paid` — mirror กับ BookingConfirmation (user ส่งสลิป = รอ admin ตรวจ, `paid` = ตรวจแล้วเท่านั้น)
> 🌟 **Refactor (25/08/26):** เพิ่ม `verify_error` เมื่อ admin reject สลิป (แทนการกลับ `draft`) — user ส่งสลิปใหม่จะกลับ `pending`

```
   ┌─────────┐ user/guest/admin ┌─────────┐  admin   ┌─────────┐   admin    ┌────────────┐  admin/system  ┌─────────────┐
   │  draft  │ ───────────────► │ pending │ ───────► │   paid  │ ─────────► │ confirmed  │ ─────────────► │  complete   │
   └─────────┘                  └─────────┘          └─────────┘            └────────────┘                └─────────────┘
       │                            │
       │ admin/system               │ admin (reject)
       │ (เงินสดหน้าเคาน์เตอร์)         ▼
       │                      ┌──────────────┐ user/guest/admin (ส่งสลิปใหม่)
       │                      │ verify_error │ ───────────────────────────────┘
       │                      └──────────────┘
       │ admin (walk-in skip จนถึง confirmed)
       └──────────────────────────────────────────────────► confirmed
```

**Valid Transitions (Container):**

| From          | To             | Allowed Roles              |
|---------------|----------------|----------------------------|
| `draft`       | `pending`      | user, guest, admin (ส่งสลิป รอตรวจ) |
| `draft`       | `paid`         | admin, system (เงินสดหน้าเคาน์เตอร์ / webhook อนาคต) |
| `draft`       | `confirmed`    | admin (walk-in only)       |
| `pending`     | `paid`         | admin (verify สลิปผ่าน)     |
| `pending`     | `verify_error` | admin (reject สลิป — ให้ user ส่งใหม่) |
| `verify_error`| `pending`      | user, guest, admin (ส่งสลิปใหม่ รอตรวจ) |
| `paid`        | `confirmed`    | admin                      |
| `confirmed`   | `complete`     | admin, system (auto-sync)  |

> ❌ **ไม่มี `cancelled`** — draft ที่หมดอายุจะถูก hard delete (CleanupExpiredDrafts)
> 🧹 `pending` และ `verify_error` ที่หมด deadline **ไม่ถูกลบ** (ห้องยังถูก hold ไว้ตาม availability และรอ user ส่งสลิปใหม่)
> 🗑️ **(17/08/26)** เจ้าของ/admin ลบ draft เองได้ผ่าน `DELETE /bookings/{bookingId}` (hard delete cascade + audit log `draft → deleted` ใน status_change_logs)
> ❌ ไม่มี `deleted` เป็น state — เป็นการลบจริง (cascade BR + Addon + Payment)

---

### BookingConfirmation State Machine (24/07/26)

🌟 **Replaces payments/receipts flow.** 1:N with bookings — เก็บ history ทุกครั้งที่ user ส่งหลักฐานการชำระ (แม้ reject)

```
   ┌─────────┐  admin   ┌──────────┐
   │ pending │ ───────► │ verified │ (terminal — booking pending → paid → confirmed)
   └─────────┘          └──────────┘
       │
       │ admin
       ▼
   ┌──────────┐
   │ rejected │ (terminal — booking เปลี่ยนเป็น verify_error, user ส่งสลิปใหม่เพื่อลองอีก)
   └──────────┘
```

**Valid Transitions:**

| From       | To         | Allowed Roles | Side effect on booking          |
|------------|------------|---------------|---------------------------------|
| `pending`  | `verified` | admin         | booking `pending → paid → confirmed` + `is_paid=true` |
| `pending`  | `rejected` | admin         | booking `pending → verify_error` (ส่งใหม่ได้) |

> ✅ `verified` + `rejected` = terminal (ไม่ย้อนกลับ — จะแก้ทำ row ใหม่แทน เพื่อรักษา audit trail)
> ✅ **1 pending max guard** — ถ้ามี pending อยู่แล้ว → POST confirm จะ 422 (กัน spam)
> ✅ User re-submit หลัง reject → สร้าง row ใหม่ status=pending (row rejected เก่ายังอยู่ใน history)

---

### BookingRoom State Machine (BR-level)

> 🌟 **New (25/06/26):** แต่ละห้องมี state machine ของตัวเอง (รองรับหลายห้อง/หลายวันต่อ booking)

```
   ┌─────────┐  admin/system  ┌────────────┐   admin    ┌─────────────┐   admin   ┌──────────────┐
   │  draft  │ ─────────────► │ confirmed  │ ─────────► │ checked_in  │ ────────► │ checked_out  │
   └─────────┘                 └────────────┘            └─────────────┘           └──────────────┘
                                    │
                                    │ admin
                                    └────────────────► no_show
```

**Valid Transitions (BR-level):**

| From          | To            | Allowed Roles |
|---------------|---------------|---------------|
| `draft`       | `confirmed`   | admin, system |
| `confirmed`   | `checked_in`  | admin         |
| `confirmed`   | `no_show`     | admin         |
| `checked_in`  | `checked_out` | admin         |

> **Container auto-sync:** เมื่อ BR ทุกห้องเป็น `checked_out`/`no_show` → booking container → `complete`
>
> 🌟 **(17/08/26) Draft-editable window:** ถ้า parent booking เป็น `draft` และ BR เป็น `draft` —
> แก้ไขห้องได้ทุก field ผ่าน `PUT /bookings/{bookingId}/rooms/{bookingRoomId}` (เช็ค availability + คิดราคาใหม่ที่ server)
> และลบห้องออกได้ผ่าน `DELETE /bookings/{bookingId}/rooms/{bookingRoomId}` (ห้องสุดท้ายลบไม่ได้ — ให้ลบทั้ง booking)

---

### Room State Machine

**Allowed statuses:** `available`, `occupied`, `checkout_makeup`, `prep_checkin`, `maintenance`, `reserved_closed`

**Common transitions (driven by `Room::transitionStatusTo()`):**

| Trigger                    | From              | To                  |
|----------------------------|-------------------|---------------------|
| Walk-in / Check-in         | available/prep    | `occupied`          |
| Check-out                  | occupied          | `checkout_makeup`   |
| Housekeeping done          | checkout_makeup   | `available`         |
| Admin manual update        | any → any         | (validated)         |

> The `transitionStatusTo()` method enforces valid transitions and logs who changed the status.

---

## Appendix

### Role Permission Matrix

| Endpoint Group        | Public | User  | Admin |
|-----------------------|--------|-------|-------|
| Auth (login/register) | ✅     | ✅    | ✅    |
| Rooms (read)          | ✅     | ✅    | ✅    |
| Availability          | ✅     | ✅    | ✅    |
| Global Rates (read)   | ✅     | ✅    | ✅    |
| Profile (`/me`)       | ❌     | ✅    | ✅    |
| Create Booking        | ❌     | ✅    | ✅    |
| View Own Bookings     | ❌     | ✅    | ✅    |
| View All Bookings     | ❌     | ❌    | ✅    |
| User Management       | ❌     | ❌    | ✅    |
| Booking Status Change | ❌     | ❌    | ✅    |
| Assign Rooms          | ❌     | owner | ✅    |
| Add Rooms to Booking  | ❌     | owner | ✅    |
| Front Desk Ops        | ❌     | ❌    | ✅    |
| Room Status Update    | ❌     | ❌    | ✅    |
| Global Rate Update    | ❌     | ❌    | ✅    |
| Validate Discount (preview) 🎟️ | ❌ | ✅ | ✅ |
| Apply/Remove Discount Code (draft) 🎟️ | ❌ | owner | ✅ |
| Discounts Admin CRUD / Toggle 🎟️ | ❌ | ❌ | ✅ |
| Dashboard/Housekeeping| ❌     | ❌    | ✅    |
| Payment Webhook       | ✅     | ✅    | ✅    |

---

### Draft / Incomplete Endpoints 🚧

These endpoints exist but are **not production-ready**:

| Endpoint / Module                   | Status                          |
|-------------------------------------|---------------------------------|
| `POST /payments`                    | 🚧 Demo (mock gateway)          |
| `POST /payment/webhook`             | 🚧 Demo (signature verify TBD)  |
| `POST /front-desk/{id}/payment`     | ⚠️ Demo record-payment (admin)  |
| Receipt model / auto-generation     | ⚠️ Demo (tied to payment flow)  |

> 🖼️ **(19/08/26)** — `POST /upload-image` (draft, unauthenticated) ถูก**ถอดออกแล้ว** — ระบบรูปใช้งานจริงผ่าน flow ของเจ้าของ (เช่น `POST /bookings/{id}/confirm`) + ดูผ่าน `GET /images/{id}/file` (signed URL)

---

### Pricing Notes

- All prices stored as **integers** (satang/cents) since 2026-06-05 — baht ที่ขอบ API เฉพาะ `rates` object + `extra_bed_price` ของ RoomType (baht string 2 ตำแหน่ง, 03/09/26).
- Room rates come from `global_rates` (rows where `rate_type='daily'` หรือ `rate_type='daily_ku'` สำหรับผู้ใช้ role `ku_member`).
- 🌟 **KU Member Pricing (07/09/26)**: การคิดเงินรองรับ `daily_ku` สำหรับผู้ใช้ role `ku_member` อัตโนมัติ (fallback ไป `daily` หากไม่มีเรท KU) — ส่วน `group` / `month` rows ยังคงเป็น display-only ผ่าน `rates` object
- Addon rates come from `global_rates.default_price` — **server-side only** (clients cannot send prices).
- Pricing formula per room:
  ```
  subtotal = (global_rates[rate_type='daily'].default_price × nights)
           + (extra_bed_qty × extra_bed_rate × nights)
           + (breakfast_qty × breakfast_rate)
           + (early_hours × early_checkin_rate)
           + (late_hours × late_checkout_rate)
  ```

---

### Confirmation & Receipt Number Formats

| Type           | Format                  | Example            |
|----------------|-------------------------|--------------------|
| Confirmation # | `YYYYMM-XXXXX`          | `202606-00001`     |
| Receipt #      | `REC-YYYYMM-XXXXX`      | `REC-202606-00001` |

Both use atomic counters (`booking_sequences` / `receipt_sequences` tables) with `SELECT FOR UPDATE` to prevent collisions.

---

### cURL Examples

**Login:**
```bash
curl -X POST https://ku-home.ku.ac.th/backend/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'
```

**Create Booking:**
```bash
curl -X POST https://ku-home.ku.ac.th/backend/api/v1/bookings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer 1|your_token_here" \
  -d '{
    "source": "online",
    "booking_rooms": [{
      "room_type_id": "rt-uuid",
      "check_in": "2026-07-01",
      "check_out": "2026-07-03",
      "guests": [{"title":"Mr.","name":"Test","nationality":"Thai"}],
      "billing_address": null,
      "billing_comment": null,
      "addons": {"extra_bed": 0, "breakfast": 2, "early_checkin": 0, "late_checkout": 0}
    }]
  }'
```

**Check-in (Front Desk):**
```bash
curl -X POST https://ku-home.ku.ac.th/backend/api/v1/front-desk/booking-uuid/check-in \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer 1|admin_token_here" \
  -d '{"assigned_rooms":["room-uuid-1"]}'
```

---

*Last updated: 2026-07-14 · KU HOME API v1*