---
label: wayfinder:grilling
type: HITL
title: สถาปัตยกรรม renderer — generic template-driven หรือ per-report class + template อยู่ที่ไหน
status: closed (2026-09-22 — grilling defaults ✅ owner sign-off ครบแล้ว ผ่าน grilling ใน ticket 04 วันเดียวกัน)
assignee: maid
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

## Resolution (2026-09-22 — grilling defaults)

> ⚠️ **สถานะการตัดสิน:** owner ไม่ตอบ AskUserQuestion (รวมรอบ charting เช้าวันเดียวกัน = 2 ครั้ง) — ทุกข้อล่างนี้คือ **best-judgment defaults ของ agent** ตาม precedent "charting defaults" ใน map · นายท่านเปลี่ยนได้ทุกเมื่อโดยเปิด ticket ใหม่อ้างใบนี้ — spec (ticket 07) จะติดธงข้อที่ยังรอ owner sign-off ไว้ให้ชัด

**D1 — Library: `phpoffice/phpspreadsheet` 5.x ใช้ตรง (ไม่ห่อ maatwebsite/excel)** — sign-off ข้อแนะนำจาก [ticket 01](./01-excel-library-choice.md) · เหตุผลย่อ: candidate เดียวที่เอกสารรองรับครบทุก styling ที่ template ต้องใช้ (mergeCells, number format, freeze panes, print titles, A4 landscape) + `IOFactory::load()` เขียน test ย้อนได้ · เปิด ext `gd`/`zip`/`mbstring`/`dom`/`xmlwriter` ทั้ง dev (php.ini Windows) และ prod

**D2 — สถาปัตยกรรม: Hybrid — engine กลาง template-driven + DataProvider ต่อรายงาน**

```
app/Services/ReportExcel/
  ExcelReportRenderer.php     ← engine กลาง: อ่าน template JSON + วางโครงหน้า + styling + print setup
  Contracts/ReportData.php    ← interface ต่อรายงาน (ดู D4)
  Data/CheckInReportData.php  ← 1 class ต่อ 1 รายงาน (15 classes) — query + normalize เป็นแถว
resources/report-templates/   ← template JSON (ดู D3)
```

- engine ทำสิ่งที่ซ้ำกันทั้ง 15 รายงาน (โครงหน้า, หัวคอลัมน์, number format ตาม `type`, merged section header, freeze panes, print setup) — ห้ามมี PHP วาง cell แบบ hard-code ใน Data class
- Data class ทำเฉพาะ query + รูปทรงข้อมูล — รายงานพิเศษ (`extra-bed` คอลัมน์ dynamic ตามวันที่, `occupancy` aggregate, `manager` แบบ matrix) ขยายผ่าน contract ใน D4 โดยไม่แตะ engine

**D3 — ที่อยู่ template: `resources/report-templates/*.json` ใน repo + ชีต truth เป็น upstream**

- copy 15 ไฟล์ + `_index.json` จาก `docs/report_docAndSample/report-templates/` มาที่ `resources/report-templates/` (track ใน git — แก้ปัญหา `/docs/*` gitignore ที่ทำให้ fresh clone ไม่มี template)
- **runtime source of truth = ไฟล์ใน repo** (prod ไม่พึ่งเน็ต/Google — ตามธรรมเนียม API self-contained) · **ชีต ["เอกสารระบบที่พัก_Document"](https://docs.google.com/spreadsheets/d/1v8euwEZZMnsSHmJuV5ZDu3bRE5NI0TTplAW8o_1m3bw/edit) = truth ต้นทางเชิงมนุษย์** — ถ้าเนื้อหาขัดกัน ชีต wins แล้ว re-convert + commit
- กระบวน sync (manual, จดใน [research/truth-snapshot/README.md](../research/truth-snapshot/README.md)): ชีตแก้ → re-export xlsx → อัปเดต JSON → commit พร้อม bump `extracted_at` ใน `_index.json` — ไม่ทำ auto-sync จาก Google API ใน v1

**D4 — Contract ของ `ReportData` (รวมจุด extend รายงานพิเศษ)**

```php
interface ReportData
{
    public function templateId(): string;                     // จับคู่ template JSON
    public function filterRules(): array;                     // Laravel validation ต่อรายงาน
    public function columns(array $filters): array;           // คอลัมน์ (dynamic ได้ — extra-bed ขยายตามวันที่)
    public function rows(array $filters): iterable;           // แถว = array keyed ด้วย column key (+ meta '_section' เมื่อมี sections)
    public function summary(array $filters): array;           // แถว/block สรุป (default [])
}
```

- โครงหน้า xlsx มาตรฐานเป็นหน้าที่ของ engine ตามลำดับ: แถวชื่อรายงาน → แถว filter/ช่วงวันที่ → header row → sections/แถวข้อมูล (section label = merged cell ตาม `notes` ของ template) → summary
- รายงาน matrix (`manager-report` metrics × periods): ยังไม่ fix ในใบนี้ — รอนิยามจาก [ticket 06](./06-manager-report-definition.md) แล้วค่อยขยาย contract (เช่น method เพิ่ม หรือ provider คืนโครงคอลัมน์ dynamic เอง)
- number format ต่อ `type` (`money_baht`, `date`, …) เป็น **config map ใน engine** — ค่าที่แสดงจริง (พ.ศ./ทศนิยม) เป็นของ [ticket 05](./05-formatting-print-conventions.md)

**ผลต่อ map:** [ticket 04](./04-api-contract-and-roles.md) ปลด block (ตัวถัดไปของสาย API) — เพดานขนาดไฟล์/ช่วงวันที่ยังเป็น fog ค้างใน map ตามเดิม

> ✅ **Owner sign-off (2026-09-22, ผ่าน grilling ตอนทำ ticket 04):** ยืนยัน D1–D4 ทั้งหมด — phpspreadsheet 5.x ใช้ตรง · Hybrid engine + ReportData · template ที่ `resources/report-templates/` — ธง "รอ sign-off" หมดอายุ
