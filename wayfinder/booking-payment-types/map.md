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
  - `bookings`: `total_amount` (integer baht) + `is_paid` + `payment_deadline` **15 นาที** จาก config `booking.payment_deadline_minutes` (`CleanupExpiredDrafts` กวาด **ทุก 5 นาที**; draft หมดเวลาไม่ยึด slot — REQ-008 2026-09-24 · *แก้ fact เดิม "24 ชม./02:00" ที่ล้าสมัย* — สังเกตโดย ticket 02, 2026-09-25) — `bookings.confirmation` unique = เลขที่ยืนยัน (**คนละเรื่อง**กับตาราง confirmations)
  - State machine เดิม: `draft → pending → paid → confirmed → complete` (+`verify_error` รอส่งสลิปใหม่) — `paid` ปัจจุบันหมายถึง "ชำระเต็มและ verify ผ่าน" — **ไม่มี concept ชำระบางส่วน**
  - `payments` มี `amount` (integer baht) อยู่แล้ว แต่เขียนจริงแค่ 2 จุด (mock QR `PaymentController::requestPayment` + เงินสด `FrontDeskController::recordPayment`) · `receipts` FROZEN · webhook ยัง `410 GONE`
  - สิทธิ์ flow เดิม: ส่งสลิป = เจ้าของ booking (booking ต้องอยู่ `draft|verify_error`); verify/reject = admin; เงินสดหน้าเคาน์เตอร์ = admin/system (`draft → paid`)
- **Standing decisions ระดับ effort (owner, 2026-09-24):**
  1. **Design + implement จบในแมป** (แบบ `organization-bookings` — ไม่ใช่ spec-only)
  2. **ยอดเงินรวมทั้ง 2 ชั้น** — ชั้น A: `booking_confirmations.amount` (ยอดที่แจ้งต่อครั้งส่ง) + ชั้น B: ภาพรวมจ่ายแล้ว/ค้างบน bookings
  3. **`payment_type` อยู่ระดับ booking** — owner ยืนยันโครงเดิมถูกต้อง: confirmation เป็นแค่ log ของการส่งสลิป ไม่ใช่ที่เก็บประเภทการชำระ
  4. **แมป `organization-bookings` ticket 04 รอ design ของแมปนี้ก่อน** (ค้างชำระ = payment type ที่ org booking ใช้เสมอ — owner)
  5. Default ของ booking ออนไลน์ทั่วไป = **เต็มจำนวน (full)** — flow เดิมต้อง regression เป็น 0%
- 🆕 **(2026-09-25) ticket 08 graduate จาก fog:** [ยกเลิก / no_show ของ booking มัดจำ·ค้างชำระ](./tickets/08-cancellation-and-deposit-refund.md) — flow หลักล็อกแล้ว (ticket 02) จึงถามได้ชัด · blocked-by ticket 04 (ต้องตัดสินบนโครงยอด 2 ชั้น)
- 🆕 **(2026-09-24) ticket 07 เพิ่มจาก gap ตรวจ SRS v2:** [ปัดเศษขึ้นหลักสิบ — REQ-015/016](./tickets/07-round-up-to-tens.md) (ปัดยอดไหน ตอนไหน เศษมาจากไหน) — blocked-by ticket 04 (ยอดเงิน 2 ชั้น) · ระบบปัจจุบันไม่มี rounding ที่ไหนเลย
- Related maps: `organization-bookings` (open — ticket 04 blocked cross-map รอแมปนี้), `excel-reports` (open — ticket 08 paused ปลดล็อกเมื่อ design แมปนี้ปิด)
- ไม่มี skill `grilling`/`domain-modeling`/`research` บนเครื่อง — grilling ถาม owner ตรง (AskUserQuestion), งานสำรวจใช้ Explore agent (precedent ku-sso)
- ก่อนเขียนโค้ดจริง: จด design decision ลง `cline.md` ตาม protocol ใน AGENTS.md
- ทำงาน ticket ละ session — เริ่มจาก frontier (ticket open, blocked-by ปลดครบ, ยังไม่มี assignee) — commit tracker ไปกับ branch ปัจจุบันเสมอ
- 🎯 **Frontier ปัจจุบัน:** [ticket 06](./tickets/06-implement-payment-types.md) (implement — **ถูก claim ทำอยู่** zcode session 2026-09-25) · [ticket 08](./tickets/08-cancellation-and-deposit-refund.md) (grilling — ปลดล็อกแล้ว) · [ticket 09](./tickets/09-implement-round-up-to-tens.md) (implement ปัดเศษ — รอ ticket 06 ปิดก่อน เพราะแตะ `reprice()` จุดเดียวกัน)

## Decisions so far

