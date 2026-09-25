# ดู/รายงาน booking ตามองค์กร — filter ใน admin booking index และความสัมพันธ์กับ excel-reports

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** [03-org-booking-shape-on-post-bookings](./03-org-booking-shape-on-post-bookings.md)
- **assignee:** (ว่าง)

## Question

(graduated จาก fog ของแผนที่ 2026-09-25 — schema ล็อกครบแล้วเมื่อ ticket 01 + 03 ปิด: bookings มี FK `organization_id` + snapshot `customer_name`/`customer_phone`/`customer_email`)

- **Filter ใน admin booking index:** ใช้ FK `organization_id` filter ตรง ๆ (`GET /bookings?organization_id=`) หรือ filter ด้วย erp code / search ตาม `customer_name` — ใช้ query param ชุดเดียวกับ index เดิมหรือแยก endpoint?
- **ขอบเขตคำว่า "รายงาน":** แค่ filter ใน index + response fields พอ หรือต้องมี aggregation (ยอดจอง/ยอดเงินต่อองค์กร) — ถ้าต้องมี ซ้อนทับกับแมป [`excel-reports`](../excel-reports/map.md) อย่างไร ใครเป็นเจ้าของส่วนไหน?
- **Response shape:** booking response ควร expose `organization_id` / ข้อมูล organizations (name, erp) ตอน org booking ไหม และ role ไหนเห็น?
