# Plan: F701 Daily Transactions Report (JSON + PDF) — KU HOME API

> 📅 Created: 2026-08-24 · Status: **PLANNED (ยังไม่ implement)**
> เอกสารแผนสำหรับ roadmap item "Generate & print report templates" (AGENTS.md) — ชิ้นแรกของ roadmap นี้
> ตอน implement เสร็จให้ย้ายไฟล์นี้ไป `docs/done_plan/` พร้อมอัปเดต status

---

## 0. คำตอบคำถาม mPDF/FPDI

ใช้ **mPDF 8.x** เพียงตัวเดียว — **ไม่ใช้ FPDI** เพราะ:

- FPDI มีไว้สำหรับ "ลาก PDF เดิมมาวางทับเป็น template/พื้นหลัง" — รายงานนี้ generate จาก data ทั้งหมด ไม่มีแบบฟอร์ม PDF เดิม
- FPDI ตัวฟรีอ่านได้เฉพาะ PDF ≤ v1.4 (เวอร์ชันสูงกว่าต้องซื้อ FPDI PDF-Parser addon)
- ถ้าอนาคตมีแบบฟอร์มทางการของมหาวิทยาลัยให้วางทับ ค่อย `composer require setasign/fpdi` เพิ่มภายหลังได้

เทียบกับ dompdf: mPDF render ภาษาไทย (สระบน/ล่าง + วรรณยุกต์) และตารางหลายคอลัมน์ได้ดีกว่า — เหมาะกับรายงานไทย A4 Landscape

---

## 1. โครงสร้างรายงาน (จาก requirement)

### 📋 Header / Metadata Box
- หัวข้อหลัก: **ใบรายงานการเงิน รายวัน**
- รหัสรายงาน: **F701 — Transactions Report (Brief)**
- หมายเหตุมุมขวาบน: `(* = Details apply to the first line of the multi-line transaction)`
- เงื่อนไขตัวกรอง พิมพ์ใน metadata box:
  - `Creation Date from {Start Date} ({Start Time}) until before {End Date} ({End Time})`
  - Staff, Room Type, Source (online/admin/line)
  - `Order report by Transaction Date`

### 📊 ตาราง 12 คอลัมน์ (F701 → KU HOME mapping)

| คอลัมน์ | ตัวอย่าง (PMS เดิม) | ความหมาย | Mapping ใน KU HOME API |
|---|---|---|---|
| Creation Date | `11 Feb 2026 08:11:49` | วัน-เวลาที่บันทึกรายการ | `payments.created_at` / `booking_confirmations.reviewed_at` |
| Staff | `KIDH001S` | ผู้ทำรายการ/ตรวจรับ | `payments.receiver` / `confirmations.reviewer` → `users.name` (ระบบไม่มี staff code column) |
| Code | `DDH` / `DPA` | รหัสประเภทธุรกรรม | `PAY` = payments.completed · `SLP` = confirmation verified (constant ใน service) |
| Doc. | `12499` | เลขที่เอกสาร/ใบเสร็จ | `payments.reference_number` ?? short-UUID 8 ตัวแรก — **ห้าม invent เลขใบเสร็จ** (receipts frozen) |
| Client Member | `00007650 คุณ ...` | รหัส+ชื่อลูกค้า | `booking.user.name` (+ ระบุ KU member ไหม) |
| Lines | `1` | ลำดับบรรทัดของรายการ | ลำดับ booking_rooms 1..n — บรรทัดแรกมี `*` และแสดงยอดเงิน |
| Reference | `INET Booking` | รายละเอียดอ้างอิง | `booking.source` (`online`/`admin`/`line`) |
| Booking | `9472.1` | เลขที่จอง + ลำดับห้อง | `{bookings.confirmation}.{line}` เช่น `202608-00001.2` |
| Unit Type | `STR, DTR, DKR` | ประเภทห้อง | `room_types.name_en` (ไม่มี code column — ใช้ชื่อเต็ม ไม่เพิ่ม schema) |
| Unit | `STR701` | เลขห้องจริง | `rooms.room_number` (แสดง `—` ถ้ายังไม่ assign ตอน check-in) |
| Exclusive | `800.00` | ยอดก่อนภาษี (THB) | satang → THB (`amount/100`, 2 ตำแหน่ง) — เฉพาะบรรทัดแรกของ transaction |
| Tax / Inclusive | `0.00` / `800.00` | ภาษี / ยอดรวม | **Tax = 0.00 เสมอ** (ระบบไม่มี VAT concept) · Inclusive = Exclusive |

