# API Doc Handoff — Ticket 02: Guest Cap Validation (REQ-007, REQ-019)

เอกสารส่งมอบสำหรับผู้จัดการเพื่อนำไปผนวกเข้าใน `docs/api_guide.md`

---

## สรุปภาพรวมการเปลี่ยนแปลง (Summary of Changes)

เพิ่มระบบตรวจสอบจำนวนผู้เข้าพัก (Guest Cap Validation) 2 ชั้น ตามข้อกำหนด SRS REQ-007 และ REQ-019:
1. **ชั้นที่ 1 (Cap Validation):** ตรวจสอบจำนวนผู้เข้าพักใน `guests` array ของแต่ละห้อง ต้องไม่เกิน `max_guests` ของประเภทห้องนั้นๆ
2. **ชั้นที่ 2 (Confirmation Guest Requirement):** ตรวจสอบก่อนส่งหลักฐานการชำระเงิน (Confirm) ว่าทุกห้องในรายการจองจะต้องมีผู้เข้าพักอย่างน้อย 1 คน (`guests >= 1`)

> **หมายเหตุสำคัญเกี่ยวกับการจอง:**
> - การสร้างรายการจองในขั้นตอน Draft (`POST /api/v1/bookings`) **ไม่บังคับ** ฟิลด์ `guests` เพื่อรองรับ Real-time Draft Creation Flow ผู้ใช้สามารถสร้างห้องจองก่อนแล้วค่อยกรอกรายชื่อผู้เข้าพักภายหลังได้
> - รองรับชื่อผู้เข้าพักซ้ำกันในห้องเดียวกันหรือข้ามห้องได้ตาม REQ-019 โดยไม่มีการ reject ด้วย unique validation

---

## Endpoints ที่ได้รับผลกระทบ

### 1. `POST /api/v1/bookings` (สร้างการจองใหม่)
- **พฤติกรรม:** หากมีการระบุ `guests` ในแต่ละ `booking_rooms` จำนวนสมาชิกใน `guests` ต้องไม่เกิน `room_types.max_guests`
- **Error Response (422 Unprocessable Content):**
  ```json
  {
    "status": "error",
    "message": "ผู้เข้าพักเกินจำนวนสูงสุด 2 คนของห้องประเภทนี้ค่ะ 🥺"
  }
  ```

### 2. `POST /api/v1/bookings/{id}/rooms` (เพิ่มห้องในรายการจอง)
- **พฤติกรรม:** หากมีการระบุ `guests` ในห้องที่เพิ่ม จำนวนสมาชิกใน `guests` ต้องไม่เกิน `room_types.max_guests` ของห้องนั้น
- **Error Response (422 Unprocessable Content):**
  ```json
  {
    "status": "error",
    "message": "ผู้เข้าพักเกินจำนวนสูงสุด 2 คนของห้องประเภทนี้ค่ะ 🥺"
  }
  ```

### 3. `PUT /api/v1/bookings/{id}/rooms/{roomId}` (แก้ไขห้องเดี่ยว)
- **พฤติกรรม:** 
  - หากระบุ `guests` ใหม่: จำนวนคนต้องไม่เกิน `max_guests` ของประเภทห้องที่มีผล (effective room type)
  - หากระบุเฉพาะ `room_type_id` ใหม่โดยไม่เปลี่ยน `guests`: จำนวน `guests` เดิมของห้องนั้นต้องไม่เกิน `max_guests` ของประเภทห้องใหม่
- **Error Response (422 Unprocessable Content):**
  ```json
  {
    "status": "error",
    "message": "ผู้เข้าพักเกินจำนวนสูงสุด 1 คนของห้องประเภทนี้ค่ะ 🥺"
  }
  ```

### 4. `PUT /api/v1/bookings/{id}/rooms` (แก้ไขห้องแบบกลุ่ม / Batch)
- **พฤติกรรม:** ตรวจสอบ Final State ของทุกห้องใน Batch หากมีห้องใดห้องหนึ่งมีจำนวน `effectiveGuests` เกิน `effectiveTypeId.max_guests` จะถูก reject ทันที
- **Error Response (422 Unprocessable Content):**
  ```json
  {
    "status": "error",
    "message": "ผู้เข้าพักเกินจำนวนสูงสุด 1 คนของห้องประเภทนี้ค่ะ 🥺"
  }
  ```

### 5. `POST /api/v1/bookings/{id}/confirm` (ส่งหลักฐานการชำระเงิน)
- **พฤติกรรม:** ตรวจสอบทุก `booking_rooms` ของ booking นั้น หากพบห้องใดที่ `guests` ว่างเปล่า (`empty`, `null` หรือ `count < 1`) จะถูกปฏิเสธทันที
- **Error Response (422 Unprocessable Content):**
  ```json
  {
    "status": "error",
    "message": "กรุณาระบุข้อมูลผู้เข้าพักอย่างน้อย 1 คนต่อห้องก่อนส่งหลักฐานการชำระเงินค่ะ 🥺"
  }
  ```

---

## Request Model / FormRequest

- `App\Http\Requests\StoreBookingRoomRequest`: เพิ่ม `withValidator()` ตรวจสอบกรณี `count(guests) > roomType->max_guests` ส่งคืน validation error key `guests`
