# API Documentation Handoff — Ticket 05 (REQ-033: Check-in Evidence Upload)

> เอกสารส่วนต่อขยายสำหรับนำไปผสานเข้าสู่ `docs/api_guide.md` (เขียนแยกตามกติกาห้ามแก้ไข `docs/api_guide.md` โดยตรงใน ticket worktree)

---

## 🛎️ Front Desk Check-in Evidence Operations (REQ-033)

ระบบจัดเก็บและจัดการไฟล์หลักฐานการเข้าพักตอน check-in เช่น บัตรประชาชน (ID Card / Passport), ใบลงทะเบียนเข้าพัก (Registration Form), Guest Register (กระดาษ/ดิจิทัล), เอกสารยินยอม PDPA และเอกสารอื่นๆ โดยใช้โครงสร้าง `images` table ร่วมกับ private disk (`local` = `storage/app/private/checkin_evidences/`) และเข้าถึงไฟล์ผ่าน Signed URL อายุ 15 นาทีตามมาตรฐานความปลอดภัยของ KU HOME API

- **Model ที่ผูก:** `BookingRoom` (Polymorphic `imageable_*` ผ่าน relation `evidences()`)
- **สิทธิ์การใช้งาน (RBAC):** `admin` และ `staff` เท่านั้น (user / housekeeping ได้ 403 Forbidden)
- **สถานะห้องพักที่อนุญาต (State Guard):** `confirmed`, `checked_in`, `checked_out` (หากเป็น `draft` หรือ `no_show` จะตอบกลับ `422 Unprocessable Entity`)
- **ระยะเวลาจัดเก็บ (Retention):** 365 วัน (1 ปี) ตัดตามอายุตรง ๆ ผ่านคำสั่ง `php artisan app:cleanup-images` (รันอัตโนมัติรอบ 02:30 ทุกวัน)

---

### 1. Upload Check-in Evidence

อัปโหลดไฟล์หลักฐานการเข้าพักสำหรับห้องพักที่ระบุ (`booking_rooms.id`)

- **Method:** `POST`
- **URL:** `/api/v1/front-desk/booking-rooms/{id}/evidence`
- **Headers:**
  - `Authorization: Bearer <TOKEN>`
  - `Content-Type: multipart/form-data`
  - `Accept: application/json`
- **Roles:** `admin`, `staff`
- **Path Parameters:**
  - `id` *(string, required)*: UUID ของ `booking_rooms`
- **Body (Multipart Form-Data):**
  - `file` *(file, required)*: ไฟล์ภาพหรือเอกสาร (mimes: `jpeg, png, jpg, webp, pdf`, ขนาดสูงสุด 10MB = 10,240 KB)
  - `type` *(string, required)*: ประเภทของหลักฐาน ค่าที่รองรับได้แก่:
    - `id_card`: บัตรประจำตัวประชาชน หรือ Passport
    - `registration`: ใบลงทะเบียนการเข้าพัก (Registration Form)
    - `guest_register`: Guest Register
    - `pdpa`: เอกสารยินยอม PDPA
    - `other`: เอกสารอื่นๆ
  - `note` *(string, optional)*: หมายเหตุเพิ่มเติม (ความยาวไม่เกิน 1,000 ตัวอักษร)

#### Success Response (201 Created)

```json
{
  "status": "success",
  "message": "อัปโหลดหลักฐานการเข้าพักเรียบร้อยแล้วค่ะนายท่าน ✨",
  "data": {
    "id": "01a1386c-ec31-7203-8e78-dcf3bbd84a0d",
    "booking_room_id": "01a1386c-eb99-7032-9cb1-8de20df98a12",
    "type": "id_card",
    "metadata": {
      "type": "id_card",
      "note": "บัตรประชาชนนายสมชาย"
    },
    "file_name": "id_card.jpg",
    "mime_type": "image/jpeg",
    "size": 102400,
    "url": "https://ku-home.ku.ac.th/backend/api/v1/images/01a1386c-ec31-7203-8e78-dcf3bbd84a0d/file?expires=1770956400&signature=abcdef123456...",
    "created_at": "2026-10-14T03:20:00.000000Z"
  }
}
```

#### Error Responses

- **401 Unauthorized:** ไม่ได้ส่ง Token หรือ Token หมดอายุ
- **403 Forbidden:** ผู้ใช้ไม่ใช่ role `admin` หรือ `staff`
- **404 Not Found:** ไม่พบ `booking_room` ตาม UUID ที่ส่งมา
- **422 Unprocessable Entity:** 
  - ส่งประเภทหลักฐานผิด / ไม่ได้แนบไฟล์ / ไฟล์เกิน 10MB / นามสกุลไฟล์ไม่รองรับ
  - สถานะห้องไม่อยู่ในกลุ่มที่อนุญาต (เช่น ยังเป็น `draft` หรือถูกมาร์ก `no_show`)

---

### 2. Get Check-in Evidences

ดึงรายการหลักฐานทั้งหมดของห้องพักที่ระบุ พร้อม Signed URL สดใหม่ (อายุ 15 นาที) สำหรับเปิดดูไฟล์

- **Method:** `GET`
- **URL:** `/api/v1/front-desk/booking-rooms/{id}/evidence`
- **Headers:**
  - `Authorization: Bearer <TOKEN>`
  - `Accept: application/json`
- **Roles:** `admin`, `staff`
- **Path Parameters:**
  - `id` *(string, required)*: UUID ของ `booking_rooms`

#### Success Response (200 OK)

```json
{
  "status": "success",
  "booking_room_id": "01a1386c-eb99-7032-9cb1-8de20df98a12",
  "evidences": [
    {
      "id": "01a1386c-ec31-7203-8e78-dcf3bbd84a0d",
      "type": "id_card",
      "metadata": {
        "type": "id_card",
        "note": "บัตรประชาชนนายสมชาย"
      },
      "file_name": "id_card.jpg",
      "mime_type": "image/jpeg",
      "size": 102400,
      "url": "https://ku-home.ku.ac.th/backend/api/v1/images/01a1386c-ec31-7203-8e78-dcf3bbd84a0d/file?expires=1770956400&signature=abcdef123456...",
      "created_at": "2026-10-14T03:20:00.000000Z"
    }
  ]
}
```

---

### 3. File Streaming Route

- **Method:** `GET`
- **URL:** `/api/v1/images/{image}/file?expires=...&signature=...`
- **Auth:** ไม่ต้องส่ง Sanctum Token (ใช้ Laravel Temporary Signed URL ยืนยันสิทธิ์ มีอายุ 15 นาที)
- **Exempt:** ยกเว้น `RequireJsonAccept` เพื่อให้บราวเซอร์ใช้ `<img src="...">` หรือดาวน์โหลดไฟล์ตรงได้ทันที
