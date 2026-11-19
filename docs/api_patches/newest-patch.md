# 📢 API Patch Notes (Frontend) — 2026-11-16 (`dev` @ `4a1550e` / `main` @ `5365235`)

> **สำหรับ:** ทีม Frontend (`ku-home`)
> **ขอบเขต:** สรุปเฉพาะสิ่งที่ Frontend ต้องรู้/ต้องปรับ จากทุก commit ที่อัปเดตขึ้น `dev` และ `main` รอบนี้ (commits `7bf1418` → `4a1550e`)

---

## ⚡ TL;DR — FE ต้องทำอะไรบ้าง

| # | หัวข้อ | ประเภท | ผลกระทบ / สิ่งที่ FE ต้องทำ |
|---|---|---|---|
| 1 | **Tier-change / Change-room default คงราคาเดิม (`apply_new_rate`)** | 🟡 Behavior change | เมื่อเปลี่ยนประเภทห้องหรือย้ายห้องบนบิล `paid\|confirmed` ค่า default จะ **ไม่คิดเรทใหม่ (`surcharge_amount = 0`)** — ถ้า UI ต้องการให้คิดราคาตามเรทห้องใหม่ ต้องส่ง `"apply_new_rate": true` (หรือส่ง `"price_per_night"`) |
| 2 | **SSO Email Lock บน `PUT /profile`** | 🔴 Guard ใหม่ (`422`) | บัญชีที่ `auth_provider` เป็น `ku_sso` หรือ `google` **ห้ามส่ง `email`** มาใน `PUT /profile` (ส่งมาโดน `422`) — ให้ซ่อน/disable ช่อง email หรือตัดออกจาก payload |
| 3 | **Profile Avatar (`POST`/`DELETE /profile/avatar`)** | 🟢 Feature ใหม่ | อัปโหลด/ลบรูปโปรไฟล์ + `GET /me` และ `PUT /profile` คืน `user.avatar` (signed URL ต้องโหลดผ่าน `Authorization: Bearer` + blob URL) |
| 4 | **Shift Dates (`PUT /front-desk/{bookingId}/rooms/{bookingRoomId}/dates`)** | 🟢 Endpoint ใหม่ | ย้ายชุดวันเข้าพักแบบจำนวนคืนเท่าเดิม (shift-only) สำหรับบิล `paid\|confirmed` + BR `confirmed` ทั้งมีและไม่มีเลขห้อง (`admin`/`staff`) |
| 5 | **Slip Collect ผ่าน `POST /bookings/{bookingId}/confirm`** | 🟢 ขยายสิทธิ์ + field ใหม่ | `admin` + `staff` ส่งสลิป Collect บนบิล `paid\|confirmed` ได้ทุก `payment_type` แบบ instant-verify + response มี `booking_is_paid` และ `payment_recorded` |
| 6 | **Bugfixes (ไม่ต้องแก้โค้ด FE)** | 🟢 FYI | แก้แค่ `billing_comment`/`billing_address` ไม่ reprice ยอดบิลแล้ว · ห้องลูก `draft` auto-confirm เมื่อบิลเข้า `paid\|confirmed` · บิล `complete` ไม่กั๊ก slot ห้อง |

---

## 1. 🟡 Behavior Change: Tier-change & Change-room Default คงราคาเดิม (`apply_new_rate`)

กระทบ 2 endpoints (เฉพาะ `admin` + `staff` บนบิลที่ชำระแล้ว `paid` / `confirmed`):
- `PUT /api/v1/bookings/{bookingId}/rooms/{bookingRoomId}` (โหมด change-tier: BR `draft|confirmed` ที่ `room_id = null`)
- `POST /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/change-room`

### สิ่งที่เปลี่ยนจากเดิม
- **เดิม:** เปลี่ยน `room_type_id` หรือย้ายไปห้องคนละ type → ระบบคำนวณราคาใหม่ตาม `global_rates` ปัจจุบันให้อัตโนมัติ
- **ใหม่ (Default):** ถ้า **ไม่ส่ง `apply_new_rate`** หรือส่ง `"apply_new_rate": false` → ระบบจะ **คงราคาห้องเดิม (`room_amount`) ทั้งขาอัปเกรดและขาดาวน์เกรด** (`surcharge_amount = 0` สำหรับค่าห้อง) เหมาะกับเคสอัปเกรดฟรีหน้าเคาน์เตอร์
- **เมื่อต้องการคิดเรทห้องใหม่:** ส่ง `"apply_new_rate": true` (boolean) → ระบบจะคำนวณตาม effective rate ของประเภทห้องใหม่ ณ ปัจจุบัน (downgrade อนุญาตและไม่คืนเงิน, ส่วนต่างบวก/ปรับใน `outstanding_amount`)
- **เมื่อต้องการกำหนดราคาเอง:** ส่ง `"price_per_night": 1500` (integer บาท, `>= 0`) → **ชนะ `apply_new_rate` เสมอ** (คิด `price_per_night × nights`)

