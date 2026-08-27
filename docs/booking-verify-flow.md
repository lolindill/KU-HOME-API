# 🌟 Booking → Admin Verify Flow

> **End-to-end flow** ตั้งแต่ผู้ใช้สร้างการจอง ส่งหลักฐานการชำระ (slip) จนกระทั่งแอดมินตรวจสอบ/ยืนยัน
> ใช้ระบบ `booking_confirmations` (1:N history) แทน payments/receipts ที่ถูก freeze ไปแล้ว 🌟 Refactor (24/07/26)
> อัปเดตล่าสุด: เพิ่มสถานะ `pending` และ `verify_error` สำหรับ booking container (25/08/26)

Base URL: `/api/v1/` · Auth: Laravel Sanctum (Bearer Token)

---

## 📑 Overview — Flow ในภาพรวม

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          USER (login required)                          │
└─────────────────────────────────────────────────────────────────────────┘

  ① login/register           ② create booking             ③ submit slip
  ────────────────           ───────────────              ────────────────
  POST /login                POST /bookings               POST /bookings/{id}/confirm
  POST /register             → booking.status = draft     → confirmation.status = pending
  → access_token             → payment_deadline = +24h    → booking.status = pending
                                                          → slip stored at storage/app/private/slips

                                          │
                                          ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                              ADMIN (role:admin)                         │
└─────────────────────────────────────────────────────────────────────────┘

  ④ list pending             ⑤a verify  ✅   OR   ⑤b reject ❌
  ────────────────           ───────────────       ────────────────
  GET /booking-confirmations PUT /.../{id}/verify  PUT /.../{id}/reject
       /pending              → confirmation        → confirmation
                              pending→verified       pending→rejected
                              → booking             → booking
                              pending→paid           pending→verify_error
                              →confirmed            (รอ user ส่ง slip ใหม่)
```

> 💡 การ reject จะเปลี่ยนสถานะ booking เป็น `verify_error` — ผู้ใช้ส่ง slip ใหม่ (`POST /bookings/{id}/confirm`) จะเปลี่ยน booking กลับมาเป็น `pending` และสร้าง confirmation row ใหม่ (รักษา audit trail)

---

## 🚦 State Machines ที่เกี่ยวข้อง

### Booking (Container)

```
   ┌─────────┐ user/guest/admin ┌─────────┐  admin   ┌─────────┐   admin    ┌────────────┐
   │  draft  │ ───────────────► │ pending │ ───────► │   paid  │ ─────────► │ confirmed  │ → ...complete
   └─────────┘                  └─────────┘          └─────────┘            └────────────┘
       │                            │
       │ admin/system               │ admin (reject)
       │ (เงินสดหน้าเคาน์เตอร์)         ▼
       │                      ┌──────────────┐ user/guest/admin (ส่งสลิปใหม่)
       │                      │ verify_error │ ───────────────────────────────┘
       │                      └──────────────┘
       │ admin walk-in skip → confirmed (ไม่เกี่ยวกับ flow นี้)
       └──────────────────────────────────────────────────► confirmed
```

| From           | To             | Trigger                                      |
|----------------|----------------|----------------------------------------------|
| `draft`        | `pending`      | user/guest/admin POST confirm (slip)         |
| `pending`      | `paid`         | admin verify slip (set `is_paid=true`)       |
| `pending`      | `verify_error` | admin reject slip                            |
| `verify_error` | `pending`      | user/guest/admin POST confirm (ส่งสลิปใหม่)    |
| `paid`         | `confirmed`    | admin verify slip flow                       |
| `confirmed`    | `complete`     | (auto เมื่อ BR ทุกห้อง checked_out/no_show)  |

### BookingConfirmation

```
   ┌─────────┐ admin  ┌──────────┐
   │ pending │ ─────► │ verified │ (terminal — booking pending → paid → confirmed)
   └─────────┘        └──────────┘
       │
       │ admin
       ▼
   ┌──────────┐
   │ rejected │ (terminal — booking เปลี่ยนเป็น verify_error; user ส่งสลิปใหม่สร้าง row ใหม่)
   └──────────┘
