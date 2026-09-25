# 10: Implement — Surcharge เปลี่ยน room type หลังจ่ายแล้ว (ปรับราคาเก็บเพิ่ม)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** (ไม่มี — 04/05/06/07/08/09 ปิดหมด)
- **assignee:** (ว่าง)
- **born:** 2026-09-25 — graduate จาก resolution ของ ticket 08 (owner: "มีกรณีเปลี่ยน room type แล้วราคาเพิ่ม ที่ทำได้ ปรับราคาให้จ่ายเพิ่มด้วย")

## Question

ลงมือ implement flow **แก้ booking_room หลังมีเงินเข้าแล้ว (surcharge)** — โครงตาม decision ticket 08 ข้อ 7:

- **สถานการณ์:** booking มัดจำ/ค้างชำระ verify ผ่าน (paid/confirmed) แล้ว ต้องการเปลี่ยน room type (หรือแก้รายละเอียดห้อง) ให้แขก — ราคารวมเปลี่ยน **ราคาเพิ่ม** → ยอดส่วนต่างเข้า `outstanding_amount` เก็บต่อผ่าน `recordPayment` (กลไกเดิม — ไม่มี endpoint เงินใหม่)
- **สิ่งที่ต้องตัดสินใน implement:**
  - endpoint/ช่องทางแก้ booking_room หลัง draft — ขยาย `PUT /bookings/{bookingId}/rooms/{bookingRoomId}` เดิม (ตอนนี้ guard draft-only) หรือ endpoint ใหม่สำหรับ post-payment edit
  - สถานะที่อนุญาต (deposit ที่ verify แล้ว / deferred ที่ admin อนุมัติแล้ว — `paid`/`confirmed`) และสิทธิ์ (admin เท่านั้นตามธรรมเนียม payment fields?)
  - reprice ผ่าน chokepoint เดียว `DiscountService::reprice()` — invariant `Σ booking_rooms.amount == total_amount` คงอยู่ · กติกาปัดสิบ (ticket 09) ไหลตาม · availability re-check + `holdingSlot()` ก่อนเปลี่ยน type
  - กรณีราคา**ลด** (downgrade) — ยอด outstanding ลดตามหรือไม่อนุญาต (ticket 08 ปิดทางคืนเงิน — ส่วนต่างลด = เครดิตคงค้างหรือบล็อก)
  - `deposit_amount` ที่จ่ายไปแล้วไม่ย้อน — มัดจำ default ใหม่ควรคิดจาก total ใหม่หรือคงยอดเดิม
- **ข้อห้าม (จาก tickets ที่ปิดแล้ว):** ห้ามเขียน `payments` นอก 3 จุดเดิม · ห้ามแตะ state machine · ห้ามมี refund/negative row
- ทดสอบ: surcharge เพิ่ม/ลด, availability ตัน → 422, non-admin 403, invariant Σ, ยอด envelope 4 field ถูกต้องหลังแก้ · `php artisan test` เขียวทั้ง suite
- อัปเดต `docs/api_guide.md` + จด decision ลง `cline.md`
