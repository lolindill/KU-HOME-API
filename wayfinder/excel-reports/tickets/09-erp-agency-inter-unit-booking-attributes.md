---
label: wayfinder:grilling
type: HITL
title: ERP/agency/inter-unit + booking attributes (special_request, complimentary)
status: closed (2026-10-05 — grilling)
assignee: kevii
blocked-by: ["02-data-coverage-audit"]
---

# 09: ERP/agency/inter-unit + booking-level attributes

## Question

Audit พบว่าไม่มี concept หน่วยงาน/ERP/โอนระหว่างหน่วยงานในระบบเลย (gap กลุ่ม 2, 3, 12) — ออกแบบ field ระดับ booking ยังไง?

- **โอนระหว่างหน่วยงาน:** flag `is_inter_unit_transfer` วางที่ไหน (booking? booking_room?) — check-in-report ใช้เป็น column + special-case (จ่าย 0 แต่อยู่ section Fully Paid)
- **`agency_name` / `erp_code`:** เก็บเป็น free text บน booking หรือตาราง agencies (FK)? — erp-transfer-report ใช้ทั้ง column + filter `agency_code` · deposit-report ใช้ filter `guest_type` (หน่วยงาน/ทั่วไป) ด้วย
- **"ทำเรื่องแจ้งหนี้" (invoice requested):** template erp note ขอ — เป็น flag/date บน booking?
- **`special_request` (text):** check-in/out report ต้องการ — ใส่ที่ booking หรือ booking_room?
- **`comment` free-text:** ของ erp-transfer-report — รวมกับ special_request ได้ไหม หรือแยก?
- **`complimentary_rooms` (+ `provisional`):** manager-report ใช้ — ✅ **[ticket 06](./06-manager-report-definition.md) ตัดสินแล้ว (2026-10-05): ต้องมี flag จริง `is_complimentary` บน `bookings`** (ส่วน `provisional` จบแล้ว — derive จาก state machine ไม่ต้อง storage) — ใบนี้เหลือ design รายละเอียดของ flag: ใครตั้งได้ (admin?), เงื่อนไข/เกณฑ์, ผลต่อยอดเงิน booking (ห้อง 0 บาท? กระทบ invariant `Σ amount == total_amount` ไหม — ประสานกับ ticket 08)

**Precondition:** อ่าน audit §3.1, §3.4, §3.7, §3.15 + audit §4 gap 2, 3, 12 ก่อน

## 🆕 Update (2026-09-25 — premise หมดอายุบางส่วน จากแมป [`organization-bookings`](../../organization-bookings/map.md) ที่ปิดแล้ว)

Audit เดิมบอก "ไม่มี concept หน่วยงาน/ERP ในระบบเลย" — **ตอนนี้ไม่จริงแล้ว** ระบบมีแล้ว:

- ตาราง **`organizations`** (`erp` = string unique nullable logical FK + `is_active` toggle — stopgap เปลี่ยนไป organization-data API ได้)
- `bookings.organization_id` (FK restrict) + snapshot `customer_name`/`customer_phone`/`customer_email` · booking องค์กร = ส่ง `organize` (erp code) บน `POST /bookings` (admin) · filter `GET /bookings?organization_id` มีแล้ว
- **ผลต่อคำถามในใบนี้:** `agency_name`/`erp_code`/`guest_type` (หน่วยงาน/ทั่วไป) มีพื้นฐานให้ map ไปที่ `organizations.erp` + `organization_id` แล้ว — ใบนี้ตัดสินแค่ "รายงานอ่านจากสิ่งที่มีพอไหม หรือต้องเพิ่มอะไร" ไม่ใช่ออกแบบจากศูนย์
- **ยังไม่มีในระบบจริง** (คำถามเดิมคงอยู่): `is_inter_unit_transfer` · flag "ทำเรื่องแจ้งหนี้" · `special_request` · `comment` · `complimentary_rooms`/`provisional`
- หมายเหตุ: aggregation รายงานตามองค์กรถูกยกมาไว้ที่แมปนี้โดย ticket 08 ของแมป organization-bookings (filter/fields เท่านั้น)

