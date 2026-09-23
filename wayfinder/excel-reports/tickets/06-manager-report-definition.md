---
label: wayfinder:grilling
type: HITL
title: นิยาม manager-report — template ไม่มี columns เลย
status: open
assignee:
blocked-by: ["02-data-coverage-audit"]
---

# 06: นิยาม manager-report

## Question

`manager-report.json` ไม่มี `columns` (audit ยืนยันแล้ว) — layout จริงคือ `metrics(20 แถว) × periods(6 คอลัมน์: Day/MTD/YTD + LY equivalents)` มี filters `date`/`mode` (Complete/Week/Month)/`date_range` — รายงานผู้จัดการนิยามจริงคืออะไร?

- ต้นทาง Google Sheets หน้า "Status" มีอะไรบ้าง (owner เปิดชีตต้นทางได้: sheets id `1v8euwEZZMnsSHmJuV5ZDu3bRE5NI0TTplAW8o_1m3bw`)
- Metric 20 ตัวใน audit §3.15 — ตัวที่ derive ได้แล้ว (occupancy, arrivals/departures, no_show, OOO) ตรงตามชีตหรือไม่
- **`provisional`** ไม่มีสถานะตรงเครื่อง (ใกล้สุด `pending`/`draft`) — นิยามใหม่เป็นอะไร หรือตัดทิ้ง *(ส่วน storage ถ้าต้องมี flag → [ticket 09](./09-erp-agency-inter-unit-booking-attributes.md))*
- `mode` (Complete/Week/Month) หมายความว่าอะไร และ LY (last year) คำนวณยังไงเมื่อข้อมูลยังใหม่
- ถ้า owner ยังนิยามไม่ได้ → ปรับเป็น fog ของ map หรือตัดออกจาก v1 (จดเหตุผลใน Resolution + อัปเดต map)

**Precondition:** อ่าน audit (`../research/data-coverage-audit.md` §3.15) ก่อน — เห็น metric list + สิ่งที่ DB มีให้ใช้