```

✅ **1 pending max guard** — ถ้ามี pending อยู่แล้ว → POST confirm จะ 422 (กัน spam)
✅ `verified`/`rejected` = terminal ถ้าจะแก้ต้องสร้าง row ใหม่ (audit trail)
✅ `verify_error` ไม่ถูก `CleanupExpiredDrafts` ลบ (ห้องยังถูก hold ไว้ตาม availability)

---

## 📋 Step-by-Step (แต่ละ step พร้อม API)

### Step ① — Login / Register (เตรียม token)

> ทุกคนที่จะจองต้องล็อกอิน (guest/non-member ใช้งานไม่ได้ — Refactor 18/06/26)

#### `POST /api/v1/login`

🔒 Public · Throttle `5,1`

**Body:**
```json
{ "email": "somchai@example.com", "password": "SecurePass123!" }
```

**Response 200:**
```json
{
  "status": "success",
  "message": "Login successful",
  "access_token": "1|abcdef...",
  "token_type": "Bearer"
}
```

> หรือ `POST /api/v1/register` (public) → ได้ token กลับมาเหมือนกัน

จากนี้ทุก request ต้องแนบ header:
```
Authorization: Bearer <access_token>
Accept: application/json
```

---

### Step ② — Create Booking (draft)

> ⚠️ **Constraint**: ผู้ใช้ไม่สามารถมี booking `draft` อื่นที่ยังไม่หมดเวลาได้ (block "สายดอง")

#### `POST /api/v1/bookings`

🔒 Auth · Throttle `5,1`

**Body:**
```json
{
  "source": "online",
  "booking_rooms": [
    {
      "room_type_id": "rt-uuid",
      "check_in": "2026-08-10",
      "check_out": "2026-08-12",
      "extra_beds": 0,
      "guests": [
        { "title": "Mr.", "name": "Somchai Jaidee", "nationality": "Thai" }
      ],
      "billing_address": null,
      "billing_comment": null,
      "addons": { "breakfast": 2, "early_checkin": 0, "late_checkout": 0 }
    }
  ]
}
```

**Response 201:**
```json
{
  "status": "success",
  "message": "Booking and Add-ons created successfully",
  "booking_id": "booking-uuid",
  "total_amount": 2400,
  "payment_deadline": "2026-08-07T11:00:00.000000Z",
  "user_id": "user-uuid"
}
```

หลังจากนี้ booking อยู่ในสถานะ **`draft`** พร้อม `payment_deadline` ภายใน **24 ชม.** (เก็บ `booking_id` ไว้ใช้ใน step ถัดไป)

**Guards (422):**
- ❌ มี draft ค้างอยู่แล้ว (เหมือนเดิม) → `มีรายการจองที่รอชำระเงินอยู่ค่ะ...`
- ❌ ห้องเต็มในช่วงเวลาที่เลือก → `ห้องพักประเภทที่เลือกเต็มแล้วในช่วงเวลาดังกล่าวค่ะ`

> 💡 ราคา server คำนวณเองจาก `global_rates` — client ส่ง price มาไม่ได้ (กัน price manipulation)

---

### Step ③ — Submit Payment Proof (slip)

> User แนบหลักฐานการชำระเงิน → สร้าง `booking_confirmations` row + booking `draft → pending` (หรือ `verify_error → pending`)

#### `POST /api/v1/bookings/{bookingId}/confirm`

🔒 Auth · Ownership: เจ้าของ booking หรือ admin · Throttle `5,1`
**Content-Type:** `multipart/form-data` (เพราะมีไฟล์ slip)

**Path param:** `bookingId` = UUID ที่ได้จาก Step ②

**Form fields:**

| Field             | Type      | Required When          | Description                                  |
|-------------------|-----------|------------------------|----------------------------------------------|
| `slip_image`      | file      | เสมอ                   | jpeg/png/jpg, max 4MB (บังคับเสมอ)          |
| `transfer_time`   | datetime  | optional               | เวลาที่ลูกค้าแจ้งโอน (จากสลิป), ไม่ใช่อนาคต |

> 🌟 Refactor (19/08/26): ลบ `payment_method` ออกแล้ว — flow เหลือ "ส่งสลิป → รอแอดมินตรวจ" อย่างเดียว
> 🖼️ สลิปเก็บใน private disk (`storage/app/private/slips`) และดูผ่าน signed URL อายุ 15 นาที

**Example:**
```bash
curl -X POST /api/v1/bookings/BOOKING_UUID/confirm \
  -H "Authorization: Bearer <token>" \
  -F "slip_image=@slip.jpg" \
  -F "transfer_time=2026-08-06T10:30:00Z"
