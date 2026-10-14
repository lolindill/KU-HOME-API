# 12: Grill — ค่าปรับ/ค่าเสียหาย/charges กรอกเอง บวกยอดรวมจริง (REQ-042/044)

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** kevii (claimed 2026-10-14)
- **summary:** Grill owner กลไก charges ต่อห้อง/ต่อ booking ที่บวกยอดจริงโดยไม่พัง ledger/money chokepoint
- **phase:** 4

## Question

Owner ล็อกแล้ว (2026-10-14): ค่าปรับ/ค่าเสียหายกรอกเอง (item/ราคา/จำนวน) ต่อห้องและต่อ booking ต้อง**บวกเข้ายอดรวมจริง** — ปัจจุบัน `additional_charges` ผูก `booking_id` อย่างเดียว และเป็น report-only ("รายงานล้วน — ยอดไม่เข้า booking") ส่วน addon เป็น field โครง fixed — ตัดสินใจ 4 ข้อนี้ให้ชัดก่อนลงมือ:

1. **ยอดรวมเปลี่ยนยังไงโดยไม่พัง ledger:** แตะ `total_amount` ตรงๆ จะกระทบ `is_paid = SUM(payments) ≥ total`, `deposit` 50%, `outstanding_amount` — ทางเลือก: (a) mutate `total_amount` จริง (b) คง total เดิม + charge เข้ายอดค้างชำระแยกหมวด (c) ใช้ pattern surcharge เดิมจาก `booking-payment-types` ticket 10 (ส่วนต่างเข้ายอดค้างชำระ ไม่แตะ total) — ข้อไหน และเกิด booking ที่ confirm แล้ว/เข้าพักแล้วด้วยหรือเปล่า
2. **ผูกห้อง:** REQ-042 ต้องการ "รายห้องพัก" — ยกระดับ `additional_charges` เป็น polymorphic (booking | booking_room) + flag กลายเป็น chargeable หรือตารางใหม่ (จดเหตุผล + อย่าทำ report 15 ฉบับพัง — `additional-charges`, `daily-financial`, `manager` ต้องยังตรง)
3. **REQ-044 addon free-text:** เพิ่ม free-text item บน `addons` หรือรวมเป็นกลไกเดียวกับ charges ข้อ 2 (อย่าสร้างกลไกคู่ขนานสามตัว — addon โครง fixed, additional_charges, และตัวใหม่)
4. **การชำระ:** หลัง charge เพิ่มหลังจ่ายแล้ว — ผู้ใช้ส่งสลิปเพิ่มผ่าน confirm flow เดิมได้ไหม หรือเก็บสดหน้าเคาน์เตอร์ (`recordPayment`) เท่านั้น

**⚠️ ข้อห้ามข้าม (AGENTS.md):** ห้าม bypass `transitionStatus()`/ledger chokepoints · เงิน = integer baht · PgBoolean · อย่า mutate `total_amount` โดยไม่ได้ตัดสินข้อ 1 ชัดๆ

**Done เมื่อ:** Resolution ตอบครบ 4 ข้อ + ยกตัวอย่าง flow จริง 1 เคส (เช่น แก้วแตก 250 บาท หลัง check-in ของ org booking deferred) — ticket 13 ห้ามเริ่มก่อนปิด ticket นี้

## Draft ข้อเสนอจาก agent (2026-10-14 — ⚠️ ยังไม่ใช่มติ owner)

> AskUserQuestion ถึง owner ยังไม่มีคำตอบ (session วิ่ง autonomous) — ด้านล่างเป็นข้อเสนอ + เหตุผลจากโค้ดจริง เตรียมไว้ให้ owner ตัดสินเร็ว · **ทุกข้อยังเป็นทางเลือก ห้ามถือเป็นมติ** — ปิด ticket ได้เมื่อ owner รับ/แก้ครบ 4 ข้อเท่านั้น

**ข้อเท็จจริงจากโค้ด (ตรวจ 2026-10-14):**

- Surcharge เดิม (booking-payment-types ticket 10) จริงๆ **mutate `total_amount` ผ่าน `reprice()`** ที่คิดจาก "ห้องล้วน" แล้วส่วนต่างไหลเข้า `outstanding_amount` (accessor `total − paid`) อัตโนมัติ — คำบรรยาย "ส่วนต่างเข้ายอดค้าง ไม่แตะ total" ใน ticket ไม่ตรงกับโค้ด · charges ไม่ใช่ห้อง จึงกลืนผ่าน reprice ตรงๆ ไม่ได้
- Invariant `Σ booking_rooms.amount == total_amount` ถือทั้งระบบ (reprice + reports + suite เขียวทั้งชุด)
- `is_paid` เขียนที่ 2 จุด: verify สลิป (`BookingController`) + `recordPayment` (`FrontDeskController`) ตามนิยาม `paid ≥ total` · surcharge มีกลไก reset `false` แล้วเมื่อ total โตเกิน paid
- `additional_charges` ปัจจุบัน: `booking_id` FK restrict · `charge_type` = damage|rental · CRUD admin+staff บันทึกได้ทุกเมื่อ · รายงานที่ query ตรงมีแค่ `AdditionalChargesReportData` (`daily-financial`/`manager` **ไม่แตะ**ตารางนี้ — กระทบทางอ้อมถ้านิยามยอดรวมเปลี่ยน)
- `addons` = ตาราง fixed ต่อ booking_room (breakfast/extra_bed/early/late + ราคา snapshot) ผ่าน `AddonPricing`