### ข้อยกเว้น / กติกาที่ไม่เปลี่ยน
- บิล **`draft`** บน `PUT /bookings/{id}/rooms/{brId}` ยังคำนวณราคาตามเรทปัจจุบันอัตโนมัติเหมือนเดิม (ไม่ใช้ `apply_new_rate`)
- ห้อง **`checked_in`** บน `PUT /bookings/{id}/rooms/{brId}` รับเฉพาะ `addons` / `extra_beds` เท่านั้น — ส่ง `apply_new_rate` หรือ `price_per_night` มาจะได้ `422`
- ใน `PUT` โหมด change-tier หากมีการ **เปลี่ยนจำนวนคืน** (`check_in`/`check_out`) หรือ **แก้ `addons`/`extra_beds`** ระบบยังคำนวณส่วนต่างส่วนนั้นตามปกติ

```json
// ตัวอย่าง: ย้ายห้อง/อัปเกรดฟรี (Default ไม่ต้องส่ง apply_new_rate หรือส่ง false)
// POST /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/change-room
{
  "room_id": "deluxe-room-uuid",
  "notes": "Complimentary upgrade"
}

// ตัวอย่าง: เปลี่ยนประเภทห้องแล้วต้องการให้คิดเรทใหม่ตามระบบ
// PUT /api/v1/bookings/{bookingId}/rooms/{bookingRoomId}
{
  "room_type_id": "deluxe-room-type-uuid",
  "apply_new_rate": true
}
```

---

## 2. 🔴 Guard ใหม่: SSO Email Lock บน `PUT /api/v1/profile`

- บัญชีที่ `auth_provider` เป็น `ku_sso` หรือ `google` (ตรวจสอบได้จาก `user.auth_provider` ใน `GET /me`) **ห้ามส่ง field `email`** มาใน body ของ `PUT /api/v1/profile`
- หากส่ง `email` มา (แม้ส่งเป็นค่าเดิม) backend จะตอบ **`422 Unprocessable Content`** (`email.prohibited`) เพื่อป้องกันปัญหา identity anchor เพี้ยนเวลาล็อกอินผ่าน SSO รอบถัดไป
- **สิ่งที่ FE ต้องทำ:** ในหน้าแก้ไขโปรไฟล์ของ user ให้ check `user.auth_provider !== 'password'` → disable ช่อง Email และ **ห้าม include key `email`** ใน JSON payload ที่ยิงไป `PUT /profile`
- บัญชี `auth_provider === 'password'` ยังแก้ `email` ได้ตามปกติ (และ Admin `PUT /users/{id}` ไม่กระทบ)

---

## 3. 🟢 Feature ใหม่: Profile Avatar (`POST` / `DELETE /profile/avatar`)

1 user มีรูปโปรไฟล์ได้ 1 รูป (อัปโหลดซ้ำ = แทนที่รูปเก่าให้อัตโนมัติ)

### `POST /api/v1/profile/avatar`
- **Auth:** Required (ทุก role) · **Throttle:** `10,1`
- **Content-Type:** `multipart/form-data`
- **Field:** `file` — required, รูปภาพ `jpeg` / `png` / `jpg` / `webp`, ขนาดไม่เกิน **2 MB** (`2048` KB)
- **Response `201`:**
```json
{
  "status": "success",
  "message": "อัปเดตรูปโปรไฟล์เรียบร้อยแล้วค่ะนายท่าน ✨",
  "data": {
    "id": "image-uuid",
    "file_name": "avatar.png",
    "mime_type": "image/png",
    "size": 12345,
    "url": "https://.../api/v1/images/<image-uuid>/file?expires=...&signature=...",
    "created_at": "2026-11-11T00:00:00.000000Z"
  }
}
```
- **Errors:** `401` ไม่ได้ล็อกอิน · `422` ไฟล์ผิดประเภท/เกิน 2MB

### `DELETE /api/v1/profile/avatar`
- **Auth:** Required (ทุก role)
- **Response `200`:** `{"status":"success","message":"ลบรูปโปรไฟล์เรียบร้อยแล้วค่ะ"}`
- **Errors:** `404` กรณียังไม่มี avatar ให้ลบ

