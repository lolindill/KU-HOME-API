# 📢 API Patch Notes (Frontend) — 2026-11-27 (`dev` @ `ade6602`)

> **สำหรับ:** ทีม Frontend (`ku-home`)
> **ขอบเขต:** สรุปเฉพาะสิ่งที่ Frontend ต้องรู้/ต้องปรับ ต่อจาก digest รอบก่อน ([`2026-11-18-fe-patch-notes.md`](./2026-11-18-fe-patch-notes.md) จบที่ `1dac03f`) — commits `1dac03f` → `769e12a`
> **เอกสารละเอียดประกอบ:** [`2026-11-27-user-status.md`](./2026-11-27-user-status.md) · [`2026-11-19-breakfast-toggle-guest-pricing.md`](./2026-11-19-breakfast-toggle-guest-pricing.md) · [`2026-11-19-addon-only-carveout-assigned-room.md`](./2026-11-19-addon-only-carveout-assigned-room.md) · [`2026-11-18-report-pdf-export.md`](./2026-11-18-report-pdf-export.md)

---

## ⚡ TL;DR — FE ต้องทำอะไรบ้าง

| # | หัวข้อ | ประเภท | ผลกระทบ / สิ่งที่ FE ต้องทำ |
|---|---|---|---|
| 1 | **User status `active`/`inactive` แทน `ver`** | 🔴 Breaking | `ver` หายจาก user object → ใช้ `status` · `PUT /users/{id}/verify` ถูกถอด (`404`) → ใช้ `PATCH /users/{id}/status` · login ของบัญชีที่ปิดได้ `403` |
| 2 | **Breakfast toggle + เรทรายหัว** | 🔴 Breaking (wire) | `addons` ส่ง `breakfast_enabled` (bool) + `breakfast_code` แทน `breakfast_sets` · response ถอด `breakfast_set_100/200` |
| 3 | **Addon-only บนห้องที่ assign เลขแล้ว** | 🟢 ปลดล็อก | `PUT /bookings/{id}/rooms/{brId}` ที่ส่ง `addons`/`extra_beds` ล้วน บนบิล `paid\|confirmed` + ห้องมีเลขแล้ว → ไม่โดน `422` อีก |
| 4 | **Report PDF export** | 🟢 Feature ใหม่ | `GET /reports/{slug}/export?format=pdf` (ไม่มี route ใหม่) · `format` อื่น → `422` |
| 5 | **Change-room ล่วงหน้าเข้าห้องที่ไม่ว่างวันนี้** | 🟢 ปลดล็อก | ย้ายห้องก่อนเช็คอิน (BR `confirmed`/`draft`) ไปห้องที่วันนี้ `occupied`/`checkout_makeup` ได้แล้ว ถ้าช่วงพักไม่ทับกัน |
| 6 | **Bugfixes / docs (ไม่ต้องแก้โค้ด FE)** | 🟢 FYI | รายงานอาหารเช้าไม่ 500 บน prod แล้ว · board สถานะห้องขยับตาม job รายวันแล้ว · เอกสาร `room_type_ids` ของ discount |

---

## 1. 🔴 User status `active` / `inactive` (แทน `ver`)

- **User object:** ไม่มี `ver` แล้ว → มี `status` = `"active"` (default ทุกคน) | `"inactive"` — ลบป้าย "verified" เดิมออกจาก UI
- **ใหม่ `PATCH /api/v1/users/{id}/status`** (admin) body `{"status": "active" | "inactive"}`
  - `inactive` = ลบ token ทุก device ของ user นั้นทันที
  - `422` เมื่อค่าไม่ถูกต้อง หรือ admin ปิดบัญชี**ตัวเอง** (ซ่อน/disable ปุ่มบนแถวตัวเองได้)
- **ถอด `PUT /users/{id}/verify`** → `404`
- **`GET /users?status=inactive`** — filter ใหม่ (ใช้คู่ `auth_provider` ได้)
- **Login ของบัญชีที่ปิด:** `/login`, `/auth/sso/exchange`, `/auth/sso/google/exchange` → `403`
  ```json
  { "status": "error", "message": "บัญชีนี้ถูกปิดการใช้งาน กรุณาติดต่อผู้ดูแลระบบค่ะ 🔒" }
  ```
  แสดงข้อความให้ติดต่อผู้ดูแล อย่า retry · รหัสผ่านผิดยังได้ `401` เหมือนเดิม
