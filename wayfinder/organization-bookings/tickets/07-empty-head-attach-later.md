# Empty-head booking — การ attach user/organization ทีหลัง

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** [03-org-booking-shape-on-post-bookings](./03-org-booking-shape-on-post-bookings.md)
- **assignee:** kevii (grilled 2026-09-25)

## Question

เกิดจาก UI flow จริง (owner, 2026-09-25): admin **สร้าง booking เฮดเปล่าก่อน แล้วค่อยเลือก organization (หรือ user account) ทีหลัง** (use case: group / monthly) — ticket 02 ล็อกว่าสร้างเฮดเปล่าได้เต็มรูปแล้ว แต่กลไกการ attach ทีหลังยังไม่มีใครตัดสิน:

- **Endpoint รูปร่างไหน:** endpoint ใหม่ (เช่น `PUT /bookings/{id}/customer`) หรือรับ field เพิ่มใน draft-edit เดิม (`PUT /bookings/{bookingId}/rooms/...`)? ส่ง `user` UUID / `organization` + `customer_name` ผ่านช่องเดียวกันได้ไหม?
- **Timing:** attach ได้เฉพาะ `draft` เท่านั้นไหม — ถ้าบิลถึง `pending`/`paid` แล้วค่อยรู้ว่าเป็นขององค์กรใครล่ะ (front desk เจอ case นี้แน่)?
- **Repricing ตอน attach:** ถ้า attach user ที่เป็น `ku_member` ทีหลัง ราคาต้องคำนวณใหม่เป็น `daily_ku` ไหม (ราคาอาจเปลี่ยนระหว่างที่ admin จองเฮดเปล่าด้วยเรท daily ไว้ก่อนแล้ว)
- **การถอด/เปลี่ยนใจ:** attach แล้วถอดออกหรือเปลี่ยนเป็นอีก org/user ได้ไหม — มีเงื่อนไขสถานะหรือไม่?
- **สลิป/ความรับผิดชอบก่อน attach:** บิลเฮดเปล่า (user_id=null, ยังไม่มี org) ใครส่งสลิป — admin ผู้เดียวหรืออนุญาตให้มี "contact ชั่วคราว"?

## ✅ Resolution (2026-09-25 — grilled กับ owner ผ่าน AskUserQuestion ครบ 4 คำถาม · ข้อสลิปถูกตัดสินไปแล้วใน ticket 04 ข้อ 1)

1. **Endpoint = ขยาย `PUT /bookings/{id}` (`updateBookingPayment`) เดิม** — เพิ่ม field `user` (UUID) / `organize` (erp code) / `customer_name` / `customer_phone` / `customer_email` · validation ชุดเดียวกับ POST /bookings (mutually exclusive, organize บังคับ customer_name, erp lookup ไม่เจอ/inactive → 422)
   - **ผลพวงสำคัญ:** guard ของ endpoint เปลี่ยนจาก "draft-only ทั้ง endpoint" เป็น **แยกตาม field-group** — payment fields (`payment_type`/`deposit_amount`/`discount_code`/`payment_deadline`) = draft-only เดิม · customer identity fields = อนุญาตตามข้อ 2 · admin/system-only คงเดิม (ไม่ผูก `source='admin'` ของ booking ตอน attach — ผู้เรียกคือ admin อยู่แล้ว)
2. **Timing = attach/เปลี่ยน/ถอดได้ตั้งแต่ `draft` จนถึง `checked_in`** — ⛔ เมื่อ `complete` (terminal) หรือ `no_show` (รองรับ front desk ที่เจอลูกค้าถึงเคาน์เตอร์แล้วค่อยรู้ว่าเป็นขององค์กรไหน)
3. **Repricing = reprice เฉพาะตอนบิลยัง `draft`** — attach/ถอด `user` บน draft → reprice ผ่าน `DiscountService::reprice()` ทันที (chokepoint อ่าน `$booking->user` — ku_member ได้ daily_ku อัตโนมัติ, ถอดกลับเป็น daily สมมาตร) · attach หลัง draft ขึ้นไป = เขียน identity เท่านั้น **ราคาคงเดิม** (ไม่ไปยุ่งยอดที่สลิป/การชำระ lock ไว้)
4. **ถอด/เปลี่ยน = กติกาเดียวกับ attach บน endpoint เดียว** — ส่ง `user` หรือ `organize` ใหม่ = แทนที่ identity เดิมทั้งชุด (org เดิมถูกแทนที่ / user เดิมถูกแทนที่) · ส่ง `user: null` หรือ `organize: null` ชัด ๆ = ถอดกลับเฮดเปล่า (เคลียร์ `user_id`/`organization_id`/`customer_*` ครบ) · "แทนที่" = เคลียร์ชุดเก่าก่อนแล้วเขียนด้วย field ที่ส่งมา (ไม่ส่ง customer_phone/email = null)
5. **สลิปก่อน attach:** ตัดสินแล้วใน ticket 04 ข้อ 1 — admin ส่งสลิปแทนบิลไร้ user ที่ไม่ใช่ deferred ได้ผ่าน ownership guard "เจ้าของหรือ admin" เดิม ไม่แตะ `confirm` · deferred โดน block ตามแมป `booking-payment-types`
6. **replaceability คงเดิม (ticket 01):** attach เก็บ FK `organization_id` + snapshot 3 columns เท่าเดิม — ไม่เพิ่ม column/state ใหม่ · ไม่ re-run draft-dedup ตอน attach user (ผู้ตัดสินคือ admin — ต่างจาก POST โหมด A)
