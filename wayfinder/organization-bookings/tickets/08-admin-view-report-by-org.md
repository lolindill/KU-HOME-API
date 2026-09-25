# ดู/รายงาน booking ตามองค์กร — filter ใน admin booking index และความสัมพันธ์กับ excel-reports

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** [03-org-booking-shape-on-post-bookings](./03-org-booking-shape-on-post-bookings.md)
- **assignee:** zcode (2026-09-25 — grilling + implement ใน session เดียว)

## Question

(graduated จาก fog ของแผนที่ 2026-09-25 — schema ล็อกครบแล้วเมื่อ ticket 01 + 03 ปิด: bookings มี FK `organization_id` + snapshot `customer_name`/`customer_phone`/`customer_email`)

- **Filter ใน admin booking index:** ใช้ FK `organization_id` filter ตรง ๆ (`GET /bookings?organization_id=`) หรือ filter ด้วย erp code / search ตาม `customer_name` — ใช้ query param ชุดเดียวกับ index เดิมหรือแยก endpoint?
- **ขอบเขตคำว่า "รายงาน":** แค่ filter ใน index + response fields พอ หรือต้องมี aggregation (ยอดจอง/ยอดเงินต่อองค์กร) — ถ้าต้องมี ซ้อนทับกับแมป [`excel-reports`](../excel-reports/map.md) อย่างไร ใครเป็นเจ้าของส่วนไหน?
- **Response shape:** booking response ควร expose `organization_id` / ข้อมูล organizations (name, erp) ตอน org booking ไหม และ role ไหนเห็น?

## ✅ Resolution (2026-09-25 — grilled กับ owner ครบ 3 คำถาม ทุกข้อตามคำแนะนำ · implement จบใน session เดียวกัน)

1. **Filter = ขยาย `GET /bookings` เดิม ไม่เพิ่ม endpoint ใหม่** — query param `organization_id` (UUID ตรงตาม FK `organization_id`, admin เท่านั้น — ไม่ใช่ UUID → 422) + ขยาย `term` เดิมให้ค้น snapshot `customer_name` ด้วย (org booking/เฮดเปล่าไม่มี user ให้ `whereHas` จับ)
2. **ขอบเขต "รายงาน" = แค่ filter + response fields — ไม่มี aggregation ใน session นี้** — ยอดจอง/ยอดเงินต่อองค์กรฝากไว้ให้แมป [`excel-reports`](../excel-reports/map.md) เป็นเจ้าของแต่เพียงผู้เดียว (ไม่ซ้อนทับ)
3. **Response shape = admin เห็น object `organization`** (id, erp, name, is_active) eager-load คู่ `user` ทั้ง index และ `showById` เมื่อ booking ผูกองค์กร — `organization_id` + snapshot `customer_name`/`customer_phone`/`customer_email` serialize ตาม fillable เดิม · non-admin ไม่กระทบ (org booking ไม่มีเจ้าของ user จึงดูได้เฉพาะ admin อยู่แล้ว)

**Implement:** `BookingController::getBookings` (param + eager load + guard UUID) · `applyUserFilter` (customer_name ผ่าน `LOWER ... LIKE` cross-DB) · `showById` (eager load organization เมื่อ admin) · tests `OrgBookingTest` +4 เคส — suite **602 passed** (2 failed เป็นของงาน implement ticket 07 ค้างใน working tree อีก session · docs `api_guide.md` + `cline.md` อัปเดตแล้ว
