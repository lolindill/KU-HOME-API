# 📢 API Patch Notes (Frontend) — 🏛️ Booking `owner` envelope (2026-11-18)

> **สำหรับ:** ทีม Frontend (`ku-home`)
> **ประเด็น:** ⚠️ **BREAKING** — identity ของบิล (user / organization / ผู้ติดต่อเฮดเปล่า) ย้ายจาก flat fields เข้า **`owner` object** หนึ่งเดียว พร้อมชื่อองค์กรในตัว

---

## ⚡ TL;DR — FE ต้องทำอะไร

| # | หัวข้อ | ผลกระทบ / สิ่งที่ FE ต้องทำ |
|---|---|---|
| 1 | 🔴 **flat fields หายจาก response** | `organization_id`, `customer_name`, `customer_phone`, `customer_email` ถูกซ่อนออกจาก serialization ของ booking ทุก endpoint · ใน envelope ของ `POST /bookings` 201 และ `PUT /bookings/{id}` 200 ตัด `user_id` echo ออกด้วย |
| 2 | 🆕 **อ่าน identity ผ่าน `owner`** | `booking.owner` / envelope `owner` — object มี `type` discriminator หรือ `null` (ดูตารางด้านล่าง) · มีทุก endpoint ที่ serialize Booking |
| 3 | 🏛️ **ชื่อองค์กรได้ในตัว** | `owner.name` + `owner.erp` (live จาก organizations table) อยู่คู่ snapshot `customer_*` แล้ว — ไม่ต้องรอ object `organization` (admin-only) ก็แสดงชื่อหน่วยงานได้ |
| 4 | ✅ **Request side ไม่เปลี่ยน** | `POST /bookings` ยังรับ `organize` + `customer_*` · `PUT /bookings/{id}` ยังรับ `user`/`organize`/`detach_identity` ตามเดิม (detach guard 18/11/26 ไม่เปลี่ยน) |

---

## 🏛️ `owner` shape — key set เดียวกันทุก type

| `owner.type` | ความหมาย | `id` | `name` | `erp` | `customer_*` |
|---|---|---|---|---|---|
| `organization` | โหมด C จองให้องค์กร | organization_id | ชื่อองค์กร (live) | erp (live) | snapshot ผู้ติดต่อ |
| `user` | จองเอง / โหมด A จองแทน / walk-in (user_id = staff) | user_id | ชื่อ user (live) | `null` | snapshot (มีได้เมื่อ attach ระบุ) |
| `contact` | โหมด B เฮดเปล่าระบุผู้ติดต่อ | `null` | `null` | `null` | snapshot ผู้ติดต่อ |
| `owner = null` | draft ลอย ไม่ระบุเจ้าของ/ผู้ติดต่อ (หรือหลัง `detach_identity`) | — | — | — | — |

```json
// บิลองค์กร (โหมด C)
"owner": {
  "type": "organization",
  "id": "3f9c1a2e-8b47-4c6d-9e21-7a55f0d1c309",
  "name": "คณะเกษตร มหาวิทยาลัยเกษตรศาสตร์",
  "erp": "KU-AGR-001",
  "customer_name": "ดร.วิภา ราชกรี",
  "customer_phone": null,
  "customer_email": "wipa@ku.ac.th"
}

// บิล user
"owner": { "type": "user", "id": "user-uuid", "name": "Somchai Jaidee", "erp": null, "customer_name": null, "customer_phone": null, "customer_email": null }

// เฮดเปล่าระบุผู้ติดต่อ (โหมด B)
"owner": { "type": "contact", "id": null, "name": null, "erp": null, "customer_name": "คุณเฮดเปล่า", "customer_phone": null, "customer_email": null }

// หลัง detach_identity
"owner": null
```

---

## 📍 ผลกระทบราย endpoint

| Endpoint | เปลี่ยนแปลง |
|---|---|
| `POST /bookings` 201 | ตัด echo `user_id` + `organization_id` + `customer_*` → เพิ่ม `owner` |
| `GET /bookings` | แต่ละบิล: flat 4 field หาย → `owner` เพิ่ม (`user_id` FK column ยังอยู่ · `search_criteria.organization_id` echo คงเดิม) |
| `GET /bookings/{id}` | เหมือน list — `organization` object (admin) ยัง eager-load ตามเดิม |
| `PUT /bookings/{id}` 200 | ตัด echo 5 field → เพิ่ม `owner` (ดูผล attach/switch/detach จาก `owner.type` + `owner.id`) |
| discount-code / assign-rooms | flat หาย + `owner` เพิ่ม อัตโนมัติผ่าน model serialization |

---

*Backend ref: `Booking::getOwnerAttribute()` ($appends + $hidden) · `BookingController::createBooking`/`getBookings` · `BookingManagementController::updateBookingPayment` · tests: `OrgBookingTest` + `BookingAttachCustomerTest` + `AdminBookingForUserTest` · จดใน `cline.md` หัวข้อ "🏛️ Owner envelope (2026-11-18)"*
