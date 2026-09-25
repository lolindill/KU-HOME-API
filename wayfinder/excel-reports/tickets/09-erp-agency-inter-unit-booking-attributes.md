---
label: wayfinder:grilling
type: HITL
title: ERP/agency/inter-unit + booking attributes (special_request, complimentary)
status: open
assignee:
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
- **`complimentary_rooms` + `provisional`:** manager-report ใช้ — ต้องมี flag จริง หรือ re-define metric จากสิ่งที่มี (`source`, `pending`)? *(ส่วนนิยาม metric รวมถึง mode คุยใน [ticket 06](./06-manager-report-definition.md) — ใบนี้ตัดสินแค่ storage)*

**Precondition:** อ่าน audit §3.1, §3.4, §3.7, §3.15 + audit §4 gap 2, 3, 12 ก่อน

## 🆕 Update (2026-09-25 — premise หมดอายุบางส่วน จากแมป [`organization-bookings`](../../organization-bookings/map.md) ที่ปิดแล้ว)

Audit เดิมบอก "ไม่มี concept หน่วยงาน/ERP ในระบบเลย" — **ตอนนี้ไม่จริงแล้ว** ระบบมีแล้ว:

- ตาราง **`organizations`** (`erp` = string unique nullable logical FK + `is_active` toggle — stopgap เปลี่ยนไป organization-data API ได้)
- `bookings.organization_id` (FK restrict) + snapshot `customer_name`/`customer_phone`/`customer_email` · booking องค์กร = ส่ง `organize` (erp code) บน `POST /bookings` (admin) · filter `GET /bookings?organization_id` มีแล้ว
- **ผลต่อคำถามในใบนี้:** `agency_name`/`erp_code`/`guest_type` (หน่วยงาน/ทั่วไป) มีพื้นฐานให้ map ไปที่ `organizations.erp` + `organization_id` แล้ว — ใบนี้ตัดสินแค่ "รายงานอ่านจากสิ่งที่มีพอไหม หรือต้องเพิ่มอะไร" ไม่ใช่ออกแบบจากศูนย์
- **ยังไม่มีในระบบจริง** (คำถามเดิมคงอยู่): `is_inter_unit_transfer` · flag "ทำเรื่องแจ้งหนี้" · `special_request` · `comment` · `complimentary_rooms`/`provisional`
- หมายเหตุ: aggregation รายงานตามองค์กรถูกยกมาไว้ที่แมปนี้โดย ticket 08 ของแมป organization-bookings (filter/fields เท่านั้น)
