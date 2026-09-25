# 05: API contract รวมทุก flow — request/response, form requests, regression ของ flow เดิม

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** [01-payment-type-enum-and-schema](./01-payment-type-enum-and-schema.md), [02-deposit-semantics](./02-deposit-semantics.md), [03-deferred-payment-and-permissions](./03-deferred-payment-and-permissions.md), [04-amounts-layer-a-and-b](./04-amounts-layer-a-and-b.md)
- **assignee:** kevii (claimed 2026-09-25)

## Question

Design ล็อกครบจาก tickets 01–04 แล้ว — รวบเป็น contract ฉบับใช้ implement ก่อนลงมือ:

- **Write paths ที่โดน:** `POST /bookings` (ส่ง `payment_type`? ใครส่งได้), `POST /bookings/{id}/confirm` (ส่ง `amount` ชั้น A), `PUT booking-confirmations/{id}/verify|reject` (admin เห็น expected vs claimed แล้ว response รูปร่างไหน), flow เก็บส่วนที่เหลือตามที่ ticket 02/03 ตัดสิน (endpoint เดิมแก้ / endpoint ใหม่)
- **Read paths:** `GET /bookings` show/index ส่ง `payment_type` + ยอด (จ่ายแล้ว/ค้าง/ต้องชำระตอนนี้) กลับยังไง — booking ของคนอื่น (admin index) เห็นเท่ากันไหม
- **Form Requests:** `StoreBookingRequest` แก้ / `ConfirmBookingRequest` เพิ่ม rule ใหม่ — validation message ไทย+emoji ตามธรรมเนียม
- **Throttle + error shape เดิมคงไว้:** `5,1` login/booking/confirm — ไม่มีอะไรเปลี่ยน
- **Regression contract:** booking `full` ต้องวิ่ง flow เดิม 100% (สลิป → verify → paid → confirmed) — ระบุ explicit ว่าอะไรบ้างที่ "ห้ามเปลี่ยน" เพื่อให้ implement เขียน test กันพัง
- **Docs:** สรุปว่าต้องอัปเดต `docs/api_guide.md` ส่วนไหนบ้างตอน implement (ticket 06)

## ✅ Resolution (2026-09-25 — grilling กับ owner ผ่าน AskUserQuestion · contract ฉบับใช้ implement)

> รวบ decision จาก tickets 01–04 + ปิด 4 ช่องว่างที่เหลือ (owner ตัดสินสด): ① deferred บล็อกสลิป ② overpay ยอมรับ ③ `deposit_amount` ใน response = effective ④ `PUT /bookings/{id}` รับ payment fields + `discount_code` + `payment_deadline`

### 0) Response envelope ของ booking — ใส่ทุก response ทุก role (ตาม ticket 04)

ทุก response ที่มี booking (index/show/write ทุกจุด) เพิ่ม 4 field เสมอ — คำนวณจาก ledger สดทั้งหมด (implement ผ่าน accessor/append บน `Booking` model ได้ ไม่ serialize เองที่ controller — เลือกวิธีเดียวทำที่เดียว):

```json
{
  "payment_type": "full|deposit|deferred",
  "deposit_amount": 6000,        // effective — type=deposit: column ?? ceil(total × 50%) (config booking.deposit_percent); type อื่น = null
  "paid_amount": 3000,           // SUM(payments.amount) — ledger เดียว
  "outstanding_amount": 3000     // max(0, total_amount − paid_amount) — clamp 0 เพื่อรองรับ overpay
}
```

- **`deposit_amount` = effective ตาม owner (2026-09-25)** — frontend อ่านตัวเดียวจบ ไม่ต้องรู้กติกา 50%
- ทุก role เห็นเท่ากัน (booking ของใครของมัน — admin index เห็นยอด booking ทุกใบเท่ากัน ไม่มี field ที่กันด้วย role)

### 1) Write path — `POST /bookings` (createBooking)

