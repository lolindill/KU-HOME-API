# 📢 API Patch — 2026-11-11 · Unify booking update route (`PUT /bookings/{id}/status`)

> **Branch / Ticket:** `feature/unify-booking-update-route` (wayfinder/api-route-hygiene ticket 03) · **ผู้กระทบ:** Frontend หน้า `admin-confirm-bookings`
> **อ่านคู่:** [`api_guide.md`](../api_guide.md) (contract เต็มฉบับปัจจุบัน) · มติทั้งหมดบันทึกใน `cline.md` หัวข้อ "📅 Unify booking update route (ticket 03)"

---

## TL;DR

1. **เปลี่ยน URL ปรับสถานะการจองด้วยมือ (Admin):** ย้ายจาก `PUT /bookings/update/{id}` (verb-in-path) เป็น **`PUT /bookings/{id}/status`** (REST subresource shape)
2. **ถอดเส้นเดิมทิ้งทันที:** เส้น `PUT /bookings/update/{id}` ถูกถอดออก (ไม่ทิ้ง tombstone — ยิงเส้นเดิมจะได้ `404 Not Found`)
3. **เพิ่ม where-uuid constraint:** พารามิเตอร์ `{id}` บังคับรูปแบบ UUID `[0-9a-f\-]{36}` — ID ที่ไม่ใช่ UUID จะถูก reject ที่ระดับ Router (404) ทันที
4. **🪶 Rider (zero-URL-change):** เพิ่ม where-uuid constraint ให้กับเส้น `PUT /bookings/{bookingId}/assign-rooms` ด้วยเช่นกัน

---

## 🔴 Breaking changes

### 1. `PUT /bookings/update/{id}` → `PUT /bookings/{id}/status`

| รายการ | เดิม (`PUT /bookings/update/{id}`) | ใหม่ (`PUT /bookings/{id}/status`) |
|---|---|---|
| **Path & Method** | `PUT /bookings/update/{id}` | `PUT /bookings/{id}/status` |
| **Path constraint** | ไม่มี (รับสตริงใดก็ได้แล้วหลุดเข้า controller) | บังคับ UUID `[0-9a-f\-]{36}` ที่ router level |
| **Throttle** | ไม่มี | ไม่มี (คงเดิมตามกฎ hygiene out-of-scope) |
| **Request Body** | `{ "status": "draft"\|"paid"\|"confirmed"\|"complete" }` | เหมือนเดิมทุกประการ |
| **Response Body (200)** | `{ "status": "success", "booking_id": ..., "booking_status": ..., ... }` | เหมือนเดิมทุกประการ |
| **เส้นเดิม** | ใช้งานได้ | ❌ **ถูกถอดออกทันที — เรียกจะได้ 404** |

### 2. 🪶 Rider: `PUT /bookings/{bookingId}/assign-rooms`

- **URL:** คงเดิม (`PUT /bookings/{bookingId}/assign-rooms`)
- **การเปลี่ยนแปลง:** เพิ่ม where-uuid constraint (`[0-9a-f\-]{36}`) ให้กับ `{bookingId}`
- **ผลกระทบ:** กรณีส่ง `bookingId` ที่ไม่ใช่ UUID จะถูก reject ที่ระดับ Router (HTTP 404) ก่อนเข้า controller

---

## 📋 Error Matrix

### `PUT /bookings/{id}/status`

