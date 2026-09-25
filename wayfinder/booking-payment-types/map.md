# 🗺️ Wayfinder Map — Booking Payment Types (เต็มจำนวน / มัดจำ / ค้างชำระ)

- **label:** `wayfinder:map`
- **status:** open
- **tracker:** local-markdown — tickets อยู่ใน `tickets/` ของ directory นี้ (blocking ระบุใน field `blocked-by` ของแต่ละ ticket)
- **charted:** 2026-09-24

## Destination

Booking มีระบบ **ประเภทการชำระเงิน** ครบ 3 แบบ — **เต็มจำนวน (full) · มัดจำ (deposit) · ค้างชำระ (deferred)** — ตั้งแต่ schema (`bookings.payment_type` + ยอดเงิน 2 ชั้น: ชั้น A `booking_confirmations.amount` ต่อครั้งส่งสลิป และชั้น B ภาพรวมจ่ายแล้ว/ค้างบน bookings) ผ่าน API contract + ประสาน state machine จนถึง **implement จบ + suite เขียว** — เพื่อให้ booking องค์กร (แมป `organization-bookings`) ใช้ค้างชำระได้ และ [ticket 08 ของ excel-reports](../excel-reports/tickets/08-payment-amount-channel-deposit.md) กลับมาตัดสิน column รายงานได้

## Notes

- Domain: booking/payment ของ KU HOME API — อ่าน `AGENTS.md` ก่อนทุก session (money = **integer baht**, `PgBoolean` บน boolean column, state machine ผ่าน `transitionStatus()` เท่านั้น ห้าม `->status =` ตรง, UUID PKs, error shape ไทย+emoji)
- **Facts จากโค้ด ณ วัน chart (2026-09-24)** — ปูพื้นให้ทุก ticket ไม่ต้องขุดซ้ำ:
  - `booking_confirmations` = **append-only log** (1 row = 1 ครั้งส่งสลิป · สลิปแนบผ่าน `images` morph · `pending → verified|rejected` ทั้งคู่ terminal · reject → booking `verify_error` · ส่งใหม่ = **row ใหม่เสมอ**) — **ไม่มี column `amount`** (admin verify ด้วยการดูรูปเฉย ๆ)
  - `bookings`: `total_amount` (integer baht) + `is_paid` + `payment_deadline` 24 ชม. (`CleanupExpiredDrafts` กวาด 02:00) — `bookings.confirmation` unique = เลขที่ยืนยัน (**คนละเรื่อง**กับตาราง confirmations)
  - State machine เดิม: `draft → pending → paid → confirmed → complete` (+`verify_error` รอส่งสลิปใหม่) — `paid` ปัจจุบันหมายถึง "ชำระเต็มและ verify ผ่าน" — **ไม่มี concept ชำระบางส่วน**
  - `payments` มี `amount` (integer baht) อยู่แล้ว แต่เขียนจริงแค่ 2 จุด (mock QR `PaymentController::requestPayment` + เงินสด `FrontDeskController::recordPayment`) · `receipts` FROZEN · webhook ยัง `410 GONE`
  - สิทธิ์ flow เดิม: ส่งสลิป = เจ้าของ booking (booking ต้องอยู่ `draft|verify_error`); verify/reject = admin; เงินสดหน้าเคาน์เตอร์ = admin/system (`draft → paid`)
- **Standing decisions ระดับ effort (owner, 2026-09-24):**
  1. **Design + implement จบในแมป** (แบบ `organization-bookings` — ไม่ใช่ spec-only)
  2. **ยอดเงินรวมทั้ง 2 ชั้น** — ชั้น A: `booking_confirmations.amount` (ยอดที่แจ้งต่อครั้งส่ง) + ชั้น B: ภาพรวมจ่ายแล้ว/ค้างบน bookings
  3. **`payment_type` อยู่ระดับ booking** — owner ยืนยันโครงเดิมถูกต้อง: confirmation เป็นแค่ log ของการส่งสลิป ไม่ใช่ที่เก็บประเภทการชำระ
  4. **แมป `organization-bookings` ticket 04 รอ design ของแมปนี้ก่อน** (ค้างชำระ = payment type ที่ org booking ใช้เสมอ — owner)
  5. Default ของ booking ออนไลน์ทั่วไป = **เต็มจำนวน (full)** — flow เดิมต้อง regression เป็น 0%