- รับ optional `payment_type` (`full|deposit|deferred`) + `deposit_amount` (integer baht ≥ 1) — **กติกาสิทธิ์เดิม ticket 01: ถ้า input มี field ใด field หนึ่งใน 2 นี้ → role ต้อง admin/system ไม่งั้น 403** · ไม่ส่ง = `full` + `deposit_amount` null (effective 50%)
- 422 ถ้าส่ง `deposit_amount` มาโดย `payment_type` ≠ `deposit` (กันความหมายคลุมเครือ — ยอดมัดจำมีความหมายเฉพาะ deposit)
- `payment_deadline` ตั้ง 15 นาทีเสมอทุก type (รวม deferred — column ยังมีค่า แค่ไม่ถูก cleanup + ยังยึด slot ตาม ticket 03)
- Response = booking ฉบับเต็ม (มี 4 field ใหม่ตามหัวข้อ 0)

### 2) Write path — `PUT /bookings/{id}` 🆕 (endpoint ใหม่ — admin แก้ draft)

- **Route:** `Route::put('/bookings/{id}', ...)` ใต้ group admin + `throttle:5,1` ตามธรรมเนียม booking writes · role guard `admin/system` (middleware + re-check ใน controller ตาม defense-in-depth)
- **State guard:** booking ต้อง `draft` เท่านั้น — หลังส่งสลิป/verify แล้ว frozen (422) ตาม ticket 01
- **รับ 4 field (owner ตัดสิน 2026-09-25):**
  | field | rule | หมายเหตุ |
  |---|---|---|
  | `payment_type` | nullable in: full,deposit,deferred | ไม่ส่ง = ไม่แตะค่าเดิม |
  | `deposit_amount` | nullable integer ≥ 1 | ส่ง `null` ชัด ๆ = revert ไป effective 50% · 422 ถ้า type ปัจจุบัน/ใหม่ ≠ deposit |
  | `discount_code` | nullable string max:50 | reuse กลไก `setDiscountCode`/`DiscountService` เดิม — reconcile ยอดให้ครบ; ส่งค่าว่าง = ลบโค้ด |
  | `payment_deadline` | nullable date after:now | ต่อ/ลดเวลาชำระ — ไม่ส่ง = คงเดิม |
- เปลี่ยน `discount_code` ต้อง reconcile `total_amount` เหมือน draft-edit เดิม (helper reconcile เดียวกัน) — `total_amount` เอง **ไม่รับ** จาก input (reprice โดยระบบเสมอ)
- Response = booking ฉบับเต็มพร้อมยอดใหม่

### 3) Write path — `POST /bookings/{id}/confirm` (ส่งสลิป)

- **เพิ่ม required `amount`** (integer baht ≥ 1) ใน `ConfirmBookingRequest` — สลิปใหม่มีการแจ้งยอดเสมอ (ticket 04) · slip_image/transfer_time เดิมคงไว้ · throttle `5,1` เดิม
- **🆕 deferred block (owner 2026-09-25):** ถ้า `payment_type = deferred` → **422** `"booking ค้างชำระ — รอแอดมินอนุมัติ ไม่รับสลิปค่ะนายท่าน"` — deferred มีช่องเดียวคือ admin confirm (หัวข้อ 8) หรือลบ draft · **ก่อน state guard เดิม** (จะ draft ก็ block — verify_error เกิดจาก deferred ไม่ได้อยู่แล้ว)
- Guard เดิมคงครบ: state `draft|verify_error`, payment_deadline (draft), 1-pending-max, ownership — **ไม่เปลี่ยน**
- Response 201 เพิ่ม: `amount` (echo), `expected_amount` (ยอดต้องชำระ ณ ตอนนี้ — สูตรหัวข้อ 9), `booking_status`, และยอด 4 field ของ booking

### 4) Write path — `PUT /booking-confirmations/{id}/verify` (admin)

