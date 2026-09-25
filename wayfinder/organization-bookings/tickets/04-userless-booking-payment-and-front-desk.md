# ชีวิตหลังจองของ booking ไร้ user — payment, deadline และ front desk

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** [03-org-booking-shape-on-post-bookings](./03-org-booking-shape-on-post-bookings.md)
- **assignee:** kevii (grilled 2026-09-25)

## Question

**⚠️ Cross-map blocked (2026-09-24):** design ประเภทการชำระเงิน (เต็มจำนวน/มัดจำ/ค้างชำระ + ยอดเงิน) ถูก chart เป็นแมปใหม่ [`booking-payment-types`](../../booking-payment-types/map.md) — ticket นี้รอ design ของแมปนั้นปิดก่อน (ค้างชำระ = payment type ที่ org booking ใช้เสมอ — owner) แล้วค่อยกลับมาตัดสินส่วนของ org ตรงนี้

Booking องค์กรไม่มี user account — ทุก flow หลังจองที่มาผูกกับ "user เป็นคนทำ" ต้องตัดสินเจ้าของใหม่:

- **สลิป:** `POST /bookings/{id}/confirm` (ส่งสลิป `draft → pending`) เป็นของเจ้าของ booking — org booking ไม่มีเจ้าของ → **admin อัปโหลดสลิปแทนได้ไหม** หรือ org จ่ายผ่านช่องทางอื่นเท่านั้น (เงินสด `draft → paid`, walk-in `draft → confirmed`)?
- **`payment_deadline` 24 ชม.:** draft org booking โดนเช็คเดียวกันไหม — `CleanupExpiredDrafts` (02:00) จะลบทิ้งถ้าไม่จ่ายใน 24 ชม. ซึ่งองค์กรที่จองล่วงหน้าแล้วรออนุมัติเอกสารอาจไม่ทัน — ยืด deadline / ไม่ตั้ง deadline / ยอมรับกติกาเดิม?
- **Front desk:** check-in ใช้อะไรเป็นข้อมูลผู้เข้าพัก — `customer_name` + guests ต่อห้องพอไหม เจ้าหน้าที่อ้าง booking องค์กรตอน check-in ยังไง (ค้นด้วย confirmation / ชื่อองค์กร)?
- **`customer_phone` / `customer_email` ใช้ทำอะไรต่อ:** เก็บไว้ติดต่อล้วน หรือมี flow (แจ้งสลิป / ออกใบเสร็จหัวองค์กร) — ใบเสร็จ billing ปัจจุบันอยู่ที่ booking_rooms (`billing_address`/`billing_comment`) — ต้องเชื่อมกับข้อมูลองค์กรไหม?

## ✅ Resolution (2026-09-25 — cross-map block ปลดแล้วเมื่อแมป [`booking-payment-types`](../../booking-payment-types/map.md) ปิด 2026-09-25 · grilling กับ owner ผ่าน AskUserQuestion ครบ 4 คำถาม ทุกข้อตามคำแนะนำ)

**ถูกตัดสินไปกับแมป `booking-payment-types` แล้ว (ส่งมอบหัวข้อ 📌 ของ ticket 05 แมปนั้น) — ไม่ถามซ้ำ:**
- org booking = **`deferred` เสมอ** (admin-only ตาม ticket 01 แมปนั้น) → สลิปถูก block 422, อนุมัติผ่าน `PUT /bookings/update/{id}` (`draft → confirmed` ข้าม `paid`), เก็บเงินผ่าน `recordPayment` เดิม, `complete` ได้ทั้งที่ยังค้าง (`is_paid` ตาม ledger)
- draft `deferred` **ยกเว้น `CleanupExpiredDrafts` + ยังยึด slot** จน admin confirm หรือลบ (ticket 03 แมปนั้น ข้อ 3) — คำถาม deadline ของ org booking ปิดที่ข้อนี้แล้ว

**คำตอบของ ticket นี้:**
1. **สลิป:** admin ส่งสลิปแทนบิลไร้ user ที่ไม่ใช่ deferred ได้ (เช่น เฮดเปล่าโหมด B ชำระเต็ม) — ใช้ ownership guard เดิม "เจ้าของหรือ admin" ซึ่งปล่อย admin ผ่านอยู่แล้ว **ไม่แตะ `confirm` เพิ่ม** · deferred โดน block ตาม design แมป payment-types (หัวข้อ 3 — message "booking ค้างชำระ — รอแอดมินอนุมัติ")
2. **Deadline เฮดเปล่า (user_id=null, ไม่ใช่ deferred):** โดน `CleanupExpiredDrafts` ลบใน 15 นาทีตามกติกาเดิม — admin ขยายเวลาเองผ่าน field `payment_deadline` บน **`PUT /bookings/{id}`** (endpoint ใหม่ของแมป payment-types ticket 05 หัวข้อ 2) · **ไม่ยกเว้น cleanup เพิ่มสำหรับเฮดเปล่า**
3. **Front desk check-in:** ใช้ของเดิมทั้งหมด — ข้อมูลผู้เข้าพัก = guests ต่อห้อง (primary guest default จาก `customer_name` ตาม ticket 03) · ค้น booking ผ่าน admin index `GET /bookings?term=` (ค้นชื่อ guest ได้อยู่แล้ว) + `showById` UUID · **ไม่เพิ่มค้นเลข confirmation/ชื่อองค์กรใน term ตอนนี้ — filter ตามองค์กรไปตัดสินที่ ticket 08 ที่เดียว**
4. **`customer_phone`/`customer_email` = เก็บติดต่อล้วน** — ไม่ผูก flow ใด (ไม่เติม billing อัตโนมัติ, ไม่ทำใบเสร็จหัวองค์กร) — `billing_address`/`billing_comment` คงอยู่ระดับ booking_rooms เดิม · สอดคล้อง stopgap replaceability (ถ้าเทตาราง organizations ทิ้ง snapshot ก็ยังอ่านความหมายได้ด้วยตัวเอง)