- 🆕 **(2026-09-24) ticket 07 เพิ่มจาก gap ตรวจ SRS v2:** [ปัดเศษขึ้นหลักสิบ — REQ-015/016](./tickets/07-round-up-to-tens.md) (ปัดยอดไหน ตอนไหน เศษมาจากไหน) — blocked-by ticket 04 (ยอดเงิน 2 ชั้น) · ระบบปัจจุบันไม่มี rounding ที่ไหนเลย
- Related maps: `organization-bookings` (open — ticket 04 blocked cross-map รอแมปนี้), `excel-reports` (open — ticket 08 paused ปลดล็อกเมื่อ design แมปนี้ปิด)
- ไม่มี skill `grilling`/`domain-modeling`/`research` บนเครื่อง — grilling ถาม owner ตรง (AskUserQuestion), งานสำรวจใช้ Explore agent (precedent ku-sso)
- ก่อนเขียนโค้ดจริง: จด design decision ลง `cline.md` ตาม protocol ใน AGENTS.md
- ทำงาน ticket ละ session — เริ่มจาก frontier (ticket open, blocked-by ปลดครบ, ยังไม่มี assignee) — commit tracker ไปกับ branch ปัจจุบันเสมอ
- 🎯 **Frontier ปัจจุบัน:** [ticket 02](./tickets/02-deposit-semantics.md) (deposit semantics) + [ticket 03](./tickets/03-deferred-payment-and-permissions.md) (deferred + สิทธิ์) — ปลดบล็อกพร้อมกันหลัง ticket 01 ปิด (2026-09-25) ทำคนละ session ได้

## Decisions so far

- [01: payment_type enum + schema บน bookings](./tickets/01-payment-type-enum-and-schema.md): column ใหม่บน `bookings` = `full|deposit|deferred` string · `NOT NULL DEFAULT 'full'` + backfill หมด (flow เดิม regression 0%) · ตั้งได้ admin เท่านั้น (POST /bookings มี field นี้ใน input → ต้อง admin, ไม่งั้น 403) · แก้ได้เฉพาะ draft ผ่าน endpoint ใหม่ `PUT /bookings/{id}` (admin-only) · ส่ง `payment_type` กลับทุก response ของ booking

## Not yet specified

- **payment_channel (QR/เงินสด/บัตร) จะกลับมาไหม** — โดน drop ไปแล้ว (`2026_08_19_100000`) พร้อมกันทั้ง confirmations/payments — ยังไม่มีใครตัดสิน; [ticket 08 ของ excel-reports](../excel-reports/tickets/08-payment-amount-channel-deposit.md) ถามข้ามแมป — อาจโผล่มาระหว่าง grill เรื่องยอดเงิน
- **ยกเลิก/คืนมัดจำ** — no_show / ยกเลิกหลังจ่ายมัดจำแล้ว เงินเป็นอย่างไร — รอ flow หลักล็อกก่อน (ticket 02)
- **เอกสารหลังชำระ (ใบเสร็จ/ใบแจ้งหนี้)** — `receipts` FROZEN — การออกเอกสารของมัดจำ/ค้างชำระยุ่งกับ roadmap "report templates + digital signature" — รอ design เงินจบจึง spec ได้
- **เก็บเงินหลายงวด** — ถ้ายอดชั้น B ออกแบบแล้วรองรับงวดย่อยตามธรรมชาติ จะดูว่า spec ขยายต่อหรือตัดทิ้ง

## Out of scope

- **Design column รายงาน Excel** — เป็นงานของ [excel-reports ticket 08](../excel-reports/tickets/08-payment-amount-channel-deposit.md) (แมปนั้นจัดการ — ปลดล็อกเมื่อ design ของแมปนี้ปิด)
- **Flow จองแทน user / จองให้องค์กร** — เป็นงานของแมป `organization-bookings` (แมปนี้แค่ส่งมอบ `payment_type` ให้ใช้)
- งานฝั่ง React frontend (`ku-home`) — ฝั่ง API เท่านั้น
- Payment gateway จริง / webhook HMAC (blocker #4 เดิม — ยังรอ gateway decision)