```

**Response 201:**
```json
{
  "status": "success",
  "message": "ส่งหลักฐานการชำระเรียบร้อย — รอแอดมินตรวจสอบค่ะนายท่าน",
  "confirmation_id": "confirmation-uuid",
  "confirmation_status": "pending",
  "booking_status": "pending",
  "slip_image_url": "http://localhost/api/v1/images/img-uuid/file?expires=...&signature=..."
}
```

หลังจากนี้ `confirmation.status = pending` + `booking.status = pending` (เก็บ `confirmation_id` ไว้สำหรับ admin ใน Step ⑤)

**Guards (422):**
- ❌ Booking ไม่ใช่ `draft` หรือ `verify_error`
- ❌ หมดเวลา (`payment_deadline` ผ่านแล้ว — ตรวจสอบเฉพาะสถานะ `draft`, `verify_error` ส่งใหม่ได้เสมอ)
- ❌ มี confirmation `pending` อยู่แล้ว (1 pending max — กัน spam)
- ❌ ไม่ส่ง `slip_image` (บังคับเสมอ)

---

### Step ④ — Admin: List Pending Confirmations (dashboard)

> แอดมินดูคิวสลิปที่รอตรวจ (FIFO เก่าก่อน)

#### `GET /api/v1/booking-confirmations/pending`

🔒 Admin only

**Response 200:**
```json
{
  "status": "success",
  "confirmations": {
    "data": [
      {
        "id": "confirmation-uuid",
        "booking_id": "booking-uuid",
        "transfer_time": "2026-08-06T10:30:00.000000Z",
        "status": "pending",
        "booking": {
          "id": "booking-uuid",
          "confirmation": "202608-00001",
          "total_amount": 2400,
          "user": { "id": "user-uuid", "name": "Somchai Jaidee", "email": "..." }
        },
        "reviewer": null,
        "slip_image": {
          "id": "img-uuid",
          "url": "http://localhost/api/v1/images/img-uuid/file?expires=...&signature=..."
        }
      }
    ],
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

จาก list นี้ admin เลือก `confirmation_id` เพื่อ review ใน Step ⑤

---

### Step ⑤a — Admin: Verify Slip ✅

> ยืนยันว่าสลิปถูกต้อง → confirmation `pending → verified` + booking `pending → paid → confirmed` (+ `is_paid=true`)

#### `PUT /api/v1/booking-confirmations/{id}/verify`

🔒 Admin only

**Path param:** `id` = confirmation UUID (จาก Step ④)

**Body (optional):**
```json
{ "review_note": "สลิปถูกต้อง ยอดตรง" }
```

**Response 200:**
```json
{
  "status": "success",
  "message": "ยืนยันการชำระเงินเรียบร้อย — booking confirmed",
  "confirmation": {
    "id": "confirmation-uuid",
    "status": "verified",
    "reviewed_by": "admin-uuid",
    "reviewed_at": "2026-08-06T11:00:00.000000Z",
    "review_note": "สลิปถูกต้อง ยอดตรง"
  },
  "booking_status": "confirmed"
}
```

หลังจากนี้ booking `confirmed` พร้อมเข้าสู่ flow check-in / front desk (BookingRoom `draft → confirmed → checked_in → checked_out`)

---

### Step ⑤b — Admin: Reject Slip ❌

> สลิปไม่ผ่าน → confirmation `pending → rejected` + **booking `pending → verify_error`** (รอ user ส่ง slip ใหม่)

#### `PUT /api/v1/booking-confirmations/{id}/reject`

🔒 Admin only

**Body:**
```json
{ "review_note": "สลิปไม่ชัด กรุณาส่งใหม่อีกครั้ง" }
```

**Response 200:**
```json
{
  "status": "success",
  "message": "ปฏิเสธสลิป — booking เปลี่ยนสถานะเป็น verify_error รอผู้จองแจ้งใหม่",
  "confirmation": {
    "id": "confirmation-uuid",
    "status": "rejected",
    "review_note": "สลิปไม่ชัด กรุณาส่งใหม่อีกครั้ง"
  },
  "booking_status": "verify_error"
}
```

➡️ **ถ้า user จะลองอีก**: กลับไป Step ③ (POST confirm ใหม่) — booking เปลี่ยนเป็น `pending` และ server สร้าง confirmation row ใหม่ status=pending (row rejected เดิมยังอยู่ใน history)

---

## 🔁 ทางแยกหลัง confirmed (เกริ่นนำ)

หลัง booking `confirmed` แล้ว flow จะไปต่อยัง (อยู่นอก scope เอกสารนี้):

| Action | API | ผลลัพธ์ |
|---|---|---|
| ระบุเลขห้อง (auto cluster) | `PUT /bookings/{id}/assign-rooms` | BookingRoom `draft → confirmed` + `room_id` |
| Check-in | `POST /front-desk/{id}/check-in` | BR → `checked_in` + Room → `occupied` |
| Check-out | `POST /front-desk/{id}/check-out` | BR → `checked_out` + สร้าง housekeeping task |
| Mark No-show | `POST /front-desk/{id}/mark-no-show` | BR → `no_show` |

เมื่อ BR ทุกห้องถึง terminal (`checked_out`/`no_show`) → booking container auto-sync → `complete`

---

## 📝 Audit Trail (Status Change Logs)

ทุก transition ผ่าน `transitionStatus()` เขียน row ใน `status_change_logs` อัตโนมัติ:

| Transition | entity_type | from → to | role |
|---|---|---|---|
| Step ③ confirm (ครั้งแรก) | `booking` | `draft → pending` | `user` / `guest` / `admin` |
| Step ③ confirm (ส่งใหม่) | `booking` | `verify_error → pending` | `user` / `guest` / `admin` |
| Step ⑤a verify | `booking` | `pending → paid` | `admin` |
| Step ⑤a verify | `booking` | `paid → confirmed` | `admin` |
| Step ⑤b reject | `booking` | `pending → verify_error` | `admin` |

#### `GET /api/v1/bookings/{id}/status-logs` — ดู audit trail (admin only)

🔒 Admin only · คืน log ของ booking container + ทุก booking_room ใต้ booking นั้น

> ⚠️ อย่า bypass `transitionStatus()` ด้วย `->status = ...` ตรงๆ — audit trail จะหาย
> ⚠️ `causer_id` nullable สำหรับ system/queue transitions

---

## 🛡️ Guards สรุปรวม

| Check | ที่ไหน | Error |
|---|---|---|
| Login required | ทุก protected route | 401 |
| Ownership (user/admin) | confirm, showById | 403 |
| Admin role | verify/reject/pending | 403 |
| Booking status = draft/verify_error | confirm | 422 |
| `payment_deadline` ไม่หมด (เฉพาะ draft) | confirm | 422 |
| 1 pending confirmation max | confirm | 422 |
| `slip_image`/`transfer_time` เมื่อ transfer | ConfirmBookingRequest | 422 |
| Confirmation state machine | verify/reject | 422 |

---

## 📚 Reference

- `app/Http/Controllers/Api/V1/BookingController.php` — createBooking
- `app/Http/Controllers/Api/V1/BookingConfirmationController.php` — confirm / verify / reject / pending
- `app/Http/Requests/ConfirmBookingRequest.php` — slip validation
- `app/Http/Requests/ReviewConfirmationRequest.php` — review_note validation
- `app/Models/Booking.php` — `transitionStatus()` (state machine + audit log)
- `app/Models/BookingConfirmation.php` — confirmation state machine
- `routes/api.php` — route definitions
- `docs/api_guide.md` — API reference ฉบับเต็ม

*Last updated: 2026-08-25 · KU HOME API v1*