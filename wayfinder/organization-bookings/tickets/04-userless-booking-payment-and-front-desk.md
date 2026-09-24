# ชีวิตหลังจองของ booking ไร้ user — payment, deadline และ front desk

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** [03-org-booking-shape-on-post-bookings](./03-org-booking-shape-on-post-bookings.md)
- **assignee:** (ว่าง)

## Question

**⚠️ Cross-map blocked (2026-09-24):** design ประเภทการชำระเงิน (เต็มจำนวน/มัดจำ/ค้างชำระ + ยอดเงิน) ถูก chart เป็นแมปใหม่ [`booking-payment-types`](../../booking-payment-types/map.md) — ticket นี้รอ design ของแมปนั้นปิดก่อน (ค้างชำระ = payment type ที่ org booking ใช้เสมอ — owner) แล้วค่อยกลับมาตัดสินส่วนของ org ตรงนี้

Booking องค์กรไม่มี user account — ทุก flow หลังจองที่มาผูกกับ "user เป็นคนทำ" ต้องตัดสินเจ้าของใหม่:

- **สลิป:** `POST /bookings/{id}/confirm` (ส่งสลิป `draft → pending`) เป็นของเจ้าของ booking — org booking ไม่มีเจ้าของ → **admin อัปโหลดสลิปแทนได้ไหม** หรือ org จ่ายผ่านช่องทางอื่นเท่านั้น (เงินสด `draft → paid`, walk-in `draft → confirmed`)?
- **`payment_deadline` 24 ชม.:** draft org booking โดนเช็คเดียวกันไหม — `CleanupExpiredDrafts` (02:00) จะลบทิ้งถ้าไม่จ่ายใน 24 ชม. ซึ่งองค์กรที่จองล่วงหน้าแล้วรออนุมัติเอกสารอาจไม่ทัน — ยืด deadline / ไม่ตั้ง deadline / ยอมรับกติกาเดิม?
- **Front desk:** check-in ใช้อะไรเป็นข้อมูลผู้เข้าพัก — `customer_name` + guests ต่อห้องพอไหม เจ้าหน้าที่อ้าง booking องค์กรตอน check-in ยังไง (ค้นด้วย confirmation / ชื่อองค์กร)?
- **`customer_phone` / `customer_email` ใช้ทำอะไรต่อ:** เก็บไว้ติดต่อล้วน หรือมี flow (แจ้งสลิป / ออกใบเสร็จหัวองค์กร) — ใบเสร็จ billing ปัจจุบันอยู่ที่ booking_rooms (`billing_address`/`billing_comment`) — ต้องเชื่อมกับข้อมูลองค์กรไหม?