| Case | HTTP | Response Body / Behavior | หมายเหตุ |
|---|---|---|---|
| **Non-UUID ID** | `404` | Standard Laravel router 404 (เช่น `{"message": "The route api/v1/bookings/... could not be found."}` หรือ HTML ขึ้นกับ middleware) | ⚠️ **เปลี่ยนจากเดิม:** เดิมหลุดเข้า controller แล้วได้ JSON 404 "ไม่พบข้อมูล Booking ที่ระบุ..." |
| **UUID ไม่พบในระบบ** | `404` | `{"status":"error","message":"ไม่พบข้อมูล Booking ที่ระบุในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ"}` | Controller-level 404 (`ModelNotFoundException`) |
| **ไม่ได้ส่ง Token / Token ผิด** | `401` | `{"message":"Unauthenticated."}` | Sanctum middleware |
| **Role ไม่ใช่ admin** | `403` | `{"status":"error","message":"ขออภัยค่ะนายท่าน เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถเข้าถึงส่วนนี้ได้ 🥺"}` | `CheckRole` middleware (`role:admin`) |
| **ไม่ได้ส่ง status / status ไม่อยู่ใน allowlist** | `422` | Validation error (Laravel standard `errors` object) | Allowlist: `draft,paid,confirmed,complete` |
| **Transition ไม่อนุญาตตาม state machine** | `422` | `{"status":"error","message":"ไม่สามารถเปลี่ยนสถานะจาก {from} เป็น {to} ได้ค่ะ..."}` | Domain rule จาก `Booking::transitionStatus()` |

### `PUT /bookings/{bookingId}/assign-rooms` (Rider)

| Case | HTTP | Response Body / Behavior | หมายเหตุ |
|---|---|---|---|
| **Non-UUID bookingId** | `404` | Standard Laravel router 404 | ⚠️ **พฤติกรรมใหม่:** เดิมหลุดเข้า controller แล้วได้ JSON 404 "ไม่พบข้อมูลการจองนี้ในระบบ..." |
| **UUID ไม่พบในระบบ** | `404` | `{"status":"error","message":"ไม่พบข้อมูลการจองนี้ในระบบค่ะนายท่าน โปรดตรวจสอบ ID อีกครั้งนะคะ"}` | คงเดิม |
| **ไม่ใช่ admin หรือ staff** | `403` | `{"status":"error","message":"..."}` | คงเดิม (`role:admin,staff`) |

---

## 🔄 Migration guide สำหรับ Frontend

### Caller เดียวที่ได้รับผลกระทบ: หน้า `admin-confirm-bookings`

> ⚠️ **Limitation Note:**
> Frontend repository (`ku-home`) ไม่ได้อยู่บนเครื่องสำหรับ task นี้ จึงไม่สามารถ grep เพื่อยืนยันซ้ำแบบสดได้ ข้อมูลนี้อ้างอิงจาก Reverse Index Snapshot (2026-11-06 ใน `api_pages README`) ซึ่งยืนยันว่ามี caller เดียวที่เรียก endpoint นี้คือหน้า **`admin-confirm-bookings`**

### การแก้ไขที่ฝั่ง Frontend:

1. **เปลี่ยน URL การเรียก API:**
   ```typescript
   // ❌ ก่อนหน้า (URL เดิม):
   const res = await fetch(`/api/v1/bookings/update/${bookingId}`, {
     method: 'PUT',
     headers: {
       'Content-Type': 'application/json',
       'Accept': 'application/json',
       'Authorization': `Bearer ${token}`
     },
     body: JSON.stringify({ status: newStatus })
   });

   // ✅ แก้ไขเป็น (URL ใหม่):
   const res = await fetch(`/api/v1/bookings/${bookingId}/status`, {
     method: 'PUT',
     headers: {
       'Content-Type': 'application/json',
       'Accept': 'application/json',
       'Authorization': `Bearer ${token}`
     },
     body: JSON.stringify({ status: newStatus })
   });
   ```

2. **Payload & Response Handling:**
   - Request body ยังคงใช้ `{ status: ... }` เหมือนเดิม
   - Response status 200 และ shape ของข้อมูลส่งกลับมาเหมือนเดิมทุกประการ
   - หากมีการตรวจสอบ error response สำหรับกรณี invalid ID: ให้ตระหนักว่า ID ที่ไม่ใช่ UUID จะได้ HTTP 404 จาก router ทันที
