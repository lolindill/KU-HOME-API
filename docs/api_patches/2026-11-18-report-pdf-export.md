# 🖨️ Patch note — Report PDF export (`?format=pdf`)

> 2026-11-18 · branch `feature/report-pdf-export` · zero new route · backward compatible

## สิ่งที่เพิ่ม

Export รายงาน 15 ฉบับเป็น **PDF** ได้คู่กับ Excel เดิม ผ่าน query param บน route เดิมทุกเส้น:

```
GET /api/v1/reports/{slug}/export              → .xlsx (ค่า default ไม่เปลี่ยน)
GET /api/v1/reports/{slug}/export?format=pdf   → .pdf   (ใหม่)
```

- `{slug}` = ทั้ง 15 ตัวเดิม (`check-in`, `room-status`, `housekeeping`, …)
- สิทธิ์ / throttle 10,1 / filterRules (เพดาน 366 วัน) **คงเดิมทุกอย่าง**
- `?format=` อื่น → **422** `{"status":"error","message":"format รองรับเฉพาะ xlsx หรือ pdf เท่านั้นค่ะ"}` (ไม่ silent fallback)

## Headers (PDF)

```
Content-Type: application/pdf
Content-Disposition: attachment; filename=report.pdf; filename*=UTF-8''รายงาน...pdf
```

ชื่อไฟล์ไทยตามช่วง filter เหมือน xlsx เดิม แต่นามสกุล `.pdf`

## การเรนเดอร์

- Reuse `ExcelReportRenderer::render()` เดิมทุกอย่าง → PDF = "หน้า print" ของ xlsx เดียวกัน
  (A4 landscape, title แถว 1, filter แถว 2, header แถว 3, summary ท้ายตาราง bold)
- 🔁 ทุกหน้า PDF ซ้ำแถว 1–3 (title + filter + header) — ตั้ง `rowsToRepeatAtTop 1..3` เฉพาะเส้นทาง PDF (`renderForPdf()`) เพราะ HTML/PDF writer สร้าง `<thead>` เฉพาะช่วงที่เริ่มแถว 1 · Excel คง 3..3 เดิมเป๊ะ
- ฟอนต์ไทย **Sarabun** (SIL OFL) bundle ที่ `resources/fonts/` — ต้องมีสองไฟล์ `Sarabun-Regular.ttf` + `Sarabun-Bold.ttf` ติด deploy เสมอ
- `freezePane`/`AutoFilter` ไม่ปรากฏใน PDF (feature หน้าจอ) — ถือว่าถูกต้อง

## โค้ดที่แตะ

| ไฟล์ | การเปลี่ยนแปลง |
|---|---|
| `app/Services/ReportExcel/ThaiMpdfWriter.php` | 🆕 subclass `Writer\Pdf\Mpdf` — ฉีด config ฟอนต์ผ่าน `createExternalWriterInstance()` |
| `app/Services/ReportExcel/ExcelReportRenderer.php` | + `renderForPdf()` · + `streamPdfResponse()` · `filename()` รับ param `$extension` |
| `app/Http/Controllers/Api/V1/ReportController.php` | `exportReport()` อ่าน `?format=` → switch writer |
| `composer.json` / `composer.lock` | + `mpdf/mpdf ^8.3` (require ปกติ — ติด deploy main) |
| `resources/fonts/Sarabun-{Regular,Bold}.ttf` | 🆕 ฟอนต์ OFL (ห้ามลบ — PDF พังถ้าไม่มี) |
| `tests/Feature/ReportPdfExportTest.php` | 🆕 8 เคส (binary `%PDF` + ฟอนต์ + role + 422) |

## หมายเหตุ deploy (main)

- `composer install --no-dev` ต้องตามด้วย **ไฟล์ฟอนต์ 2 ตัวบน server** (track ใน git อยู่แล้ว)
- mPDF เขียน cache ที่ `storage/app/mpdf/` — ต้อง writable (Laravel storage ปกติ)
- รายละเอียดเต็ม: [`cline.md`](../../cline.md) หัวข้อ "🖨️ Report PDF export"