- **Session ที่เปิดค้างอยู่:** request ถัดไปของ user ที่ถูกปิดได้ `401` → flow 401 เดิม (เด้งไป login) ใช้ได้เลย
- ส่ง `status` มาทาง `/register`, `PUT /profile`, `POST /users`, `PUT /users/{id}` → **ถูกเมิน** (ไม่ error)

## 2. 🔴 Breakfast toggle + เรทรายหัว

```jsonc
"addons": {
  "breakfast_enabled": true,         // toggle
  "breakfast_code": "breakfast_200"  // optional — เรทจาก global_rates (rate_type='addon')
}
```

- สูตร: `breakfast_price = rate × nights × จำนวน guests ของห้อง` (guests ว่าง = 1 คน)
- `breakfast_code` ไม่มีในระบบ/ถูกปิด → `422`
- Response addon: ถอด `breakfast_set_100` / `breakfast_set_200` → ใช้ `breakfast_enabled` + `breakfast_code` · `breakfast_price` คงชื่อเดิม (ยอดตามสูตรใหม่)
- `breakfast_sets` / `breakfast` แบบตัวเลขเดิม **ยังรับช่วง transition** แต่ถูกตีความเป็นแค่ "เปิด toggle" (จำนวนชุดหาย) — เปลี่ยนไปส่งแบบ canonical โดยเร็ว

## 3. 🟢 แก้ addon บนห้องที่ assign เลขแล้ว (ก่อนเช็คอิน)

- `PUT /bookings/{bookingId}/rooms/{bookingRoomId}` บนบิล `paid|confirmed` + BR `confirmed` ที่มี `room_id` แล้ว — ถ้า payload เป็น **`addons`/`extra_beds` ล้วน** จะผ่าน (เดิม `422` ชี้ไป change-room ซึ่งไม่รับ addon)
- ค่าห้องคงเดิม surcharge เฉพาะส่วน addon → ไปอยู่ใน `outstanding_amount` เก็บต่อที่ `POST /front-desk/{id}/payment`
- ส่ง field อื่นปน (เช่น `room_type_id`, วันที่) → ยัง `422` เหมือนเดิม

## 4. 🟢 Report PDF export

```
GET /api/v1/reports/{slug}/export              → .xlsx (default เดิม)
GET /api/v1/reports/{slug}/export?format=pdf   → .pdf
```

- ทั้ง 15 slug · สิทธิ์ / throttle `10,1` / เพดานช่วงวันที่ 366 วัน คงเดิม
- `format` อื่น → `422` · PDF render พัง → JSON `500` ตาม error shape ปกติ (เดิมหลุดเป็นหน้า HTML)

## 5. 🟢 Change-room ล่วงหน้า

- `POST /front-desk/{bookingId}/rooms/{bookingRoomId}/change-room` สำหรับ BR `confirmed`/`draft` (ยังไม่เช็คอิน) **ไม่ตรวจสถานะ board ของห้องปลายทางแล้ว** — ห้องที่วันนี้ `occupied`/`checkout_makeup` รับการจองอนาคตที่ไม่ทับช่วงได้ (`200`)
- ช่วงพักทับกัน หรือติดช่วงซ่อม/สำรอง → ยัง `422`
- ย้ายระหว่างพัก (BR `checked_in`) คง guard สถานะห้องเดิม (`available|prep_checkin|dirty`)

## 6. 🟢 FYI (ไม่ต้องแก้โค้ด FE)

- **รายงานอาหารเช้า** (`/reports/breakfast/export`) เคย `500` ทุกวันที่บน prod (PostgreSQL boolean) — แก้แล้ว
- **Board สถานะห้อง:** job รายวันเคยพังทุกคืนเพราะห้อง `checkout_makeup` ค้าง → ห้องค้าง > 2 วันไม่ถูก flag `dirty` · แก้แล้ว — **หลัง deploy รอบแรกจะเห็นห้องกลายเป็น `dirty` + งานแม่บ้าน `daily` เพิ่มทีเดียวจำนวนมาก (~74 ห้อง)** เป็นเรื่องปกติ
- **Discount `room_type_ids`:** เพิ่มเอกสารใน `api_guide.md` — `null` = ทุก room type · array UUID = จำกัด room type · eligibility ตรวจรายห้องตอน apply (behavior ไม่เปลี่ยน)