### การแสดงรูป & Field ใน `GET /me` / `PUT /profile`
- `GET /api/v1/me` และ `PUT /api/v1/profile` จะคืน `user.avatar` เป็น Image object (มี `url`) หรือ `null` ถ้ายังไม่ได้ตั้ง
- **⚠️ วิธีโหลดรูป:** `url` ชี้ไปที่ `GET /api/v1/images/{id}/file?...` ซึ่งเป็น **signed URL อายุ 15 นาที และบังคับ `Authorization: Bearer <token>`** — **ห้ามใส่ลง `<img src={url}>` ตรง ๆ** ให้ใช้ `fetch(url, { headers: { Authorization: ... } })` แล้วแปลงเป็น Blob URL (pattern เดียวกับ slip/evidence) · ดูได้เฉพาะเจ้าของโปรไฟล์หรือ `admin` (role อื่น `403`) · ถ้า URL หมดอายุให้เรียก `GET /me` ใหม่เพื่อรับ `user.avatar.url` สด

---

## 4. 🟢 Endpoint ใหม่: ย้ายชุดวันเข้าพัก (Shift Dates)

```
PUT /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/dates
```

- **Auth:** `admin` หรือ `staff` เท่านั้น (role อื่น `403`) · ไม่มี throttle
- **Gate:** Booking สถานะ `paid` หรือ `confirmed` + BookingRoom สถานะ `confirmed` (ใช้ได้ทั้งห้องที่ **ยังไม่มี `room_id`** และห้องที่ **จัด `room_id` แล้ว**)
- **Request Body:**
```json
{
  "check_in": "2026-11-20",
  "check_out": "2026-11-24",
  "notes": "Guest postponed arrival (optional, max 255)"
}
```
- **กติกาสำคัญ (Validation / `422`):**
  - `check_in` + `check_out` required ทั้งคู่ (`check_in` >= วันนี้เวลา Asia/Bangkok, `check_out` > `check_in`)
  - **Shift-only:** จำนวนคืนชุดใหม่ต้องเท่าเดิมเป๊ะ (ถ้าต้องการเปลี่ยนจำนวนคืนและห้องยังไม่มีเลขห้อง ให้ใช้ `PUT /bookings/{id}/rooms/{brId}` แทน)
  - ชุดวันใหม่ห้ามซ้ำชุดวันเดิม
  - **ห้ามส่ง** `room_id`, `room_type_id`, หรือ `price_per_night` มาใน body (ส่งมาโดน `422`)
  - เพดานคืน: `staff` >= 20 คืน → `422` · ทุก role > 90 คืน → `422`
  - กรณี BR **มีเลขห้อง (`room_id` != null):** ตรวจห้องเดิมว่าว่างช่วงวันใหม่จริง (ไม่ชน booking อื่นและไม่ชน maintenance; ห้อง `reserved` ไม่บล็อก) — **ไม่ auto-ย้ายห้องให้** ถ้าไม่ว่างจะได้ `422` (FE แนะนำให้ใช้ `change-room` แทน)
  - กรณี BR **ยังไม่มีเลขห้อง (`room_id` = null):** ข้ามการตรวจเลขห้อง
- **พฤติกรรมราคา & สถานะ:**
  - เลื่อน `extra_beds_by_night` key ตามจำนวนวันที่ขยับให้อัตโนมัติ (จำนวนเตียงต่อคืนคงเดิม)
  - Reprice บิลจาก `global_rates` วันนี้ + recompute `is_paid` (ไม่มีคืนเงิน; ส่วนต่างบวกเข้า `outstanding_amount` — **เช็ค `surcharge_amount` ใน response เสมอ**)
  - ไม่เปลี่ยนสถานะ Booking หรือ BookingRoom
- **Response `200`:**
```json
{
  "status": "success",
  "message": "ย้ายชุดวันเข้าพักเรียบร้อยแล้วค่ะนายท่าน 📅✨ (2026-11-16–2026-11-20 → 2026-11-20–2026-11-24) คงห้อง 401 เดิม",
  "booking_id": "booking-uuid",
  "booking_room_id": "br-uuid",
  "room_number": "401",
  "old_check_in": "2026-11-16",
  "old_check_out": "2026-11-20",
  "check_in": "2026-11-20",
  "check_out": "2026-11-24",
  "nights": 4,
  "previous_total_amount": 4000,
  "total_amount": 4000,
  "surcharge_amount": 0,
  "paid_amount": 4000,
  "outstanding_amount": 0
}
```
> หมายเหตุ: `room_number` จะเป็น `null` หาก BR ยังไม่ได้จัดเลขห้อง

