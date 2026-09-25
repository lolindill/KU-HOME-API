# 06: Implement — migration + code + tests, suite เขียว

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **blocked-by:** [05-api-contract-all-flows](./05-api-contract-all-flows.md)
- **assignee:** zcode session (2026-09-25)

## Question

ลงมือเขียนโค้ดตาม resolution ของ tickets 01–05 — หนึ่ง session จบ:

- migration ตามที่ design ล็อก: `bookings.payment_type` (+ backfill `full` ให้ของเดิม), `booking_confirmations.amount` (integer baht), ชั้น B ตาม ticket 04 — boolean ใช้ `PgBoolean` เสมอ · ถ้า migration เก่ารูปทรงเปลี่ยนใหญ่ จด "Migration Required" ลง `cline.md`
- code: model casts + controllers (`BookingController`, `BookingConfirmationController`, front-desk ตาม design) + Form Requests + hook ยอดใน `transitionStatus()` (ห้าม bypass state machine / audit log)
- tests ครอบ: flow `full` regression ไม่พังเลย · `deposit` (ส่งสลิปมัดจำ → verify → เหลือยอดค้าง → เก็บส่วนที่เหลือ) · `deferred` (admin อนุมัติข้าม paid / guard สิทธิ์) · reject แล้วส่งสลิปใหม่ (row ใหม่ + ยอดค้างถูกต้อง) · backfill ของเดิม
- `php artisan test` เขียวทั้ง suite ก่อนปิด ticket
- จด design decision ลง `cline.md` (protocol ใน AGENTS.md) + อัปเดต `docs/api_guide.md` ตามที่ ticket 05 ระบุ + append บรรทัด decision ใน map

## ✅ Resolution (2026-09-25 — implement จบ 1 session · suite 540 เขียว)

- **Migration `2026_09_25_100000_add_payment_type_and_amount_ledger`:** `bookings.payment_type` (NOT NULL DEFAULT 'full' — DB default ครอบ backfill) + `bookings.deposit_amount` (integer baht nullable) + `booking_confirmations.amount` (integer baht nullable) · backfill ledger booking เดิม `paid|confirmed` → payments row = total (reference `legacy-backfill:{id}`) ผ่าน **`App\Support\LegacyPaymentBackfill`** (named class เพราะ migration anonymous — test เรียกซ้ำได้, idempotent) · ไม่ต้อง migrate:fresh
- **Ledger:** `payments` เขียนได้ 3 จุด — verify ใหม่ (reference = confirmation UUID, received_by = admin) / recordPayment / mock QR · `is_paid` = SUM(completed) ≥ total — hook อยู่ที่ controller (verify/recordPayment) **ไม่แตะ transitionStatus เลยบรรทัดเดียว** (regression contract ข้อ 2 ครบ)
- **Envelope 4 field** เป็น accessors/appends บน `Booking` (`paid_amount`, `outstanding_amount` appends · `deposit_amount` accessor ทับ column = effective · `expected_amount` สูตรรวม) + `BookingConfirmation.expected_amount` append สำหรับ pending dashboard
- **Endpoints:** POST /bookings (field เงิน → admin 403, cross-field 422) · **PUT /bookings/{id} ใหม่** (admin+system, draft only, 4 field + DiscountService reconcile) · confirm + `amount` required + **deferred block ก่อน state guard** · verify เขียน ledger + expected/claimed soft + `payment_recorded` · recordPayment min:1 + overpay ยอมรับ · updateStatus อนุมัติ deferred (draft → confirmed เดิม)
- **🛡️ Guards กัน ledger หาย:** CleanupExpiredDrafts ข้าม draft deferred + draft มี payments row (whereDoesntHave) — ขยายไป `destroyBooking` ด้วย (422) ตามเหตุผลเดียวกัน (การตัดสินใจของ session — จด cline.md ให้ owner ทบทวนได้)
- **deferred:** บล็อกสลิป 422 · ยกเว้น cleanup + ยึด slot ต่อผ่านเงื่อนไขเพิ่มใน `BookingRoom::scopeHoldingSlot()`
- **Tests:** `BookingPaymentTypeTest` ใหม่ 14 (full/deposit×3/403/deferred/PUT×3/reject-resubmit/backfill/cleanup×1/holdingSlot/expected_amount) + แก้ `BookingConfirmationTest`/`ImageTest` ให้ส่ง amount (breaking change ของ contract) · **`php artisan test` = 540 passed (1986 assertions) เขียวทั้ง suite** · Pint เรียบร้อย
- **Docs:** `cline.md` หัวข้อใหม่ "💳 Booking Payment Types" (จุดเขียน ledger 3 จุด + breaking change + guards) · `docs/api_guide.md` — TOC + POST /bookings + confirm + verify/reject/pending + updateStatus + recordPayment + state machine notes + หัวข้อรวม "💳 Payment types" (contract + PUT /bookings/{id} เต็มรูป)

