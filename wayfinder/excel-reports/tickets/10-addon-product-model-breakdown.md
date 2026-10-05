---
label: wayfinder:grilling
type: HITL
title: Addon product model — breakfast แยกชุด 100/200, extra-bed รายคืน, checklist แม่บ้าน
status: closed (2026-10-05 — grilling)
assignee: kevii
blocked-by: ["02-data-coverage-audit"]
---

# 10: Addon product model breakdown

## Question

Audit พบว่า addons เก็บเป็นตัวเลขเดียวต่อทั้ง stay (gap กลุ่ม 4, 5, 9) — โมเดลข้อมูลฝั่ง product ต้องละเอียดขึ้นแค่ไหน?

- **Breakfast ชุด 100 / ชุด 200:** `addons.breakfast` เป็น int เดียว — breakfast-report ต้องแยก `qty_set_100`/`qty_set_200` + filter `breakfast_type` → ต้องเก็บตามชุดตั้งแต่ตอนจอง (โครงสร้างใหม่บน addons: split เป็น 2 field? child rows? config-driven set types?)
- **Extra bed รายคืน (`beds_by_night` type `integer_by_date`):** `addons.extra_bed` เป็น int เดียวทั้ง stay — extra-bed-report ต้องการจำนวนเตียงต่อคืน (คอลัมน์ dynamic ตามวันที่) → เก็บ per-night ยังไง + ยอด fleet inventory (summary `total_inventory` = 35 เตียง) เก็บที่ไหน
- **ผลต่อ pricing:** ถ้าแยกชุด/รายคืน การคิดเงิน (`breakfast_price`, `extra_bed_price`, `room_amount`/`amount` ผ่าน `reprice()`) ต้อง conform อย่างไร — ห้ามทำ invariant `Σ amount == total_amount` พัง
- **`cleaning_check_1/2/3`:** housekeeping_tasks ไม่มี boolean checklist — หัวข้อตรวจคืออะไรบ้าง (template notes บอกว่ารอ owner ยืนยัน) เก็บ generic (JSON) หรือ 3 columns ตรง ๆ

**Precondition:** อ่าน audit §3.6, §3.9, §3.11 + audit §4 gap 4, 5, 9 ก่อน

> **📌 SRS v2 (2026-09-24):** REQ-016 ยืนยันของจริง — "เลือกแพ็กเกจอาหารเช้า (ระบุราคา **100/200 บาท**)" สำหรับจองแบบกลุ่ม (srs_room_booking_v2.pdf) — breakfast ชุด 100/200 ที่ถามไว้ด้านบนคือ requirement ตาม SRS ไม่ใช่แค่ตั้งสมมุติจาก template · ส่วน "ปัดเศษขึ้นหลักสิบ" ของ REQ-015/016 graduate ไปเป็น [ticket 07 ของแมป booking-payment-types](../booking-payment-types/tickets/07-round-up-to-tens.md) แล้ว (domain ยอดเงิน — ต้อง grill คู่กับยอด 2 ชั้น) · ปัจจุบัน `addons.breakfast` ยังเป็น int เดียว ยังไม่มี concept ชุด 100/200 ใน DB

## Resolution

(2026-10-05 — grilling กับ owner ตรง · AskUserQuestion 3 รอด 10 คำตอบ · evidence จากโค้ดจริง: `DiscountService::reprice()` chokepoint เดียว, 4 write paths คำนวณ addon ซ้ำ formula เดียวกัน, `GlobalRate::getPrices()`)

**🍳 Breakfast ชุด 100/200:**

- **Storage = 2 columns บน `addons`** — `breakfast_set_100` + `breakfast_set_200` (integer, แทน `breakfast` int เดิม) · migration: `breakfast` เดิม → `breakfast_set_200` (rate เดิม 200 ตรงกันพอดี)
- **เรทต่อชุด = global_rates 2 แถวใหม่** — code `breakfast_100` (100 บาท) / `breakfast_200` (200 บาท) แทนแถว `breakfast` เดิม — จุดบริหารเรทเดียวกับ addon อื่น, admin ปรับกลางได้ไม่ต้อง deploy
- **Pricing = × คืน (owner ยืนยันแก้ undercharge)** — `breakfast_total = (set_100 × เรท_100 + set_200 × เรท_200) × nights` · เดิมโค้ดคิด `qty × rate` ครั้งเดียวต่อ stay ทั้งที่ร้านอาหารเตรียมทุกคืนที่พัก
- **"วันที่รับประทานอาหาร" = เช้าวันถัดจากคืนนอน** — วันกิน D ∈ `(check_in, check_out]` (เช้าเช็คเอ้าท์นับ) — ตรง sample ชีตจริง: guest พัก 1 คืน (9/11→9/12) โผล่ในรายงานวันที่ 9/12 — ร้านอาหารใช้วันนี้เตรียมของ
- **Wire input:** canonical `breakfast_sets: {set_100, set_200}` + legacy `breakfast` (int) → normalize เป็น set_200 (pattern เดียวกับ `resolveExtraBed`/`resolveEarlyLate` — alias เก็บไว้ให้ frontend เดิม)

