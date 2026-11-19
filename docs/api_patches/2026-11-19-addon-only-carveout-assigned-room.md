# 🍳 FE Patch Notes 2026-11-19 — PUT booking room: addon-only บนห้อง assign แล้ว (ปลดล็อก)

> **Non-breaking (ปลดล็อกเคสที่เคย 422)** — `PUT /bookings/{bookingId}/rooms/{bookingRoomId}`
> Backend: addon-only carve-out ใน guard โหมด change-tier (tier-endpoints-v2 มติ v2 ข้อ 3 คงเดิมสำหรับ shape/tier fields)

## สรุปเปลี่ยนแปลง

เดิม: บิล `paid|confirmed` + BR `confirmed` **ที่มีเลขห้องแล้ว** (`room_id ≠ null`) ก่อนเช็คอิน —
ยิง PUT นี้ด้วย payload อะไรก็ได้ → `422` "ห้องนี้มีเลขห้องจัดไว้แล้ว … กรุณาใช้ change-room 🚪"
ทั้งที่ `change-room` ก็ไม่รับ `addons` → **แก้ addon (early check-in / late check-out / breakfast /
เตียงเสริม) บนห้อง assign แล้วไม่ได้ทุกทาง**

ใหม่: payload ที่เป็น **`addons`/`extra_beds` ล้วน** ผ่าน guard ได้ — server คิด surcharge เฉพาะ
ส่วน addon (คงราคาห้องเดิมทั้งบิล) ไหลเข้า `outstanding_amount` เก็บต่อผ่าน
`POST /front-desk/{id}/payment` ตามกลไกเดิม

## เงื่อนไข (ตรวจจาก validated keys ⊆ `['addons', 'extra_beds']`)

| Payload | ผลลัพธ์ |
|---|---|
| `{"addons": {"early_hours": 2}}` | ✅ **200** — surcharge = ส่วน addon |
| `{"addons": {...}, "extra_beds": 1}` | ✅ **200** — (legacy `extra_beds` หัวห้องนับรวม) |
| ปน `room_type_id` / `check_in` / `check_out` / `guests` / `bed_preference` / `billing_*` / `price_per_night` / `apply_new_rate` มาด้วย | ❌ **422** ชี้ `change-room` เหมือนเดิม (ข้อความเพิ่ม "(รับเฉพาะ addon เท่านั้น)") |
| แถม `room_id` ใน request | ❌ **422** เหมือนเดิม (จัดเลขห้องผ่าน assign-rooms/change-room เท่านั้น) |

คงเดิมทุกอย่างอื่น: **admin/staff เท่านั้น** (owner = `403` — เหมือนโหมด change-tier และโหมด
checked_in) · cap เตียงรายคืน ≤ `max_extra_beds` ของ tier ปัจจุบัน (`422` ถ้าเกิน) · `is_paid`
recompute ตาม `paid ≥ total + charges` (surcharge ทำให้ paid ไม่ครอบ → `false` ได้)

## ข้อความ 422 เปลี่ยนเล็กน้อย (ไม่ breaking — key คือ `change-room` ยังอยู่)

- เดิม: `ห้องนี้มีเลขห้องจัดไว้แล้ว ไม่สามารถแก้ไขประเภทห้องผ่านหน้านี้ได้ กรุณาใช้ change-room ค่ะ 🚪`
- ใหม่: `ห้องนี้มีเลขห้องจัดไว้แล้ว ไม่สามารถแก้ไขประเภทห้องผ่านหน้านี้ได้ (รับเฉพาะ addon เท่านั้น) กรุณาใช้ change-room ค่ะ 🚪`

## Audit

`status_change_logs` (entity_type `booking_room`) สำหรับ addon-only ติดป้าย
`addon-only (surcharge) · price X → X บาท` แทน `change-tier (same-tier) · …` — FE ที่ parse
note ต้องรองรับทั้งสอง prefix

## Response envelope — ไม่เปลี่ยน

ยังครบ `total_amount` / `previous_total_amount` / `surcharge_amount` / `payment_type` /
`deposit_amount` / `paid_amount` / `outstanding_amount` / `booking_room` (พร้อม `addon`)
