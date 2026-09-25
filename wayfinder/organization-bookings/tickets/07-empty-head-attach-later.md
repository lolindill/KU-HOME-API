# Empty-head booking — การ attach user/organization ทีหลัง

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** [03-org-booking-shape-on-post-bookings](./03-org-booking-shape-on-post-bookings.md)
- **assignee:** (ว่าง)

## Question

เกิดจาก UI flow จริง (owner, 2026-09-25): admin **สร้าง booking เฮดเปล่าก่อน แล้วค่อยเลือก organization (หรือ user account) ทีหลัง** (use case: group / monthly) — ticket 02 ล็อกว่าสร้างเฮดเปล่าได้เต็มรูปแล้ว แต่กลไกการ attach ทีหลังยังไม่มีใครตัดสิน:

- **Endpoint รูปร่างไหน:** endpoint ใหม่ (เช่น `PUT /bookings/{id}/customer`) หรือรับ field เพิ่มใน draft-edit เดิม (`PUT /bookings/{bookingId}/rooms/...`)? ส่ง `user` UUID / `organization` + `customer_name` ผ่านช่องเดียวกันได้ไหม?
- **Timing:** attach ได้เฉพาะ `draft` เท่านั้นไหม — ถ้าบิลถึง `pending`/`paid` แล้วค่อยรู้ว่าเป็นขององค์กรใครล่ะ (front desk เจอ case นี้แน่)?
- **Repricing ตอน attach:** ถ้า attach user ที่เป็น `ku_member` ทีหลัง ราคาต้องคำนวณใหม่เป็น `daily_ku` ไหม (ราคาอาจเปลี่ยนระหว่างที่ admin จองเฮดเปล่าด้วยเรท daily ไว้ก่อนแล้ว)
- **การถอด/เปลี่ยนใจ:** attach แล้วถอดออกหรือเปลี่ยนเป็นอีก org/user ได้ไหม — มีเงื่อนไขสถานะหรือไม่?
- **สลิป/ความรับผิดชอบก่อน attach:** บิลเฮดเปล่า (user_id=null, ยังไม่มี org) ใครส่งสลิป — admin ผู้เดียวหรืออนุญาตให้มี "contact ชั่วคราว"?