### 📑 Footer
- ซ้าย: `KU Home` · กลาง: `Printed: {d/m/Y H:i}` · ขวา: `Page {PAGENO}/{nbpg}` (mPDF native placeholder)

---

## 2. Design Decisions (ต้องจดใน cline.md ก่อนเริ่ม coding ตามกติกา AGENTS.md)

1. **PDF lib = mPDF 8.x** — รองรับไทยดีสุด, ใช้โดยตรงไม่ต้องมี Laravel wrapper (construct ใน service class เอง)
2. **ฟอนต์ = Sarabun Regular + Bold (SIL OFL)** จาก Google Fonts → เก็บใน `resources/fonts/`
3. **Transaction sources = union 2 ทาง** — สำคัญ เพราะ flow ปัจจุบัน verify สลิป **ไม่ได้**สร้าง payments row:
   - `payments` ที่ `status=completed` (front-desk `recordPayment`) → type `PAY`
   - `booking_confirmations` ที่ `status=verified` (แอดมินตรวจสลิปผ่าน) → type `SLP`
   - timestamp ใช้ `payments.created_at` / `confirmations.reviewed_at` (วันที่เงิน "ได้รับการรับรอง")
4. **Role = admin เท่านั้น** + in-controller `$user->role !== 'admin'` re-check 403 (defense-in-depth pattern เดียวกับ `recordPayment`/`verify`) + `throttle:10,1`
5. **Tax = 0.00 เสมอ** — ไม่มี VAT ใน schema ทั้งระบบ (university dorm) — note ใน api_guide + template
6. **Doc. number ไม่ invent** — receipts table frozen อ่านอย่างเดียว ใช้ `reference_number` ?? short-UUID
7. **ยอดเงินจากเงินที่รับจริง** (`payments.amount`) — **ไม่คิดราคาย้อนหลัง** จาก rate ปัจจุบัน เพราะ `booking_rooms` ไม่เก็บราคาต่อห้องตอนจอง (rate ย้ายไป global_rates แล้ว คิดวันนี้จะผิดจากที่เก็บจริง)
8. **ไม่ exempt `RequireJsonAccept`** — frontend fetch PDF พร้อม Bearer token โดย Accept มี `*/*` หรือ `application/json` (ผ่าน middleware ได้) ส่วนเปิด URL ตรงใน browser ทำไม่ได้อยู่แล้วเพราะต้องส่ง Authorization header
9. **Blade ใช้เฉพาะเป็น template engine ของ PDF HTML** — ไม่มี web response ใดๆ (note ข้อยกเว้นนี้ใน cline.md เพราะโปรเจกต์ API-only)
10. **Read-only ทั้งหมด** — ไม่แต้ schema / state machine / audit trail / migration

---

## 3. Files to create/modify (เมื่อถึงเวลา implement)

### 3.1 Dependencies
- [ ] `composer require mpdf/mpdf`
- [ ] ดาวน์โหลด `Sarabun-Regular.ttf` + `Sarabun-Bold.ttf` → `resources/fonts/`
- [ ] สร้าง `storage/app/mpdf/` (mPDF tempDir — เพิ่มใน `.gitignore`)

### 3.2 Config — `config/reports.php` (ใหม่)
env-tunable ตาม pattern `config/allocation.php`:
- `font_dir` (default `resource_path('fonts')`)
- `pdf_temp_dir` (default `storage_path('app/mpdf')`)
- `default_per_page` (default 15 — เท่ากับ GET /bookings)
- `max_date_range_days` (default 31 — กัน PDF memory บวม)
- `max_pdf_rows` (default 2000 — ตัดพร้อมระบุใน report ว่าถูก truncate)

### 3.3 Service — `app/Services/Reports/TransactionsReportService.php` (ใหม่)
- `build(ReportTransactionsRequest): array{rows, summary, filters}` — query 2 sources แล้ว union เป็น transactions, eager load `booking.user`, `booking.bookingRooms.roomType`, `booking.bookingRooms.room`
- map เป็น F701 rows: บรรทัดแรกของ transaction แสดงยอด + `*`, บรรทัดถัดไปรายห้อง (ไม่มียอด)
- summary: `transaction_count`, `total_exclusive`, `total_tax`, `total_inclusive` (หน่วย THB 2 ตำแหน่ง)
- รองรับ filter: `start_date`/`end_date` (default = วันนี้ — รายงาน "รายวัน"), `staff_id`, `room_type_id`, `source`
- type code constant: `TYPE_PAYMENT = 'PAY'`, `TYPE_SLIP = 'SLP'`