- ภายใน transaction เดิม + **เขียน payments row ใหม่** (ticket 04): `amount` = confirmation.amount, `reference_number` = confirmation UUID, `received_by` = admin id, status `completed` · จุดเขียน payments = 3 จุดเท่านั้น (verify / recordPayment / QR — จด cline.md)
- คำนวณ `is_paid = SUM(payments) ≥ total_amount` หลังเขียน ledger — full จ่ายเต็ม → true เหมือนเดิม (regression 0%) · deposit → false ค้างจนกว่าจ่ายครบ
- Transition เดิมคงไว้: `pending → paid → confirmed` (branch legacy paid คงไว้) — **ไม่มี transition ใหม่**
- **Response เพิ่ม:** `expected_amount`, `claimed_amount` (= confirmation.amount — soft เทียบให้ admin เห็นคู่, ระบบไม่ reject), `payment_recorded` (payments row ที่เพิ่งเขียน), ยอด 4 field + `is_paid` + `booking_status`

### 5) Write path — `PUT /booking-confirmations/{id}/reject` (admin)

- ไม่มี payments row (ยังไม่รับเงิน) — booking → `verify_error` เดิมทุกอย่าง
- Response เพิ่มยอด 4 field ของ booking (ค่าไม่เปลี่ยน — ส่งเพื่อ consistency)

### 6) Read path — `GET /booking-confirmations/pending` (admin dashboard)

- แต่ละ row เพิ่ม `expected_amount` (จาก booking ของ row: full = outstanding · deposit = effective deposit) + `amount` (claimed ที่ user แจ้ง — มาจาก column ใหม่ อยู่ใน row อยู่แล้ว) — admin เห็นคู่เทียบตั้งแต่หน้า dashboard ไม่ต้องเปิด booking

### 7) Write path — `POST /{bookingId}/payment` (front-desk recordPayment)

- Validate: `amount` **min:1** (เลิก min:0 เดิม — 0 ไม่ใช่เหตุการณ์เงิน)
- **Overpay = ยอมรับ (owner 2026-09-25):** เขียน row ตามจริง · `is_paid` = SUM ≥ total · `outstanding` clamp 0 — ไม่มี reject (soft เหมือนทั้งระบบ, เผื่อค่าธรรมเนียมโอน)
- Multiple calls ตามธรรมชาติ ledger (1 ครั้ง = 1 row) · guard `! is_paid` idempotent เดิมคงไว้
- Transition เดิมคงไว้: ครบยอดแล้วถ้า booking `draft` → `paid` (เงินสดหน้าเคาน์เตอร์ — เดิม) · มัดจำ/ค้างชำระเก็บส่วนเหลือหลัง `confirmed` → **สถานะคง confirmed** ไม่มี transition ใหม่ (ticket 02/03)
- Response 201 เพิ่ม: `payment_type`, `paid_amount`, `outstanding_amount`, `deposit_amount` (effective) — คู่กับ `payment`, `booking_is_paid`, `booking_status` เดิม

### 8) Write path — deferred อนุมัติ (ไม่มี endpoint ใหม่)

- admin ใช้ **`PUT /bookings/update/{id}` (updateStatus เดิม)** ตั้ง `status=confirmed` — transition `draft → confirmed` มี role admin อยู่แล้วใน state machine (walk-in path) — **ไม่เขียน endpoint/state ใหม่** (ticket 03) · response ผ่านหัวข้อ 0 ได้ยอดครบ

### 9) สูตร `expected_amount` (ใช้ร่วมทั้ง confirm response / verify / pending list)

- booking `full`: `outstanding_amount`
- booking `deposit` งวดแรก (ยังไม่มีเงินเข้า): `deposit_amount` (effective)
- booking `deposit` งวดถัดไป (มีเงินเข้าบางส่วนแล้ว — เช่น resubmit หลัง reject ระหว่างมี recordPayment ค้าง): `outstanding_amount`
- booking `deferred`: n/a (บล็อกสลิปแล้ว)
- → ยุบเป็นสูตรเดียว: `paid_amount === 0 && payment_type === 'deposit' ? deposit_amount : outstanding_amount`

### 10) 🛡️ Contract guard กัน ledger หาย — `CleanupExpiredDrafts` ข้าม draft ที่มี payments row

- draft ที่มีเงินเข้าจริงแล้ว (recordPayment ระหว่าง draft — เช่น เก็บมัดจำสดหน้าเคาน์เตอร์ แต่ยังไม่ครบงวด) **ห้าม hard-delete เงียบ ๆ** — เงื่อนไข `whereDoesntHave('payments')` หรือเทียบเท่า · (draft deferred ไม่เกี่ยว — ถูกยกเว้น cleanup อยู่แล้ว ticket 03)

