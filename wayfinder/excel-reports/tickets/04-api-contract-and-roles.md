---
label: wayfinder:grilling
type: HITL
title: API contract — endpoint, filter, สิทธิ์, วิธีส่งไฟล์
status: closed (2026-09-22 — grilling)
assignee: maid
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

## Resolution

(2026-09-22 — grilling กับ owner ตรง · AskUserQuestion)

- **Endpoint: route ต่อรายงาน (explicit) — ไม่ใช้ `{report-id}` generic** — 15 routes ชัดแยกใบ เช่น `GET /api/v1/reports/check-in/export?checkin_date=…&room_type=…` (slug ต่อรายงาน) — ตัดสินโดย owner เอง (agent แนะนำ generic แต่ owner เลือกแยก) · ทั้งหมดชี้ controller เดียว (มี method/branch ต่อรายงาน หรือ delegate เข้า `ReportData` ตามสถาปัตยกรรม Hybrid ของ ticket 03) · validate filter ต่อรายงานผ่าน `ReportData::filterRules()`
- **ส่งไฟล์: stream download ทันที (synchronous)** — สร้างใน memory แล้ว response ออก · `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` · ชื่อไฟล์ = ชื่อรายงานภาษาไทย + ช่วงวันที่ · **ไม่มีไฟล์ค้างบน disk → ไม่ต้อง cleanup job** (ต่างจากระบบรูป)
- **สิทธิ์: `admin` + `staff` เห็นทุกใบ · `housekeeping` เห็นเฉพาะ housekeeping-report + housekeeping-report-v2** — ลง `CheckRole` middleware ต่อ route ตามธรรมเนียม (ไม่มี Policy class)
- **Error contract ตาม shape เดิม:** 422 filter ไม่ผ่าน validate · 403 ไม่มีสิทธิ์ · 404 slug ไม่ตรง route · body `{"status":"error","message":…}`
- **เพดาน: จำกัดช่วงวันที่ ≤ 1 ปี ต่อการ export** + throttle กลุ่ม `10,1` (lookups) — ตาม default ที่ chart ไว้ owner ไม่ขยับ (fog ใน map ใบนี้จบ)

→ ลง spec หัวข้อ "API contract + สิทธิ์" (ticket 07)
