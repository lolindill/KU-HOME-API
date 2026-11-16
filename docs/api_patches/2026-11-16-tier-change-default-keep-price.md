# 📢 API Patch — 2026-11-16 · Tier-change default keep-price + `apply_new_rate` toggle

> **สถานะ:** behavior change (default ราคาหลังเปลี่ยนประเภทห้อง/ย้ายห้อง) · **ผู้กระทบ:** frontend (`admin` / `staff` console) ที่เรียก `PUT /bookings/{id}/rooms/{brId}` (โหมดหลังจ่ายเงิน) หรือ `POST /front-desk/{id}/rooms/{brId}/change-room`
> **อ่านคู่:** [`api_guide.md`](../api_guide.md) · [`2026-11-11-tier-endpoints-v2.md`](./2026-11-11-tier-endpoints-v2.md)

---

## TL;DR

1. **Default ใหม่ = คงราคาห้องเดิม (`apply_new_rate = false`):** เมื่อ `admin` หรือ `staff` เปลี่ยนประเภทห้องหรือย้ายห้องในบิลที่ **ไม่ใช่ `draft`** (`paid` / `confirmed`) โดยไม่ส่ง `apply_new_rate` หรือ `price_per_night` — ระบบจะ **คงราคาห้องเดิมทั้งขาขึ้น (upgrade) และขาลง (downgrade)** (`surcharge_amount = 0`) เพื่อป้องกันการบวกเงินเพิ่มโดยอัตโนมัติ (เช่น อัปเกรดฟรีหน้าเคาน์เตอร์)
2. **เปิดคิดเรทใหม่เมื่อต้องการ:** ส่ง `"apply_new_rate": true` เพื่อให้ระบบคำนวณค่าห้องตามเรทของประเภทห้องใหม่จาก `global_rates` (ทั้ง upgrade และ downgrade)
3. **Manual rate ชนะเสมอ:** ถ้าส่ง `"price_per_night"` (integer บาท) ระบบจะใช้ราคาที่กรอกเป็นหลักเสมอ (ชนะทั้ง `apply_new_rate: false` และ `true`)
4. **สิ่งที่ไม่เปลี่ยน:**
   - บิล **`draft`** (`PUT` โหมด 1) ยังคิดราคาตามเรทห้องใหม่จาก `global_rates` อัตโนมัติเหมือนเดิมทุกประการ
   - โหมด **`checked_in`** (`PUT` โหมด 3 addon-only) ยังรับเฉพาะ `addons` / `extra_beds` — ส่ง `apply_new_rate` มาจะได้ `422`
   - การแก้ `addons` หรือเปลี่ยนจำนวนคืน (`check_in`/`check_out` จำนวนคืนเปลี่ยน) ใน `PUT` โหมด 2 ยังคำนวณส่วนต่างตามปกติ

---

## 🎚️ ตารางพฤติกรรม (เฉพาะบิล `paid | confirmed` โดย `admin + staff`)

| Endpoint | เงื่อนไข Input | ผลลัพธ์ค่าห้อง (`room_amount`) | Addons / เปลี่ยนจำนวนคืน |
|---|---|---|---|
| `PUT /bookings/{id}/rooms/{brId}` (โหมด 2, `room_id = null`) | ไม่ส่ง `apply_new_rate` หรือ `false` (และจำนวนคืนเท่าเดิม) | **คง `room_amount` เดิม** (รวม BR อื่นในบิล — `surcharge_amount = 0` สำหรับค่าห้อง) | แก้ `addons` หรือเปลี่ยนจำนวนคืน ยังคิดส่วนต่างปกติ |
| `PUT /bookings/{id}/rooms/{brId}` (โหมด 2) | `apply_new_rate: true` | คำนวณตามเรทประเภทห้องใหม่จาก `global_rates` | คิดปกติ |
| `PUT /bookings/{id}/rooms/{brId}` (โหมด 2) | ส่ง `price_per_night: N` | ใช้ `N × nights` เสมอ (ชนะ `apply_new_rate`) | คิดปกติ |
| `POST /front-desk/{id}/rooms/{brId}/change-room` | ไม่ส่ง `apply_new_rate` หรือ `false` | **คง `room_amount` เดิมทุกห้องในบิล** (`surcharge_amount = 0`) | — |
| `POST /front-desk/{id}/rooms/{brId}/change-room` | `apply_new_rate: true` | คำนวณตามเรทประเภทห้องใหม่จาก `global_rates` | — |
| `POST /front-desk/{id}/rooms/{brId}/change-room` | ส่ง `price_per_night: N` | ใช้ `N × nights` เสมอ (ชนะ `apply_new_rate`) | — |

---

## ตัวอย่าง Request Body

**อัปเกรด/ย้ายห้องโดยไม่คิดเงินเพิ่ม (Default — ไม่ต้องส่ง `apply_new_rate` หรือส่ง `false`):**
```json
// POST /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/change-room
{
  "room_id": "deluxe-room-uuid",
  "notes": "Complimentary upgrade"
}
// → 200: total_amount คงเดิม · surcharge_amount = 0 · outstanding_amount คงเดิม
```

**อัปเกรด/เปลี่ยน tier และคิดเรทห้องใหม่ตามระบบ:**
```json
// PUT /api/v1/bookings/{bookingId}/rooms/{bookingRoomId}
{
  "room_type_id": "deluxe-room-type-uuid",
  "apply_new_rate": true
}
// → 200: คำนวณตามเรท Deluxe ใน global_rates · ส่วนต่างเข้า outstanding_amount
```

**กำหนดราคาต่อคืนเอง:**
```json
{
  "room_id": "deluxe-room-uuid",
  "price_per_night": 1800
}
// → 200: คิด 1800 × nights เสมอ (ไม่ว่าจะส่ง apply_new_rate เป็น true หรือ false)
```
