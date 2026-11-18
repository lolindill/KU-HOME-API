# 📢 API Patch Notes (Frontend) — 🛡️ Identity-detach guard บน `PUT /bookings/{id}` (2026-11-18)

> **สำหรับ:** ทีม Frontend (`ku-home`)
> **ประเด็น:** บิล self-booking ที่ `user_id` กลายเป็น `null` ในหน้า report — สาเหตุ และ contract ใหม่ของ `PUT /bookings/{id}` (admin/system เท่านั้น)

---

## ⚡ TL;DR — FE ต้องทำอะไร

| # | หัวข้อ | ผลกระทบ / สิ่งที่ FE ต้องทำ |
|---|---|---|
| 1 | 🛡️ **`user`/`organize` เป็น `null`/`""` ไม่มี flag = no-op** | ห้ามแถม `user`/`organize` เป็น `null`/`""` ใน payload ถ้าไม่ได้ตั้งใจแก้ identity — ค่าที่ส่งมา backend จะ **ข้าม (no-op) identity คงเดิม** (เดิมโดนถอดจน `user_id` หาย) |
| 2 | 🆕 **ถอด identity ต้องส่ง `detach_identity: true`** | ตั้งใจทำบิลกลับเฮดเปล่า = ส่ง `"detach_identity": true` (ไม่ส่ง `user`/`organize` หรือส่งเป็น null ก็ได้) |
| 3 | 🔴 **`detach_identity` คู่กับค่าใหม่ → `422`** | ส่ง `detach_identity: true` พร้อม `user` / `organize` / `customer_name|phone|email` ที่มีค่า → `422` (เลือกถอด หรือ attach อย่างเดียว) |
| 4 | 🔧 **บิลที่ `user_id` โดนล้างไปแล้ว** | ไม่มี restore อัตโนมัติ — attach คืนด้วย `PUT /bookings/{id}` body `{"user": "<user-uuid>"}` (บิล state อื่นคงเดิม ไม่กระทบ) |

---

## 🐛 สาเหตุที่บิล self-booking มี `user_id = null`

- `POST /bookings` ด้วย token user ปกติ **ตั้ง `user_id` ถูกต้องเสมอ** (ตรวจแล้ว)
- แต่ contract เดิมของ `PUT /bookings/{id}` (organization-bookings ticket 07) ระบุว่า "ส่ง `user`/`organize` = null ชัด ๆ = ถอด identity กลับเฮดเปล่า" และ backend เช็คด้วย `$request->has()` — ซึ่ง **คืน `true` แม้ค่าที่ส่งเป็น `null`/`""`**
- ผลคือ: ตอน admin ใช้ฟอร์มแก้ข้อมูลบิล (เช่นแก้ payment fields) ถ้า FE ยิง payload ที่แถม identity key ครบทุกตัวแบบ `"user": null` → backend เข้า branch "ถอด identity" แล้ว **ล้าง `user_id` + `organization_id` + `customer_*` ของบิลทิ้งเฉย ๆ**

---

## ✅ พฤติกรรมใหม่ของ identity group บน `PUT /bookings/{id}`

| Payload | ผล |
|---|---|
| ไม่ส่ง `user`/`organize` | identity คงเดิม (เหมือนเดิม) |
| `"user": null` หรือ `"organize": null`/`""` **(ไม่มี flag)** | 🛡️ **no-op** — identity คงเดิมทั้งชุด (ของใหม่) |
| `"user": "<uuid>"` | attach/switch จองแทน — เหมือนเดิม (แทนที่ identity เดิมทั้งชุด) |
| `"organize": "<erp>"` + `customer_name` | attach/switch org — เหมือนเดิม |
| `"detach_identity": true` | 🆕 ถอดกลับเฮดเปล่า (ล้าง `user_id` + `organization_id` + `customer_*` ครบ) |
| `"detach_identity": true` + `user`/`organize`/`customer_*` มีค่า | 🔴 `422` |

- Guard สถานะคงเดิม: identity group แก้ได้จนก่อน `complete`/`no_show` (`detach_identity` โดน guard ตัวเดียวกัน)
- แก้ `customer_name|phone|email` เป็น `null` ยังคงหมายถึง "เคลียร์ field นั้น" ตามเดิม (per-field, ไม่เกี่ยวกับ flag)

```json
// ตัวอย่าง: admin แก้ payment fields อย่างเดียว — อย่าแถม identity keys
PUT /api/v1/bookings/{id}
{ "payment_deadline": "2026-11-20 18:00:00" }

// ตัวอย่าง: ถอด identity กลับเฮดเปล่า (ตั้งใจ)
PUT /api/v1/bookings/{id}
{ "detach_identity": true }

// ตัวอย่าง: แก้เบอร์ผู้ติดต่ออย่างเดียว
PUT /api/v1/bookings/{id}
{ "customer_phone": "0812345678" }
```

---

*Backend ref: `BookingManagementController::updateBookingPayment` · tests: `BookingAttachCustomerTest` (15 cases) · จดใน `cline.md` หัวข้อ "🛡️ Identity-detach guard (2026-11-18)"*