**🛏️ Extra bed รายคืน:**

- **Storage = JSON `extra_beds_by_night` บน `addons`** (แทน `extra_bed` int เดิม) — `{"2026-09-11": 1, "2026-09-12": 2}` · 1 row ต่อ booking_room คงเดิม · migration: int เดิม → flat map ทุกคืนของ stay (คงยอดเงินเดิม) · validate: คีย์ต้องเป็นคืนใน `[check_in, check_out)` + qty ≤ `room_types.max_extra_beds` ต่อคืน
- **Pricing = รายคืน** — `Σ qty รายคืน × เรท` (ต่างจากเดิม `qty เดียว × nights` เมื่อจำนวนรายคืนไม่เท่ากัน — ตรงชีตจริง เช่น 9/12=2 แต่ 9/13=1)
- **เรท = global_rates code `extra_bed` ต่อไป** — เจอ inconsistency ระหว่าง grill: โค้ดคิดเงินด้วย global_rates (500 flat ทุก type) แต่ `room_types.extra_bed_price` (500/600) ที่โชว์ใน availability/calendar **ไม่เคยถูกใช้คิดเงิน** — owner ตัดสิน: **global_rates เป็น pricing source ต่อไป** · `room_types.extra_bed_price` คงสถานะ display-only (จดหมายเหตุใน spec)
- **Fleet inventory 35 เตียง/คืน = config** — `reporting.extra_bed_fleet_size` (default 35) · summary `total_inventory` อ่านจาก config, `balance` = inventory − allocated (allocated รวมใน PHP — ข้อมูลระดับร้อย row ไม่หนัก) · ไม่ derive จาก `rooms.builtin_extra_beds` (เตียงในห้อง ≠ fleet เคลื่อนที่)
- **Wire input:** canonical `extra_beds_by_night` + legacy `extra_bed` (int) → server normalize เป็น flat map ทุกคืน

**🧮 Pricing conform (กฎร่วม 2 อัน):**

- แก้ที่ **`DiscountService::reprice()` chokepoint เดียว** — สูตรใหม่: `amount = room_amount − discount + extra_bed_total(รายคืน) + breakfast_total(× คืน) + early + late` · `RoundToTen::round()` ท้ายสุดคงเดิม · **invariant Σ booking_rooms.amount == total_amount คงเดิม** (total ไหลตาม)
- 4 write paths (createBooking / addRooms / update+batch / front-desk walk-in) ใช้ formula เดียวกัน — ตอน implement ดึงเป็น helper กลางเพื่อเลิกคำนวณซ้ำ 4 ที่
- ⚠️ ยอดเงินเปลี่ยนจากเดิมเฉพาะ booking ที่มี breakfast (× คืนเพิ่ม) — draft reprice ใหม่อัตโนมัติตอน implement (draft เกิน deadline ถูก GC อยู่แล้ว ไม่มีสถานะค้าง)

**🧹 Checklist แม่บ้าน (housekeeping-report-v2):**

- **เก็บจริง = 3 boolean columns บน `housekeeping_tasks`** — `cleaning_check_1/2/3` (nullable boolean, **PgBoolean cast ตาม convention**)
- **แม่บ้าน tick ผ่าน API update task** (flow เดิม unassigned→accepted→in_progress→done) · tick เป็นบันทึกประกอบ — **ไม่ผูกเงื่อนไขกับ done ใน v1** (ถ้าอนาคตอยากล็อก ค่อยเพิ่ม)
- **หัวข้อ label เก็บ config** (เริ่มต้น: ① ห้องน้ำ ② เครื่องนอน/ผ้า ③ พื้น+ขยะ — แก้ได้ไม่ต้อง migrate) · รายงานใช้ label จาก config เป็นหัวคอลัมน์ (ชีตต้นทาง G/H/I ไม่มีหัวข้อ — owner ยืนยันให้ระบบตั้งเอง)
- ห้องที่ไม่มี task ในวันรายงาน → 3 ช่องโชว์ว่าง (null)

**ผลต่อ template (ตอน implement):**

- `breakfast-report`: โครงตรงชีตอยู่แล้ว — row per (booking_room × วันกิน), วันกิน ∈ (check_in, check_out], qty จาก 2 columns ใหม่ · "x" ในชีต = null คงตีความเดิม (ไม่ได้ซื้อชุดนั้น) · filter `breakfast_type` ตัด row ที่ชุดนั้น qty = 0
- `extra-bed-report`: `beds_by_night` อ่านจาก JSON, night columns จาก filter range, `total_allocated` รวมใน PHP, `total_inventory` จาก config
- `housekeeping-report-v2`: 3 คอลัมน์อ่านจาก task ของห้องวันนั้น (nullable → ว่าง)

→ ลง spec หัวข้อ "addon data model + pricing" (ticket 07) — gap ที่เหลือของกลุ่ม product (supplies/charges/maintenance log) อยู่ [ticket 11](11-room-domain-new-tables.md) ต่อ
