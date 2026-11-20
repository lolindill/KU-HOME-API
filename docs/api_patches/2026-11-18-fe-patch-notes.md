# 📢 API Patch Notes (Frontend) — 2026-11-18 (`dev` @ `1dac03f` / `main` @ `49240b8`)

> **สำหรับ:** ทีม Frontend (`ku-home`)
> **ขอบเขต:** สรุปเฉพาะสิ่งที่ Frontend ต้องรู้/ต้องปรับ จากทุก commit ที่อัปเดตขึ้น `dev` และ `main` รอบนี้ (commits `4a1550e` → `1dac03f`)
> **เอกสารละเอียดประกอบ:** [`2026-11-18-identity-detach-guard.md`](./2026-11-18-identity-detach-guard.md) · [`2026-11-18-booking-owner-envelope.md`](./2026-11-18-booking-owner-envelope.md)

---

## ⚡ TL;DR — FE ต้องทำอะไรบ้าง

| # | หัวข้อ | ประเภท | ผลกระทบ / สิ่งที่ FE ต้องทำ |
|---|---|---|---|
| 1 | 🔴 **Booking identity → `owner` object (owner-envelope)** | 🔴 **BREAKING** (wire format) | `organization_id`, `customer_name`, `customer_phone`, `customer_email` **หายจาก serialization ของ booking ทุก endpoint** · ใน envelope ของ `POST /bookings` 201 และ `PUT /bookings/{id}` 200 ตัด `user_id` echo ด้วย → ย้ายมาอ่าน **`owner`** (`booking.owner` / top-level `owner`) — ได้ **ชื่อองค์กร + erp ในตัว** (`owner.name`, `owner.erp`) ไม่ต้องรอ object `organization` (admin-only) อีกต่อไป |
| 2 | 🛡️ **Detach identity ต้องประกาศชัด (`detach_identity: true`)** | 🟡 Contract fix | บน `PUT /bookings/{id}`: ส่ง `user`/`organize` เป็น `null`/`""` **ไม่มีผลแล้ว (no-op — identity คงเดิม)** · ตั้งใจถอดกลับเฮดเปล่าต้องส่ง `"detach_identity": true` · flag คู่กับค่าใหม่ → `422` — **ห้ามแถม identity keys เป็น null ใน payload อีกต่อไป (แถมก็ไม่พังแล้ว แต่ไม่จำเป็น)** |
| 3 | 🔧 **Remediation บิลเก่าที่ `user_id` โดนล้าง** | 🟡 งานค้าง | ไม่มี restore อัตโนมัติ — re-attach ด้วย `PUT /bookings/{id}` body `{"user": "<user-uuid>"}` (ยืนยันบน domain จริงแล้วว่า re-attach ใช้ได้) · audit ว่า form ไหนแถม identity keys แล้วเอาออก |

---

## 1. 🔴 Breaking: `owner` object — identity ของบิลเป็น object เดียว

**มีทุก endpoint ที่ serialize Booking:** `POST /bookings` 201 (top-level `owner`) · `GET /bookings` · `GET /bookings/{id}` · `PUT /bookings/{id}` 200 (top-level `owner`) · discount-code / assign-rooms (ผ่าน `booking.owner`)

### Shape — key set เดียวกันทุก type (บิลหนึ่งมีเจ้าของได้คนเดียวเสมอ)

| `owner.type` | ความหมาย | `id` | `name` | `erp` | `customer_*` |
|---|---|---|---|---|---|
| `organization` | โหมด C จองให้องค์กร | organization_id | **ชื่อองค์กร (live)** | erp (live) | snapshot ผู้ติดต่อ |
| `user` | จองเอง / โหมด A จองแทน / walk-in (user_id = staff) | user_id | ชื่อ user (live) | `null` | snapshot (มีได้เมื่อ attach ระบุ) |
| `contact` | โหมด B เฮดเปล่าระบุผู้ติดต่อ | `null` | `null` | `null` | snapshot ผู้ติดต่อ |
| `owner = null` | draft ลอยไม่ระบุใคร / หลัง `detach_identity` | — | — | — | — |

