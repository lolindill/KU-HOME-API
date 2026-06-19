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
8. [Addon Rates](#addon-rates)
9. [Dashboard / Housekeeping](#dashboard--housekeeping)
10. [DB Models Reference](#db-models-reference)
11. [State Machines](#state-machines)
12. [Appendix](#appendix)

---

## Quick Start

### Base URL

```
http://localhost/api/v1
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

| Role   | Description                                |
|--------|--------------------------------------------|
| `admin`| Full access — manage users, bookings, rooms |
| `user` | Member — book & manage own bookings         |

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
| `password`              | required, string, min 8, confirmed      |

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
  "total_types": 5,
  "room_types": [
    {
      "id": "uuid",
      "name_en": "Standard",
      "name_th": "ห้องมาตรฐาน",
      "rate_daily_general": 1200,
      "rate_daily_ku": 900,
      "max_occupancy": 2,
      "builtin_extra_beds": 1,
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
  "room_type": { ...room type object... }
}
```

---

### GET `/availability` — Check room availability

🔒 **Public**

**Query Params:**
- `check_in` (optional, date, ≥ today) — default: today
- `check_out` (optional, date, > check_in) — default: tomorrow

**Response `200`:**
```json
{
  "status": "success",
  "message": "Room availability fetched successfully",
  "room_types": [
    {
      "room_type_id": "uuid",
      "name_en": "Standard",
      "name_th": "ห้องมาตรฐาน",
      "available_rooms": 8,
      "search_criteria": {
        "check_in": "2026-06-19",
        "check_out": "2026-06-20"
      }
    }
  ]
}
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
      "check_in": "2026-06-20",
      "check_out": "2026-06-22",
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
          "guests": [
            { "title": "Mr.", "name": "Somchai", "nationality": "Thai", "is_ku_member": false }
          ],
          "children": 0,
          "room_type": { ...room type... },
          "room": null,
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
  "check_in": "2026-06-20",
  "check_out": "2026-06-22",
  "booking_rooms": [
    {
      "room_type_id": "rt-uuid",
      "quantity": 1,
      "extra_beds": 0,
      "guests": [
        {
          "title": "Mr.",
          "name": "Somchai Jaidee",
          "nationality": "Thai",
          "is_ku_member": false
        }
      ],
      "children": 0,
      "addons": {
        "breakfast": 2,
        "early_checkin": false,
        "late_checkout": false
      }
    }
  ]
}
```

**Validation Rules:**

| Field                                       | Rule                                          |
|---------------------------------------------|-----------------------------------------------|
| `source`                                    | required, in: `online`, `admin`, `line`       |
| `check_in`                                  | required, date, ≥ today                       |
| `check_out`                                 | required, date, > check_in                    |
| `booking_rooms`                             | required, array                               |
| `booking_rooms.*.room_type_id`              | required, uuid, exists in room_types          |
| `booking_rooms.*.quantity`                  | required, integer, min 1                      |
| `booking_rooms.*.extra_beds`                | nullable, integer, min 0                      |
| `booking_rooms.*.guests`                    | nullable, array                               |
| `booking_rooms.*.guests.*.title`            | nullable, string, max 50                      |
| `booking_rooms.*.guests.*.name`             | nullable, string, max 255                     |
| `booking_rooms.*.guests.*.nationality`      | nullable, string, max 100                     |
| `booking_rooms.*.guests.*.is_ku_member`     | nullable, boolean                             |
| `booking_rooms.*.children`                  | nullable, integer, min 0                      |
| `booking_rooms.*.addons.breakfast`          | nullable, integer, min 0                      |
| `booking_rooms.*.addons.early_checkin`      | nullable, boolean                             |
| `booking_rooms.*.addons.late_checkout`      | nullable, boolean                             |

> 💡 **Pricing**: Server calculates all prices from `room_types.rate_daily_*` and `addon_rates.default_price`. Client **cannot** send prices (prevents manipulation). `booking_rooms.*.quantity` is the requested count — server creates that many identical BookingRoom rows.

**Response `201`:**
```json
{
  "status": "success",
  "message": "Booking and Add-ons created successfully",
  "booking_id": "booking-uuid",
  "total_amount": 2400,
  "payment_deadline": "2026-06-20T11:00:00.000000Z",
  "user_id": "user-uuid"
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
    "check_in": "2026-06-20",
    "check_out": "2026-06-22",
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
        "guests": [...],
        "children": 0,
        "addon": {...},
        "room_type": {...},
        "room": {...}
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

**Allowed `status` values:**
`draft`, `paid`, `confirmed`, `checked_in`, `checked_out`, `cancelled`, `no_show`, `deleted`

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
        "room_id": "room-uuid",
        "room": { "id": "room-uuid", "room_number": "101", ... }
      }
    ]
  }
}
```

**Response `422`:**
- Booking not in `paid`/`confirmed` status
- No rooms available for the requested type

---

### POST `/bookings/validate-discount` — Validate discount code 🚧

🔒 **Auth required**

> 🚧 **DRAFT / TESTING** — Discount system incomplete. Do not use in production.

**Request Body:**
```json
{
  "code": "WELCOME10",
  "subtotal": 2400
}
```

**Response `200`:**
```json
{
  "status": "success",
  "discount_applied": 240,
  "net_total": 2160
}
```

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
      "nationality": "Thai",
      "is_ku_member": false
    }
  ],
  "children": 0
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
| `guests.*.is_ku_member`  | nullable, boolean                   |
| `children`               | nullable, integer, min 0            |

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

### POST `/front-desk/{bookingId}/payment` — Record payment

🔒 **Admin only**

Records a completed payment. Auto-marks booking `is_paid=true` and transitions `draft → paid` if applicable. Creates a receipt when fully paid.

**Request Body:**
```json
{
  "amount": 2400,
  "payment_method": "cash",
  "reference_number": "CASH-2026-001",
  "received_by": "admin-uuid"
}
```

**Validation Rules (StorePaymentRequest):**

| Field              | Rule                                                |
|--------------------|-----------------------------------------------------|
| `booking_id`       | required, uuid, exists in bookings                  |
| `amount`           | required, integer, min 1                            |
| `payment_method`   | required, in: `cash`, `credit_card`, `transfer`, `qr`, `other` |
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
    "payment_method": "cash",
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

### POST `/payments` — Request payment (Admin)

🔒 **Admin only**

Creates a `pending` payment and returns a mock payment URL.

**Request Body:**
```json
{
  "booking_id": "booking-uuid",
  "payment_method": "credit_card"
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
> 4. Generates a receipt

---

## Addon Rates

### GET `/addon-rates` — List all addon rates

🔒 **Public**

**Response `200`:**
```json
{
  "status": "success",
  "message": "ดึงรายการ Add-on Rates เรียบร้อยแล้วค่ะ! ✨",
  "rates": [
    {
      "id": "rate-uuid",
      "code": "breakfast",
      "name_en": "Breakfast",
      "name_th": "อาหารเช้า",
      "default_price": 150,
      "is_active": true
    },
    {
      "id": "rate-uuid",
      "code": "early_checkin",
      "name_en": "Early Check-in",
      "name_th": "เช็คอินก่อนเวลา",
      "default_price": 200,
      "is_active": true
    },
    {
      "id": "rate-uuid",
      "code": "late_checkout",
      "name_en": "Late Check-out",
      "name_th": "เช็คเอาท์ช้ากว่าเวลา",
      "default_price": 200,
      "is_active": true
    },
    {
      "id": "rate-uuid",
      "code": "extra_bed",
      "name_en": "Extra Bed",
      "name_th": "เตียงเสริม",
      "default_price": 300,
      "is_active": true
    }
  ]
}
```

---

### GET `/addon-rates/{id}` — Get addon rate by ID

🔒 **Public**

**Response `200`:**
```json
{
  "status": "success",
  "message": "ดึงข้อมูล Add-on Rate เรียบร้อยแล้วค่ะ! ✨",
  "rate": { ...rate object... }
}
```

---

### PUT `/addon-rates/{id}` — Update addon rate (Admin)

🔒 **Admin only**

**Request Body (all optional):**
```json
{
  "name_en": "Breakfast Buffet",
  "name_th": "บุฟเฟ่ต์อาหารเช้า",
  "default_price": 180,
  "is_active": true
}
```

**Response `200`:**
```json
{
  "status": "success",
  "message": "อัปเดต Add-on Rate เรียบร้อยแล้วค่ะ! ✨",
  "rate": { ...updated rate... }
}
```

---

### PATCH `/addon-rates/{id}/toggle` — Toggle addon rate active state (Admin)

🔒 **Admin only**

**Response `200`:**
```json
{
  "status": "success",
  "message": "ปิดใช้งาน Add-on Rate เรียบร้อยแล้วค่ะ! ✨",
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
| `role`        | enum      | `admin` / `user` (default: `user`)     |
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
| `status`           | enum      | See [Booking State Machine](#booking-state-machine)     |
| `check_in`         | date      |                                                         |
| `check_out`        | date      |                                                         |
| `total_amount`     | integer   | In baht (no decimals — integer since 2026-06-05)        |
| `is_paid`          | boolean   | Default: false                                          |
| `payment_deadline` | datetime  | For draft bookings (24h from creation)                  |
| `created_at`       | timestamp |                                                         |
| `updated_at`       | timestamp |                                                         |

**Relationships:**
- `belongsTo User` — owner
- `hasMany BookingRoom` — the rooms in this booking

> 🌟 **Refactor (18/06/26)**: Guest name/title/nationality moved from `bookings` to `booking_rooms.guests` JSON. `bookings` no longer stores guest info.

---

### BookingRoom

| Field          | Type      | Description                                              |
|----------------|-----------|---------------------------------------------------------|
| `id`           | UUID      | Primary key                                             |
| `booking_id`   | UUID      | FK → bookings                                           |
| `room_type_id` | UUID      | FK → room_types (what was booked)                       |
| `room_id`      | UUID      | FK → rooms (nullable — assigned at check-in)            |
| `guests`       | JSON      | Array of `{title, name, nationality, is_ku_member}`     |
| `children`     | integer   | Number of children in this room                         |
| `created_at`   | timestamp |                                                         |
| `updated_at`   | timestamp |                                                         |

**Guests JSON Structure:**
```json
[
  {
    "title": "Mr.",
    "name": "Somchai Jaidee",
    "nationality": "Thai",
    "is_ku_member": false
  },
  {
    "title": "Ms.",
    "name": "Suda Jaidee",
    "nationality": "Thai",
    "is_ku_member": true
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

| Field                  | Type    | Description                            |
|------------------------|---------|----------------------------------------|
| `id`                   | UUID    | Primary key                            |
| `name_en`              | string  | English name                           |
| `name_th`              | string  | Thai name                              |
| `rate_daily_general`   | integer | Daily rate for general public (baht)   |
| `rate_daily_ku`        | integer | Daily rate for KU members (baht)       |
| `max_occupancy`        | integer | Max guests                             |
| `builtin_extra_beds`   | integer | Default extra bed capacity             |
| `created_at`           | timestamp |                                      |
| `updated_at`           | timestamp |                                      |

**Relationships:**
- `hasMany Room`
- `hasMany BookingRoom`

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
| `early_checkIn_price`  | integer | Early check-in price (0 if not selected) |
| `late_checkOut_price`  | integer | Late check-out price (0 if not selected) |
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

| Field              | Type      | Description                                              |
|--------------------|-----------|---------------------------------------------------------|
| `id`               | UUID      | Primary key                                             |
| `booking_id`       | UUID      | FK → bookings                                           |
| `amount`           | integer   | In baht (integer since 2026-06-05)                      |
| `payment_method`   | enum      | `cash` / `credit_card` / `transfer` / `qr` / `other`    |
| `status`           | enum      | `pending` / `completed` / `failed`                      |
| `reference_number` | string    | Bank/gateway reference (nullable)                       |
| `received_by`      | UUID      | FK → users (admin who received cash, nullable)          |
| `created_at`       | timestamp |                                                         |
| `updated_at`       | timestamp |                                                         |

**Relationships:**
- `belongsTo Booking`

---

### Receipt

| Field          | Type      | Description                                       |
|----------------|-----------|---------------------------------------------------|
| `receipt_no`   | string    | Format: `RCP-YYYYMM-XXXXX` (auto-generated)       |
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

### Booking State Machine

```
                          ┌──────────────────────────────┐
                          │           cancelled           │ ◄──── admin (from paid/confirmed)
                          └──────────────────────────────┘

   ┌─────────┐  user/admin  ┌─────────┐  admin   ┌────────────┐  admin   ┌─────────────┐  admin  ┌─────────────┐
   │  draft  │ ───────────► │   paid  │ ───────► │ confirmed  │ ───────► │ checked_in  │ ──────► │ checked_out │
   └─────────┘              └─────────┘          └────────────┘          └─────────────┘         └─────────────┘
       │                        │                      │
       │ admin (walk-in)        │                      │ admin
       └────────────────────────┼──────────────────────┴───► no_show
                                │
       user/admin/system        │
       └──────────────────► deleted

   Special: webhook (system role) can do draft → paid only
```

**Valid Transitions:**

| From          | To            | Allowed Roles                    |
|---------------|---------------|----------------------------------|
| `draft`       | `paid`        | user, guest, admin, system       |
| `draft`       | `checked_in`  | admin (walk-in only)             |
| `draft`       | `deleted`     | user, guest, admin, system       |
| `paid`        | `confirmed`   | admin                            |
| `paid`        | `cancelled`   | admin                            |
| `confirmed`   | `cancelled`   | admin                            |
| `confirmed`   | `checked_in`  | admin                            |
| `confirmed`   | `no_show`     | admin                            |
| `checked_in`  | `checked_out` | admin                            |

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
| Addon Rates (read)    | ✅     | ✅    | ✅    |
| Profile (`/me`)       | ❌     | ✅    | ✅    |
| Create Booking        | ❌     | ✅    | ✅    |
| View Own Bookings     | ❌     | ✅    | ✅    |
| View All Bookings     | ❌     | ❌    | ✅    |
| User Management       | ❌     | ❌    | ✅    |
| Booking Status Change | ❌     | ❌    | ✅    |
| Assign Rooms          | ❌     | owner | ✅    |
| Front Desk Ops        | ❌     | ❌    | ✅    |
| Room Status Update    | ❌     | ❌    | ✅    |
| Addon Rate Update     | ❌     | ❌    | ✅    |
| Dashboard/Housekeeping| ❌     | ❌    | ✅    |
| Payment Webhook       | ✅     | ✅    | ✅    |

---

### Draft / Incomplete Endpoints 🚧

These endpoints exist but are **not production-ready**:

| Endpoint                            | Status                  |
|-------------------------------------|-------------------------|
| `POST /bookings/validate-discount`  | 🚧 Testing only         |
| `POST /upload-image`                | 🚧 Testing only         |

---

### Pricing Notes

- All prices stored as **integers** (baht, no decimals) since 2026-06-05.
- Room rates come from `room_types.rate_daily_general` (or `rate_daily_ku` for members).
- Addon rates come from `addon_rates.default_price` — **server-side only** (clients cannot send prices).
- Pricing formula per room:
  ```
  subtotal = (room_type.rate × nights)
           + (extra_bed_qty × extra_bed_rate × nights)
           + (breakfast_qty × breakfast_rate)
           + (early_checkin ? early_checkin_rate : 0)
           + (late_checkout ? late_checkout_rate : 0)
  ```

---

### Confirmation & Receipt Number Formats

| Type           | Format                  | Example            |
|----------------|-------------------------|--------------------|
| Confirmation # | `YYYYMM-XXXXX`          | `202606-00001`     |
| Receipt #      | `RCP-YYYYMM-XXXXX`      | `RCP-202606-00001` |

Both use atomic counters (`booking_sequences` / `receipt_sequences` tables) with `SELECT FOR UPDATE` to prevent collisions.

---

### cURL Examples

**Login:**
```bash
curl -X POST http://localhost/api/v1/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'
```

**Create Booking:**
```bash
curl -X POST http://localhost/api/v1/bookings \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer 1|your_token_here" \
  -d '{
    "source": "online",
    "check_in": "2026-07-01",
    "check_out": "2026-07-03",
    "booking_rooms": [{
      "room_type_id": "rt-uuid",
      "quantity": 1,
      "guests": [{"title":"Mr.","name":"Test","nationality":"Thai","is_ku_member":false}]
    }]
  }'
```

**Check-in (Front Desk):**
```bash
curl -X POST http://localhost/api/v1/front-desk/booking-uuid/check-in \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer 1|admin_token_here" \
  -d '{"assigned_rooms":["room-uuid-1"]}'
```

---

*Last updated: 2026-06-19 · KU HOME API v1*