### 11) Form Requests + validation messages (ไทย + emoji ตามธรรมเนียม)

- `StoreBookingRequest`: + `payment_type` (`nullable|in:full,deposit,deferred`), `deposit_amount` (`nullable|integer|min:1`) + message ไทย · cross-field (deposit_amount ต้องคู่ deposit / สิทธิ์ 403) ตรวจใน controller (FormRequest ไม่รู้ role ได้แต่ authorize() รู้ — เลือกที่เดียวทำที่เดียว)
- `ConfirmBookingRequest`: + `amount` (`required|integer|min:1`)
- `StorePaymentRequest`: `amount` `min:0` → **`min:1`**
- `UpdateBookingPaymentRequest` 🆕 สำหรับ PUT /bookings/{id} (หัวข้อ 2)
- `ReviewConfirmationRequest`: ไม่เปลี่ยน

### 12) Regression contract — "ห้ามเปลี่ยน" (implement ใช้เขียน test กันพัง)

1. booking `full` (default ทุกทางที่ไม่ส่ง payment_type): สลิป → verify → `paid` → `confirmed` + `is_paid=true` — path/transition/response shape เดิม 100%
2. Transition rules ของ `Booking::transitionStatus()` + audit log `status_change_logs` — ไม่เพิ่ม/ไม่แก้ edge ใด
3. State guards เดิมของ confirm (draft|verify_error · deadline · 1-pending-max · ownership) และ verify/reject (admin ใน controller)
4. Throttle ทุกจุดเดิม (`5,1` login/booking/confirm · `10,1` lookups) — endpoint ใหม่ PUT /bookings/{id} ใช้ `5,1`
5. Error shape `{"status":"error","message":...}` + 406 middleware + UUID route constraints — ไม่แตะ
6. `receipts` FROZEN · webhook `410 GONE` · `payments` เขียนได้แค่ 3 จุด (verify/recordPayment/QR)
7. Slot-holding: `holdingSlot()` เดิม + ขยายตาม ticket 03 (draft deferred เกิน deadline ยังยึด slot จน admin confirm/ลบ) — ตรวจผ่าน test availability

### 13) Docs — `docs/api_guide.md` ส่วนที่ต้องอัปเดตตอน implement (ticket 06)

- หัวข้อ Booking: enum `payment_type` + กติกาสิทธิ์ (input มี field เงิน → admin) · response shape ใหม่ 4 field
- Endpoint ใหม่ `PUT /bookings/{id}` (ตาราง field หัวข้อ 2) + `POST /bookings/{id}/confirm` field `amount` required + deferred block
- Verify/Reject: expected vs claimed soft + payments row จาก verify + response ใหม่
- Front-desk `POST /{bookingId}/payment`: overpay semantics + multiple calls + response ใหม่
- State machine diagram: หมายเหตุใต้ `paid` (deposit: งวดปัจจุบันผ่าน — is_paid ตาม ledger) + deferred path `draft → confirmed` + ยกเว้น cleanup/ยึด slot
- หมายเหตุ breaking change: `POST /bookings/{id}/confirm` **ต้องส่ง `amount` ตั้งแต่ deploy นี้** (frontend ku-home ต้องอัปเดต)

### 📌 ส่งมอบให้แมป `organization-bookings` (ticket 04 — ปลดล็อกพร้อม implement)

Org booking สร้างโดย admin → ตั้ง `payment_type=deferred` ได้ผ่าน `POST /bookings` โหมด admin หรือ `PUT /bookings/{id}` (draft) · อนุมัติผ่าน updateStatus · เก็บเงินผ่าน recordPayment · owner บอกให้ "watch organization booking ด้วย" — ถ้า org ticket 04 ต้องการ field เพิ่มบน PUT /bookings/{id} (เช่น user_id จองแทน) ให้เคลียร์ที่ org map แล้วขยาย endpoint นี้ต่อ (ไม่ block ticket 06 ของแมปนี้)