**ข้อ 1 — กลไกยอดรวม: แนะนำ (b) คง `total_amount` เดิม + นิยามปลายน้ำรวม charges**

- `total_amount` = ราคาห้องล้วนตลอด → invariant เดิมไม่พัง, suite/reports เดิมเขียวต่อ, reprice ไม่ต้องรู้จัก charges
- เพิ่ม accessor `charges_total` = Σ charges ของ booking → `outstanding_amount` = `max(0, total + charges_total − paid)` (แก้สูตรจุดเดียว) · `is_paid` = `paid ≥ total + charges_total` (ครอบคลุม reset เมื่อ charge ทำยอดโตเกิน paid — เทียบกลไก surcharge)
- `deposit_amount` (ceil total×50%) **ไม่ขยับ** — มัดจำผูกค่าห้อง ไม่รวมค่าเสียหายที่ยังไม่เกิด
- บวกจริง**ทุกสถานะ** (CRUD บันทึกได้ทุกเมื่ออยู่แล้ว — เคสหลักคือหลัง check-in)
- ทางเลือก (a) mutate total ตรง: invariant พังทั้งระบบ + reprice (chokepoint ราคาห้อง) ต้องแบก ledger ค่าเสียหายด้วย — ไม่แนะนำ

**ข้อ 2 — ผูกห้อง: แนะนำคง `booking_id` เดิม + เพิ่ม `booking_room_id` nullable (FK จริง)**

- Charge ต่อห้อง = ใส่ทั้งคู่ (validate booking_room ต้องเป็นลูกของ booking_id นั้น — 422 ถ้าไม่ใช่) · ต่อ booking = ใส่ `booking_id` เดียวเหมือนเดิม
- ได้ FK จริงทั้งสองด้าน (restrict ตามธรรมเนียม audit trail) + รายงานที่ query `booking_id` ยังตรงทันที (charge ต่อห้องก็มี booking_id เสมอ) — ต่างจาก polymorphic ที่เสีย FK จริงและต้อง join หา booking ใหม่ทั้งหมด · ไม่ใช่ตารางใหม่ (กลไกคู่ขนาน ขัดข้อห้ามข้อ 3)

**ข้อ 3 — REQ-044 free-text: แนะนำรวมเป็นกลไกเดียวกับ charges (ข้อ 2)**

- Free-text item ต่อห้อง = charge ที่ผูก `booking_room_id` (item_name/qty/price กรอกเอง) — **ไม่แตะ** `addons` fixed เลย ไม่เกิดกลไกที่สาม
- เปิดให้ owner เลือกเสริม: เพิ่ม `charge_type = 'addon'` เป็นค่าที่สามเพื่อให้รายงานแยกหมวดได้ (report filter ตาม type อยู่แล้ว) หรือใช้ damage|rental เดิมพอ

**ข้อ 4 — การชำระ: แนะนำ `recordPayment` เท่านั้นสำหรับ charge หลังชำระ**

- Charge ก่อน verify (draft/pending) → รวมในสลิปแรกได้เลย เพราะ expected/outstanding รวม charges แล้ว
- Charge หลังจ่าย/confirm → admin+staff เก็บผ่าน `recordPayment` (ตรง flow org deferred เดิม) · state machine + confirm flow + payments ledger 3 write points **ไม่ถูกแตะ**
- ทางเลือกเปิดให้ user ส่งสลิปเพิ่ม = ต้องเปิด transition ใหม่บน state machine (paid/confirmed → pending) — ใหญ่กว่าตัว charge เอง → ไม่แนะนำ

**เคสตรวจสอบ (แก้วแตก 250 · org booking deferred หลัง check-in):** staff `POST /additional-charges` {booking_id, booking_room_id ห้อง 201, "ค่าเสียหาย แก้วแตก", qty 1, price 250, charge_type damage} → `outstanding_amount` +250 ทันที (accessor คำนวณสด) · ตอน org มาเบิก/checkout → admin `recordPayment` 250 → ครบเมื่อ `paid ≥ total + charges` → `is_paid` true

**รายละเอียดที่ต้องล็อกต่อใน ticket 13 (ไม่ใช่คำถาม owner):** การแก้/ลบ charge หลัง `is_paid` เคยถูกตั้ง — is_paid เป็น column จึงต้องมีจุด recompute เมื่อ charge เปลี่ยน (ไม่งั้นลบ charge แล้ว paid ≥ ยอดใหม่แต่ is_paid ค้าง false)

## Resolution

(ยังว่าง — รอ grilling คู่กับ owner)