## Resolution

(2026-10-05 — grilling กับ owner ตรง · AskUserQuestion 2 รอบ + clarifying 2 จุด · ใช้ premise ใหม่จากแมป organization-bookings เป็น base)

- **`agency_name` / `erp_code` / `guest_type`:** จบตั้งแต่ update 2026-09-25 — อ่านจาก `organizations` ผ่าน `bookings.organization_id` (`agency_name` = `organizations.name`, `erp_code` = `organizations.erp`) ไม่เพิ่ม storage · filter `agency_code` ของ erp-transfer-report = `organizations.erp` · filter หน่วยงาน/ทั่วไป ของ deposit-report = `organization_id` null / not null
- **`is_inter_unit_transfer` → derive จาก org booking** — ไม่เพิ่ม flag: โอนระหว่างหน่วยงาน = booking ที่มี `organization_id` (ซึ่งเป็น `deferred` เสมอ ตาม design payment-types) · check-in-report: column โชว์ TRUE/FALSE จาก organization_id · special-case ตามต้นทาง: แถวนี้จัด section **Fully Paid** (ชำระแล้วโชว์ตามจริง อาจ 0 — เพราะหน่วยงานรับผิดชอบผ่าน ERP ไม่ใช่ค้างชำระของแขก) · erp-transfer-report = list org bookings ทั้งหมด
- **"ทำเรื่องแจ้งหนี้" → `invoice_requested_at`** (nullable timestamp) บน `bookings` — admin กด "ทำเรื่องแจ้งหนี้" เก็บเวลาด้วย · รายงาน erp โชว์วันที่ทำเรื่อง (null = ยังไม่ทำ)
- **`special_request`** → column (text nullable) **ระดับ `bookings`** — คำขอของแขกตอนจอง เป็นของทั้งการจอง ("ขอกาน้ำ" "ขอห้องชั้นไม่สูง") · check-in/out report โชว์ซ้ำทุกแถวห้องของ booking เดียวกัน
- **`comment` → แยก column** (text nullable) บน `bookings` — หมายเหตุฝั่ง admin/บัญชีสำหรับงาน ERP (ใส่ทีหลังได้) คนละเรื่องกับ special_request · รายงาน erp ใช้ comment
- **`is_complimentary`** → flag (boolean) ระดับ **`bookings`** (ยืนยันกับ owner ชัดเจน: tag อยู่ที่ booking) · **tag-only — ยอดเงิน booking คงเดิมทุกอย่าง** (booking 3,000 บาทติดป้ายแล้วยังเป็น 3,000 — ไม่แตะ pricing/reprice/invariant `Σ amount == total_amount`; ถ้าอยากหักเงินจริง admin ใช้ discount `set_room_price` เดิม) · ใครตั้ง = admin เท่านั้น · manager-report นับ "Complimentary Room" = booking_room ทั้งหมดของ booking ที่ flag
- **Fog "รายงานตามองค์กร" จบที่ใบนี้** — v1 มีมุมมององค์กรแค่ 2 รายงาน: **erp-transfer-report** (list org bookings + filter erp) และ **deposit-report** (filter หน่วยงาน/ทั่วไป) — รายงานอื่นไม่ break down ตามองค์กร ถ้ามี demand ภายหลังค่อยเปิดงานใหม่

**สรุป storage ใหม่ที่ใบนี้สั่ง:** `bookings` เพิ่ม 3 column — `invoice_requested_at` (timestamp nullable) · `special_request` (text nullable) · `comment` (text nullable) · `is_complimentary` (boolean) — รวม 4 column ระดับ booking ทั้งหมด ไม่มีตารางใหม่

→ ลง spec หัวข้อ "booking attributes" (ticket 07)
