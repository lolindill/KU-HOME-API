# API Documentation Handoff — Ticket 03 (Front Desk Staff Role / REQ-031)

เอกสารส่งมอบสำหรับผู้จัดการเพื่อนำไปผนวกเข้ากับ `docs/api_guide.md` หลังปิด Ticket 03

---

## สรุปการเปลี่ยนแปลงสิทธิ์ (Permissions Changes)

ตามข้อกำหนด SRS REQ-031 (เปิดให้แผนกต้อนรับ/รปภ. ให้บริการเช็คอิน-เช็คเอาต์ได้ตลอด 24 ชั่วโมง โดยใช้บัญชี role `staff`) ได้มีการปรับเปลี่ยนสิทธิ์การเข้าถึง endpoint ที่เกี่ยวข้องดังนี้:

| Endpoint | Method | สิทธิ์เดิม (Before) | สิทธิ์ใหม่ (After) | เหตุผล |
|---|---|---|---|---|
| `/bookings/{bookingId}/assign-rooms` | `PUT` | `admin` (or owner) | `admin`, `staff` (or owner) | เพื่อให้เจ้าหน้าที่หน้าฟร้อนสามารถจัดสรรห้องอัตโนมัติตาม cluster allocator ได้ทันทีเมื่อผู้เข้าพักมาถึง |
| `/front-desk/{bookingId}/check-in` | `POST` | `admin` | `admin`, `staff` | REQ-031 รับเช็คอินผู้เข้าพักได้ตลอด 24 ชม. |
| `/front-desk/{bookingId}/check-out` | `POST` | `admin` | `admin`, `staff` | REQ-031 รับเช็คเอาต์ผู้เข้าพักและ trigger งานแม่บ้าน (housekeeping task) อัตโนมัติ |
| `/front-desk/{bookingId}/payment` | `POST` | `admin` | `admin`, `staff` | จำเป็นอย่างยิ่งในการเคลียร์ยอดค้างชำระหน้าเคาน์เตอร์ก่อนเช็คอิน หรือค่าใช้จ่ายเพิ่มเติมก่อนเช็คเอาต์ |
| `/front-desk/walk-in` | `POST` | `admin` | `admin` (คงเดิม) | การเปิดห้อง walk-in นอกระบบยังคงสงวนไว้สำหรับระดับบริหาร/Admin |
| `/front-desk/{bookingId}/mark-no-show` | `POST` | `admin` | `admin` (คงเดิม) | การตัด no-show และริบมัดจำยังคงเป็นอำนาจของ Admin |

---

## ส่วนที่ต้องอัปเดตใน `docs/api_guide.md`

### 1. หมวด `PUT /bookings/{bookingId}/assign-rooms`

**แก้ไขหัวข้อและป้ายกำกับสิทธิ์:**
```markdown
### PUT `/bookings/{bookingId}/assign-rooms` — Auto-assign rooms (Admin / Staff)

🔒 **Admin, Staff** (or booking owner)
```

### 2. หมวด Front Desk Endpoints

**แก้ไขคำนำหน้าหมวดหมู่:**
```markdown
> Endpoints in this section are restricted to **Admin** and **Staff** (front-desk operations), except `/walk-in` and `/mark-no-show` which remain strictly **Admin only**.
```

**แก้ไขป้ายกำกับสิทธิ์ของแต่ละ Endpoint:**

#### `POST /front-desk/{bookingId}/check-in`
```markdown
### POST `/front-desk/{bookingId}/check-in` — Check-in guest

🔒 **Admin, Staff**
```

#### `POST /front-desk/{bookingId}/check-out`
```markdown
### POST `/front-desk/{bookingId}/check-out` — Check-out guest

🔒 **Admin, Staff**
```

#### `POST /front-desk/{bookingId}/payment`
```markdown
### POST `/front-desk/{bookingId}/payment` — Record payment

🔒 **Admin, Staff**
```

*(หมายเหตุ: `POST /front-desk/walk-in` และ `POST /front-desk/{bookingId}/mark-no-show` ยังคงระบุ `🔒 Admin only` ตามเดิม)*
