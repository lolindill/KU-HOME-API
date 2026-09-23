---
label: wayfinder:research
type: AFK
title: เลือก library สร้าง Excel (.xlsx) บน Laravel 13 / PHP 8.3
status: closed (2026-09-22 — research)
assignee: maid
blocked-by: []
---

# 01: เลือก library สร้าง Excel (.xlsx) บน Laravel 13 / PHP 8.3

## Question

ควรใช้ library อะไรสร้างไฟล์ .xlsx ฝั่ง server ให้ตรงกับ template รายงาน (header ป้ายภาษาไทย, section header แบบ merged cell, number format จำนวนเงิน, print setup) บน Laravel 13 / PHP 8.3?

- เทียบอย่างน้อย: `phpoffice/phpspreadsheet` · `openspout/openspout` · `maatwebsite/excel` (Laravel wrapper ของ phpspreadsheet) — มี option อื่นที่เหมาะกว่าไหม
- เกณฑ์ตัดสิน:
  - styling ที่ template ต้องใช้: merged cells, bold/border/fill, number_format, freeze panes / print title rows, page setup (A4 landscape)
  - memory/speed เมื่อแถวเยอะ (รายงานรายเดือน/ปี)
  - สถานะ maintenance + compat กับ PHP 8.3 / Laravel 13
  - ความง่ายของการเขียน test (สร้างไฟล์จริงแล้ว assert cell ได้ไหม)
  - feature เกินจำเป็นไหม (formula/chart ยังไม่จำเป็นใน v1 — template เป็นตารางล้วน)
- composer.json ปัจจุบัน **ไม่มี** excel/pdf lib ใด ๆ — เลือกเพิ่มใหม่ทั้งหมด
- ปิดท้ายด้วย **คำแนะนำตัวเดียว + เหตุผล** พร้อมจด trade-off ให้ owner อ่าน (research ไม่ตัดสินแทน)

**Output:** `research/excel-library.md` + เขียน `## Resolution` ใน ticket นี้

## Resolution (2026-09-22 — research)

**แนะนำ `phpoffice/phpspreadsheet` 5.x ใช้ตรง ๆ (ไม่ห่อ maatwebsite/excel)** — รายละเอียดเต็มใน [`../research/excel-library.md`](../research/excel-library.md)

- ครอบ requirement 100% ที่ template ต้องใช้: `mergeCells()` (section header) · `NumberFormat::setFormatCode('#,##0')` · `freezePane()` · print title rows (`setRowsToRepeatAtTopByStartAndEnd`) · `PAPERSIZE_A4` + `ORIENTATION_LANDSCAPE` — เป็น candidate เดียวที่ print setup **มีเอกสารรองรับ**
- v5.10.0 (2026-09-17) แอกทีฟมาก, PHP `^8.2` (รอดทั้ง 8.3 และ 8.4 ในอนาคต), framework-agnostic
- Test ได้ง่าย: `IOFactory::load()` อ่านไฟล์ที่ generate กลับมา assert header ไทย/merged ranges/format codes ใน PHPUnit
- ⚠️ OpenSpout ตก: v5 ต้อง PHP 8.4+ (บน PHP 8.3 ติด v4 ซึ่งหยุด 2025-09) + ไม่มี print titles/page setup ใน docs
- maatwebsite/excel 4.x รองรับ Laravel 13 แล้ว เป็น runner-up — ถ้าอนาคตต้องการ queued/chunked exports ค่อยย้าย (ย้ายง่ายเพราะมัน wrap phpspreadsheet เหมือนกัน)
- Setup note: ต้องเปิด ext `gd`, `zip`, `mbstring`, `dom`, `xmlwriter` บน php.ini เครื่อง dev (Windows) และ prod
- Memory: ~1.6 KB/cell — report รายเดือน 5,000 แถว × 30 คอลัมน์ ≈ 240 MB ยังรับได้; >50k–100k แถวค่อยว่ากัน (จดไว้ใน fog "เพดานขนาด")
- **Decision ยังไม่ lock** — รอ owner sign-off ใน ticket 03 (สถาปัตยกรรม renderer)
