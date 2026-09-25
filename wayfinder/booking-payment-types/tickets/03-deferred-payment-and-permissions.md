# 03: ค้างชำระ (deferred) + สิทธิ์การใช้ — path บน state machine, deadline, เก็บเงินปลายทาง

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** [01-payment-type-enum-and-schema](./01-payment-type-enum-and-schema.md)
- **assignee:** kevii (claimed 2026-09-25)

## Question

Booking แบบค้างชำระ — ไม่ส่งสลิปตอนจอง อนุมัติไปก่อนแล้วเก็บเงินทีหลัง (owner: **org booking ใช้ type นี้เสมอ** — คำตอบใบนี้จะส่งมอบให้แมป `organization-bookings` ticket 04 ด้วย):

- **สิทธิ์:** ตั้ง `deferred` ได้ใคร — admin only (บน `POST /bookings` โหมด admin ของแมป organization-bookings) หรือ user ทั่วไปเลือกได้ด้วย — ถ้า admin only ต้อง guard ยังไง
- **State path:** อนุมัติแล้ว booking วิ่งยังไง — `draft → confirmed` ข้าม `paid` เลย (เหมือน walk-in เดิม)? หรือ `draft → paid` เพราะ "ผ่านการอนุมัติเชิงเอกสาร" — `is_paid` ตอนนี้เป็น true หรือ false
- **`payment_deadline` 24 ชม. / `CleanupExpiredDrafts`:** draft แบบค้างชำระโดนลบใน 24 ชม.ไหม — องค์กรรออนุมัติเอกสารอาจไม่ทัน (ประเด็นเดียวกับ org ticket 04 — ตัดสินที่นี่ทีเดียว)
- **เก็บเงินปลายทาง:** ใคร mark ว่าจ่ายครบแล้ว ผ่านอะไร — path เงินสดเดิม `FrontDeskController::recordPayment`? endpoint ใหม่? แล้ว transition ไปไหนเมื่อจ่ายครบ
- **เงื่อนไขปิด booking:** `complete` ได้ทั้งที่ยังค้างชำระไหม — หรือต้องจ่ายครบก่อนถึงปิดได้

⚠️ ยอดเงิน (ชั้น A/B) ตัดสินใน [ticket 04](./04-amounts-layer-a-and-b.md) — ticket นี้ล็อกกลไก/สิทธิ์/เงื่อนไข

## ✅ Resolution (2026-09-25 — grilling กับ owner ผ่าน AskUserQuestion)

1. **สิทธิ์:** ตัดสินแล้วใน [ticket 01](./01-payment-type-enum-and-schema.md) — **admin/system เท่านั้น** (input มี `payment_type` → ต้องเป็น admin, non-admin → 403) — กติกาเดียวกันครอบคลุม `deferred` โดยอัตโนมัติ · มอบให้แมป `organization-bookings` ticket 04 ใช้: org booking สร้างโดย admin และเป็น `deferred` เสมอ
2. **State path:** **`draft → confirmed` (ข้าม `paid`)** — ใช้ transition ที่มีอยู่แล้วใน `Booking::transitionStatus` (admin-only, walk-in ใช้อยู่ — ไม่ต้องแตะ machine) · "อนุมัติ = ยืนยันการเข้าพัก รอเก็บเงินภายหลัง" · `is_paid=false` จนกว่าจ่ายครบ
3. **Deadline/cleanup:** **`deferred` ยกเว้น `CleanupExpiredDrafts`** — draft ค้างชำระ**ไม่ถูก hard-delete อัตโนมัติ** รอ admin confirm หรือลบเอง (org รอเอกสารอนุมัติได้ไม่จำกัดเวลา — ประเด็นเดียวกับ org ticket 04 ปิดที่นี่แล้ว)
   - ⚠️ **Sub-decision (owner ยังไม่ได้ยืนยัน — ปิดตาม recommendation ของ session, flip ได้ก่อน implement ใน ticket 06):** draft deferred เกิน 15 นาที **ยังยึด slot ห้องต่อ** จนกว่า admin จะ confirm หรือลบ — เหตุผล: จุดประสงค์ของ deferred คือการันตีห้องให้องค์กรก่อนชำระ (ถ้าปล่อย slot การยกเว้น cleanup ก็ไร้ความหมาย) และ draft deferred ถูกสร้าง/ดูแลโดย admin เท่านั้น จึงมีคนรับผิดชอบเก็บกวาดเสมอ · implement ต้องเพิ่มเงื่อนไขทั้ง `BookingRoom::scopeHoldingSlot()` (ยกเว้น deferred) และ `CleanupExpiredDrafts`
4. **เก็บเงินปลายทาง:** ผ่าน **`FrontDeskController::recordPayment` เดิม** (admin/system) — องค์กรโอนมา → admin บันทึก → ครบยอด `is_paid=true`, สถานะคง `confirmed` ไม่มี transition ใหม่ (สม่ำเสมอกับการเก็บยอดค้างของมัดจำ — ticket 02)
5. **เงื่อนไขปิด:** **`complete` ได้ทั้งที่ยังค้างชำระ** — สม่ำเสมอกับ no-hard-guard ของ ticket 02 (org เบิกหลังเข้าพักได้ 1 เดือน) — ยอดค้างติดพวง booking จน admin เก็บครบ (`is_paid` ค้าง false ได้แม้ `complete`)

หมายเหตุ: ข้อความ "payment_deadline 24 ชม." ในตัว ticket เดิมเป็น fact ล้าสมัย (แก้ใน map Notes แล้ว — ปัจจุบัน 15 นาที ตาม REQ-008)
