---
label: wayfinder:grilling
type: HITL
title: สถาปัตยกรรม renderer — generic template-driven หรือ per-report class + template อยู่ที่ไหน
status: open
assignee:
blocked-by: ["01-excel-library-choice", "02-data-coverage-audit"]
---

# 03: สถาปัตยกรรม renderer + ที่อยู่ของ template

## Question

ออกแบบชั้น render Excel ยังไงให้รองรับรายงาน 15 แบบโดยไม่ duplicate โค้ด?

- **Generic template-driven renderer** (อ่าน template JSON → วาง header/sections/columns → data provider ต่อรายงานป้อนแถว) หรือ **per-report PHP class** สร้าง spreadsheet ตรง ๆ — หรือ hybrid?
- Template JSON อยู่ที่ไหนใน app ให้เป็น source of truth เดียว (⚠️ ต้นฉบับ `docs/report_docAndSample` ถูก gitignore — ถ้า runtime จะอ่าน ต้องมีสำเนาใน repo เช่น `resources/report-templates/` หรือแปลงเป็น PHP class/config? sync กับ Google Sheets ต้นทางยังไงต่อไป)
- โครงหน้า xlsx มาตรฐาน (แถว title → filter info → header row → sections/rows → summary) กำหนดเป็น contract ตรงไหน
- จุด extend ต่อรายงานพิเศษ: `extra-bed-report` (`beds_by_night` type `integer_by_date` — คอลัมน์แยกตามวันที่), `occupancy-report` (aggregate รายวัน) — ออกแบบ data-provider interface ยังไง

**Precondition:** อ่าน research ทั้งสองใบก่อน (lib + audit) — คุยกับ owner ด้วย AskUserQuestion
