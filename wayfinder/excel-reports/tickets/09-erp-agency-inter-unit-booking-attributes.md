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