```json
// บิลองค์กร (โหมด C) — ชื่อองค์กร + erp มาในตัวแล้ว
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

### ผลกระทบราย endpoint

| Endpoint | เปลี่ยนแปลง |
|---|---|
| `POST /bookings` 201 | ตัด echo `user_id` + `organization_id` + `customer_*` → เพิ่ม `owner` |
| `GET /bookings` | บิลแต่ละแถว: flat 4 field หาย → `owner` เพิ่ม · `user_id` (FK column) **ยังอยู่** · `search_criteria.organization_id` echo คงเดิม |
| `GET /bookings/{id}` | เหมือน list — object `organization` (admin-only) ยัง eager-load ตามเดิม |
| `PUT /bookings/{id}` 200 | ตัด echo 5 field → เพิ่ม `owner` — ดูผล attach/switch/detach จาก `owner.type` + `owner.id` |
| discount-code / assign-rooms | flat หาย + `owner` เพิ่ม อัตโนมัติ |

**คงเดิม:** request side ทั้งหมด (`POST /bookings` ยังรับ `organize` + `customer_*`, `PUT` ยังรับ `user`/`organize`/`detach_identity`) · รายงาน Excel ฝั่ง server ไม่กระทบ · `search` ด้วย `term` (ค้น `customer_name`) และ filter `?organization_id=` ใช้ได้ตามเดิม

---

## 2. 🛡️ Detach identity guard บน `PUT /bookings/{id}`

| Payload | ผล |
|---|---|
| ไม่ส่ง `user`/`organize` | identity คงเดิม (เหมือนเดิม) |
| `"user": null` / `"organize": null`/`""` **(ไม่มี flag)** | 🛡️ **no-op** — identity คงเดิมทั้งชุด (เดิมโดนล้าง — ต้นตอ bug `user_id = null` ในหน้า report) |
| `"user": "<uuid>"` | attach/switch จองแทน — เหมือนเดิม (แทนที่ identity ทั้งชุด) |
| `"organize": "<erp>"` + `customer_name` | attach/switch org — เหมือนเดิม |
| `"detach_identity": true` | ถอดกลับเฮดเปล่า (ล้าง `user_id` + `organization_id` + `customer_*` ครบ) |
| `"detach_identity": true` + `user`/`organize`/`customer_*` มีค่า | 🔴 `422` |

- Guard สถานะคงเดิม: identity group แก้ได้จนก่อน `complete`/`no_show`
- แก้ `customer_*` เป็น `null` ยังหมายถึง "เคลียร์ field นั้น" ตามเดิม (per-field)
- ตรวจโค้ด fix บน **domain จริง** (`ku-home.ku.ac.th`) ผ่านครบ 4 เช็คแล้ว (2026-11-18) — server รันโค้ดใหม่แล้ว ไม่ต้อง restart

---

## 3. 🔧 งานค้างฝั่ง FE (remediation)

- บิล self-booking เก่าที่ `user_id` โดนล้างไปแล้ว: re-attach ด้วย `PUT /bookings/{id}` body `{"user": "<user-uuid>"}` (state อื่นคงเดิม ไม่กระทบ)
- ตรวจ form ฝั่ง FE ที่ generic payload แถม `user`/`organize`/`customer_*` เป็น null ตอน save — ตัด key เหล่านั้นออกเมื่อไม่ได้แก้ identity (แม้จะ no-op แล้ว แต่ตัดออกคือชัดที่สุด)
- ปรับ UI ที่แสดงเจ้าของบิล: อ่านจาก `owner` จุดเดียว (`owner.name` / `owner.type` / `owner.customer_name`) — และหน้า org booking ใช้ `owner.name`/`owner.erp` ได้เลยโดยไม่ต้องรอ relation `organization`

---

*Backend ref: `3751fc6` (detach guard) · `1dac03f` (owner-envelope) · sync main @ `49240b8` ผ่าน `scripts/sync-main.sh` · tests: suite เต็ม 992 passed (4,284 assertions) · ยืนยัน remote: `test_scripts/test_detach_guard_remote.php` ผ่าน 4/4*