- [01: payment_type enum + schema บน bookings](./tickets/01-payment-type-enum-and-schema.md): column ใหม่บน `bookings` = `full|deposit|deferred` string · `NOT NULL DEFAULT 'full'` + backfill หมด (flow เดิม regression 0%) · ตั้งได้ admin เท่านั้น (POST /bookings มี field นี้ใน input → ต้อง admin, ไม่งั้น 403) · แก้ได้เฉพาะ draft ผ่าน endpoint ใหม่ `PUT /bookings/{id}` (admin-only) · ส่ง `payment_type` กลับทุก response ของ booking
- [02: มัดจำ (deposit) semantics](./tickets/02-deposit-semantics.md): ยอดมัดจำ admin กำหนดต่อ booking (default 50% — field shape รอ ticket 04) · `paid` = งวดปัจจุบันผ่านแล้ว (สลิปมัดจำ verify → paid → confirmed เหมือนเดิม แต่ is_paid ไม่ set — is_paid สงวนให้จ่ายครบ) · เก็บยอดค้างตั้งใจตอน check-in ผ่าน recordPayment แต่**ไม่มี hard guard** — check-in/out ได้แม้ยอดไม่ครบ (org เบิกหลังเข้าพักได้ถึง 1 เดือน) · payment_deadline เดียวกัน 15 นาทีทุก type
- [03: ค้างชำระ (deferred) + สิทธิ์](./tickets/03-deferred-payment-and-permissions.md): สิทธิ์ admin เท่านั้น (ตาม ticket 01 — org booking เป็น deferred เสมอ) · state path `draft → confirmed` ข้าม paid (transition เดิมของ walk-in — ไม่แตะ machine) · **deferred ยกเว้น CleanupExpiredDrafts** + ยึด slot จน admin ตัดสินใจ (sub-decision ปิดตาม recommendation — owner flip ได้ก่อน implement) · เก็บปลายทางผ่าน recordPayment → ครบยอด is_paid=true · complete ได้ทั้งที่ยังค้าง
- [04: ยอดเงิน 2 ชั้น](./tickets/04-amounts-layer-a-and-b.md): ชั้น A `booking_confirmations.amount` integer baht nullable (สลิปเก่า null) + required สลิปใหม่ · verify เทียบ expected/claimed แบบ soft admin ตัดสิน · **`payments` = ledger เดียวเงินที่เข้าจริง** (สลิป verify → เขียน payments row เพิ่ม · ห้ามเขียนนอก 3 จุด) · ชั้น B derive ล้วน: `paid_amount` = SUM(payments), `outstanding` = total − paid ไม่เก็บ column · `deposit_amount` baht ตายตัว nullable บน bookings (null = 50% จาก config) · `is_paid` = SUM ≥ total · backfill booking เดิม paid/confirmed = row เต็ม · ยอดทุกตัวส่งทุก response ทุก role · **excel-reports ticket 08 ปลดล็อก** (field ครบใน resolution)
- [05: API contract รวมทุก flow](./tickets/05-api-contract-all-flows.md): contract ฉบับใช้ implement ครบ 13 หัวข้อ — response envelope 4 field ทุก response (`deposit_amount` = **effective 50%**) · `PUT /bookings/{id}` 🆕 admin/draft รับ `payment_type`+`deposit_amount`+`discount_code`+`payment_deadline` · confirm + `amount` required และ **deferred บล็อกสลิป 422** · verify เขียน payments row + response expected/claimed · recordPayment **overpay ยอมรับ** + min:1 · `CleanupExpiredDrafts` ข้าม draft ที่มี payments row (กัน ledger หาย) · regression "ห้ามเปลี่ยน" 7 ข้อ + สารบัญ docs ที่ต้องอัปเดต · ส่งมอบ org-bookings (watch ต่อ)
- [07: ปัดเศษขึ้นหลักสิบ (REQ-015/016)](./tickets/07-round-up-to-tens.md): **กติกาเดียวทุก booking** — ยอดลูกค้าไม่มีทศนิยม มีเศษปัดขึ้นหลักสิบ (owner: "no decimal ปัดเศษขึ้น") · ปัดที่ `booking_rooms.amount` ใน `reprice()` **หลังลด** (ลดก่อน ปัดท้ายครั้งเดียว) ยอดรวมไหลตาม (invariant Σ คงอยู่) · normalize คืนเต็มเสมอ (`startOfDay` ก่อน `diffInDays` — ปิดช่อง Carbon 3 float) · มัดจำ default 50% ปัดสิบต่อ · ชั้น A ยอดแจ้งไม่บังคับปัด (soft admin) · implement → [ticket 09](./tickets/09-implement-round-up-to-tens.md) (blocked-by 06 — แตะ `reprice()` จุดเดียวกัน)

## Not yet specified

- **payment_channel (QR/เงินสด/บัตร) จะกลับมาไหม** — โดน drop ไปแล้ว (`2026_08_19_100000`) · design ยอดเงินปิดแล้ว (ticket 04): ledger แยกที่มาของเงินได้จากจุดเขียน (verify=สลิป / recordPayment=เงินสด / QR) โดยไม่ต้องกลับ column channel — คำถามที่เหลือ (จะมี column channel ชัด ๆ ไหม) ยกให้ [ticket 08 ของ excel-reports](../excel-reports/tickets/08-payment-amount-channel-deposit.md) ตัดสินในแมปของตัวเอง (ปลดล็อกแล้ว)
- **เอกสารหลังชำระ (ใบเสร็จ/ใบแจ้งหนี้)** — `receipts` FROZEN — design เงินปิดแล้ว (ticket 04) แต่การออกเอกสารยังผูกกับ roadmap "report templates + digital signature" ที่ยังไม่มีแมป/decision — ยัง spec ไม่ได้จนกว่า roadmap เอกสารจะชัด

## Out of scope

- **Design column รายงาน Excel** — เป็นงานของ [excel-reports ticket 08](../excel-reports/tickets/08-payment-amount-channel-deposit.md) (แมปนั้นจัดการ — ปลดล็อกเมื่อ design ของแมปนี้ปิด)
- **Flow จองแทน user / จองให้องค์กร** — เป็นงานของแมป `organization-bookings` (แมปนี้แค่ส่งมอบ `payment_type` ให้ใช้)
- งานฝั่ง React frontend (`ku-home`) — ฝั่ง API เท่านั้น
- Payment gateway จริง / webhook HMAC (blocker #4 เดิม — ยังรอ gateway decision)
