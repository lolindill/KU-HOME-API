# 10: Implement — Surcharge เปลี่ยน room type หลังจ่ายแล้ว (ปรับราคาเก็บเพิ่ม)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **closed:** 2026-09-25
- **blocked-by:** (ไม่มี — 04/05/06/07/08/09 ปิดหมด)
- **assignee:** kevii (claimed 2026-09-25)
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

## Resolution

**(2026-09-25 — implement จบ AFK, suite 554 เขียว — เดิม 544 + ใหม่ 10)**

**ตัดสินใจ implement (ทุกข้อถูกจดแล้วใน `cline.md` หัวข้อ "💳 Surcharge — แก้ห้องหลังชำระเงินแล้ว"):**

1. **Endpoint = ขยาย `PUT /bookings/{bookingId}/rooms/{bookingRoomId}` เดิม** (`BookingController::updateRoom`) เป็น 2 โหมด — ไม่สร้าง endpoint ใหม่: `draft` = flow เดิม 100% regression 0% · `paid|confirmed` = **surcharge หลังจ่าย, admin เท่านั้น** (user เจ้าของ = `403` — ตามธรรมเนียม payment fields)
2. **สถานะที่อนุญาต:** BR `draft|confirmed` (checked_in/checked_out/no_show = `422`) · BR ที่มี `room_id` ถูก auto-assign แล้ว **เปลี่ยน room_type/วันที่ไม่ได้** (`422` — ปลดการจัดห้องก่อน; guests/billing/addons แก้ต่อได้) · availability re-check + `holdingSlot()` ใช้โค้ดเดิมทั้งก้อน + lock/re-check รับทั้ง draft/paid/confirmed
3. **reprice ผ่าน chokepoint เดียว** `DiscountService::reprice()` เรียกตรง — **ไม่** ผ่าน `reconcileDiscount()` (`applyToDraft` guard draft-only · used redemption ยังถูกนับใน reprice) · กติกาปัดสิบ (ticket 09) ไหลตามอัตโนมัติ · invariant Σ คงอยู่
4. **Downgrade:** ยอดรวมใหม่ **< `paid_amount` = `422` rollback** (ticket 08 ปิดทางคืนเงิน — ส่วนต่างลดจนเกินเงินที่จ่าย = ต้อง refund ซึ่งระบบไม่มี จึงเลือก "บล็อก" ไม่ใช่ "เครดิตคงค้าง") · ลดได้เฉพาะยอดรวมใหม่ ≥ paid → `outstanding` ลดตาม (`surcharge_amount` ติดลบได้)
5. **`deposit_amount` คงเดิมไม่ย้อน** — เป็นข้อมูลงวดที่ตั้ง/จ่ายไปแล้ว · effective (null = 50%) ไหลตาม total ใหม่เอง · **`is_paid` reset** `true → false` เมื่อ surcharge ทำ total > paid (นิยาม ticket 04) — recordPayment set คืนเมื่อเก็บครบ
6. **Response เพิ่ม 6 field เสมอ (draft mode ด้วย):** `previous_total_amount` · `surcharge_amount` · envelope `payment_type/deposit_amount/paid_amount/outstanding_amount` — เก็บส่วนต่างต่อผ่าน `recordPayment` กลไกเดิม (ไม่เขียน payments นอก 3 จุด — ไม่แตะ)
7. **Regression ตั้งใจ 1 จุด:** `BookingTest::test_update_room_blocked_when_booking_not_draft` 422 → **403** (user แก้ booking paid — สิทธิ์ชัดเจนขึ้น)
8. Tests `tests/Feature/BookingSurchargeAfterPaymentTest.php` 10 เคส: surcharge deposit/full + reset is_paid + downgrade บล็อก/ยอม + 403 + pending 422 + availability 422 + room_id 422 + checked_in 422 + draft regression · docs `api_guide.md` หัวข้อ "💳 Surcharge — แก้ห้องหลังชำระเงิน (ticket 10)" + หัวข้อ endpoint PUT rooms
