---
label: wayfinder:grilling
type: HITL
title: API contract — endpoint, filter, สิทธิ์, วิธีส่งไฟล์
status: open
assignee:
blocked-by: ["03-renderer-architecture"]
---

# 04: API contract + สิทธิ์

## Question

ฝั่ง API หน้าตาเป็นอย่างไร?

- Endpoint: `GET /api/v1/reports/{report-id}/export?format=xlsx&...filters` หรือรูปอื่น? — filter ต่อรายงาน map จาก template `filters` ยังไง (validate ต่อรายงาน?)
- ส่งไฟล์ยังไง: **stream download ทันที** (synchronous) หรือ **สร้างไฟล์เก็บ disk + คืน signed URL** (precedent images — อายุ 15 นาที)? — ผลกระทบกับ throttle (ลงกลุ่ม `throttle:10,1` lookups หรือแยก), ไฟล์ค้าง (cleanup ตามแบบ `app:cleanup-images`?)
- สิทธิ์: role ไหน export รายงานไหม — ใช้ `primary_users_th` จาก `_index.json` เป็นตัวตั้ง (admin เห็นหมด?) ด้วย `CheckRole` middleware ตามธรรมเนียม (ไม่มี Policy class)
- Error contract: 422 filter ไม่ถูก / 403 ไม่มีสิทธิ์ / 404 report id ไม่มี — ตอบตาม shape `{"status":"error",...}`
- ชื่อไฟล์ที่ดาวน์โหลด (ภาษาไทย? มีวันที่ช่วงรายงาน?) + `Content-Type` ที่ถูกต้อง (`xlsx` mime)
- เพดานขนาด: จำกัดช่วงวันที่ต่อการ export สูงสุดเท่าไร (กันรายงานย้อนหลายปี)