---

## 5. 🟢 ขยายสิทธิ์ Slip Collect ผ่าน `POST /api/v1/bookings/{bookingId}/confirm`

ขยาย endpoint ส่งสลิปให้รองรับการ **เก็บเงินหน้าเคาน์เตอร์ด้วยสลิป (Collect)** บนบิลที่ชำระแล้ว/ยืนยันแล้ว (เช่น เก็บงวดที่เหลือของมัดจำ, ส่วนต่าง `change-room` / `tier-change`, addon กลางพัก, `additional_charges`)

### ตารางสิทธิ์และสถานะที่รับ
| Role | `draft` / `verify_error` | `paid` / `confirmed` (Collect) | `pending` / `complete` |
|---|---|---|---|
| **เจ้าของบิลทั่วไป** | ✅ ส่งได้ (บิล `deferred` / `full` / `deposit` → เป็น `pending` รอ verify) | ❌ `422` | ❌ `422` |
| **`staff`** | ✅ เฉพาะบิลที่ตัวเองเป็นเจ้าของ (บิลคนอื่น → **`403`**) | ✅ **ส่งได้ทุก `payment_type` (Instant-Verify)** | ❌ `422` |
| **`admin`** | ✅ ส่งได้ทุกบิล (`deferred` = **Instant-Verify** · `full`/`deposit` = `pending` รอ verify) | ✅ **ส่งได้ทุก `payment_type` (Instant-Verify)** | ❌ `422` |

### พฤติกรรม Instant-Verify สำหรับ Collect (`paid` / `confirmed`)
- `confirmation_status` เป็น `"verified"` และ `booking_status` เป็น `"confirmed"` ทันที (บิล `paid` → `confirmed`, บิล `confirmed` → คงเดิม)
- **Ledger (`payment_recorded`):**
  - ถ้าส่ง `amount` (optional integer `>= 1`) → บันทึก Payment ตามยอดนั้นทันที
  - ถ้า **ไม่ส่ง `amount`**: บิล `full` ที่ยังมี `outstanding_amount > 0` ระบบจะบันทึกยอดคงค้างให้อัตโนมัติ · ส่วน `deposit` / `deferred` จะไม่สร้าง Payment row (`payment_recorded: null`)
- `booking_is_paid` คำนวณใหม่ทันทีตาม `paid_amount >= total_amount + charges_total`

### Response `201` (ฟิลด์ที่เพิ่ม/อัปเดต)
```json
{
  "status": "success",
  "message": "...",
  "confirmation_id": "uuid",
  "confirmation_status": "verified",
  "amount": 500,
  "expected_amount": 0,
  "booking_status": "confirmed",
  "booking_is_paid": true,
  "payment_recorded": { "...payment row or null..." },
  "payment_type": "full",
  "deposit_amount": 0,
  "paid_amount": 1500,
  "outstanding_amount": 0,
  "slip_image_url": "https://.../api/v1/images/<uuid>/file?..."
}
```
> การเก็บเงินสดหน้าเคาน์เตอร์ยังคงใช้ `POST /api/v1/front-desk/{bookingId}/payment` ได้ตามเดิม

---

## 6. 🟢 Bugfixes & Behavior ที่ FE ไม่ต้องแก้โค้ด (FYI)

- **แก้เฉพาะโน้ต/ข้อมูลประกอบไม่ทำให้ราคาบิลเปลี่ยน:** การเรียก `PUT /bookings/{id}/rooms/{brId}` หรือ `PUT /bookings/{id}/rooms` (batch) เพื่อแก้เฉพาะ `billing_comment`, `billing_address`, `guests`, หรือ `bed_preference` (โดยไม่แตะประเภทห้อง/วันที่/ราคา/addons) จะ **ข้ามการ reprice** แล้ว — ยอดรวมของบิลจะไม่ถูกคำนวณใหม่ตามเรทปัจจุบัน
- **Auto-confirm ห้องลูก (`booking_rooms`):** เมื่อ booking เข้าสู่สถานะ `paid` หรือ `confirmed` ห้องลูกที่ยังค้างสถานะ `draft` จะถูกปรับเป็น `confirmed` ให้อัตโนมัติ (รวมถึง flow check-in / mark-no-show จากบิล `paid`)
- **บิล `complete` คืน slot ห้อง:** ห้องที่อยู่ใน booking สถานะ `complete` จะไม่ถูกนับว่าถือ slot ค้างในระบบแล้ว