### 3.4 Form Request — `app/Http/Requests/ReportTransactionsRequest.php` (ใหม่)
- `start_date`, `end_date` — `date` format, ตรวจ range ≤ `max_date_range_days` (422 ถ้าเกิน), default วันนี้ 00:00 → 23:59:59
- `staff_id`, `room_type_id` — `uuid` + `exists:users,id` / `exists:room_types,id`
- `source` — `in:online,admin,line`
- `per_page` — `integer 1–100` (default 15), `page`

### 3.5 Controller — `app/Http/Controllers/Api/V1/ReportController.php` (ใหม่)
- `transactions()` → JSON `{status, data: rows, summary, filters, pagination}` (pagination format เดียวกับ GET /bookings: `current_page/last_page/per_page/total`)
- `transactionsPdf()` → render `resources/views/reports/transactions.blade.php` ผ่าน mPDF (A4-L, font Sarabun) → streamed response `Content-Type: application/pdf`, `Content-Disposition: inline; filename="F701-transactions-{Ymd}.pdf"`
- ทั้งสอง method: in-controller role re-check + try/catch 500 แบบ `Log::error()` ไม่ leak `$e->getMessage()` (ตาม response shape มาตรฐาน)

### 3.6 Template — `resources/views/reports/transactions.blade.php` (ใหม่)
Layout F701 ตาม section 1: title + report code + metadata box (เงื่อนไข filter) + หมายเหตุ `*` + ตาราง 12 คอลัมน์ + footer 3 ส่วน (mPDF `SetHTMLFooter` + `{PAGENO}/{nbpg}`)

### 3.7 Routes — `routes/api.php` (แก้)
เพิ่มในกลุ่ม `role:admin` (ใต้ `auth:sanctum`):
```php
// 📊 Reports (F701) — admin only, read-only aggregates
Route::get('/reports/transactions', [ReportController::class, 'transactions'])
    ->middleware('throttle:10,1');
Route::get('/reports/transactions/pdf', [ReportController::class, 'transactionsPdf'])
    ->middleware('throttle:10,1');
```

### 3.8 Tests — `tests/Feature/ReportTest.php` (ใหม่)
Follow pattern `PaymentTest` (RefreshDatabase, `actingAsAdmin()`):
- [ ] admin เห็น rows ทั้ง type `PAY` + `SLP`, แปลง satang→THB ถูก, summary รวมถูก
- [ ] กรองวันที่ตัดรายการนอกช่วงออก / filter เกิน 31 วัน ได้ 422
- [ ] filter `staff_id` / `source` ทำงาน
- [ ] `staff`/`user` role ได้ 403, ไม่ login ได้ 401
- [ ] PDF endpoint: 200 + `Content-Type: application/pdf` + body ขึ้นต้น `%PDF`
- [ ] booking หลายห้อง → lines 2..n ไม่มียอดซ้ำ (ยอดอยู่บรรทัดแรก `*` เท่านั้น)

### 3.9 Docs
- [ ] `docs/api_guide.md` — section ใหม่ "Reports" (2 endpoints, filters, response ตัวอย่าง, หมายเหตุวิธี fetch PDF ผ่าน frontend)
- [ ] `cline.md` — entry ใหม่: เหตุผลเลือก mPDF + design decisions ทั้ง 10 ข้อ
- [ ] `AGENTS.md` — ย้าย "Generate & print report templates" จาก Planned → section จริง
- [ ] `postman/KU_HOME_API.postman_collection.json` — เพิ่ม 2 requests

---

## 4. Verification (เมื่อ implement)

1. `php artisan test --filter=ReportTest`
2. `php artisan test` (full suite เขียวทั้งชุด — ต้องไม่ทำอะไรพัง)
3. Manual: `php artisan serve` → login admin (`admin@kuhome.com` / `password123`) → curl JSON และ PDF → เปิด PDF ตรวจฟอนต์ไทย + layout landscape A4
4. `vendor/bin/pint --dirty`

---

## 5. Out of scope (แยกไว้ อย่าถลำไป)

- หน้า dashboard overview/stats (roadmap แยกต่างหาก — อย่าสับสนกับ DashboardController เดิมที่เป็น housekeeping)
- Digital signature, CSV export (frontend เอา JSON ไปทำเองได้), FPDI overlay แบบฟอร์มทางการ
- การเพิ่ม `code` column ให้ room_types หรือเลขที่เอกสารจริงแบบมี sequence (ถ้าวันหน้าอยากได้ ให้คิด atomic sequence ใหม่ ไม่ใช่ receipts)
