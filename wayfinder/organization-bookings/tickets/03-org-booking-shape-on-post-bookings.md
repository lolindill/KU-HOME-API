# Booking ให้องค์กรบน POST /bookings เดิม — field set และ consumer ของ user_id = null

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** [01-organization-table-erp-and-replaceability](./01-organization-table-erp-and-replaceability.md)
- **assignee:** (ว่าง)

## Question

Requirement จาก owner (ล็อกแล้ว): org booking ใช้ **`POST /bookings` เดิม** — ไม่มี endpoint ใหม่ · เพิ่ม field `organize` (erp) + `customer_name` (string) + `customer_phone` / `customer_email` (nullable) · booking ผูกกับตาราง organizations **แทน** user table:

- **Validation shape:** org booking เกิดเมื่อไหร่ — ส่ง `organize` มา = เข้าโหมด org เลยไหม? `customer_name` บังคับเสมอไหม (ตาม requirement: phone/email เท่านั้นที่ nullable)? ห้ามส่ง `user` (จองแทน) กับ `organize` พร้อมกันใช่ไหม — mutually exclusive หรือมีลำดับชนะ?
- **`user_id = null` กระเพื่อมไปที่ไหนบ้าง** (schema nullable อยู่แล้ว — ปัญหาอยู่ที่ consumer):
  - ownership checks ทุกจุด ("เจ้าของหรือ admin") — null = เหลือแต่ admin
  - `Booking::getPrimaryGuestNameAttribute` fallback `user?->name` — org booking fallback ไป `customer_name` ไหม
  - **ราคา** — org ไม่มี user/role → เรท `daily` ปกติเสมอ ใช่ไหม (ไม่มีทางโดน daily_ku)
  - draft-dedup "1 draft ต่อ user" — ใช้กับ org booking ไหม (org จองซ้อนหลายบิลพร้อมกันได้ไหม)
- **customer snapshot เก็บที่ไหน:** column ใหม่บน `bookings` เลย หรือ pattern เดียวกับ guests JSON ของ booking_rooms? (requirement ชี้ contact ขององค์กร = 1 คนต่อ booking ไม่ใช่ต่อห้อง — น้ำหนักไปที่ bookings)
- **default guest name:** booking_rooms ที่ไม่ส่ง guests ใช้ชื่อผู้จองเป็น default — org booking ดึงจาก `customer_name` แทนได้เลยไหม
