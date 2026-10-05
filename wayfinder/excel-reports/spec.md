---
label: ready-for-agent
title: "Excel report export — 15 รายงาน (phpspreadsheet 5.x + Hybrid renderer + data model ใหม่)"
status: open
assignee:
blocked-by: []
---

# Spec: Excel report export — 15 รายงาน (map `excel-reports`)

> รวบ decisions จาก tickets 01–06, 08–11 + research 2 ใบ (2026-09-22 → 2026-10-05) — ฉบับเดียวพอ implement โดยไม่ตัดสินใหญ่อีก
> Truth source เชิงมนุษย์ = Google Sheets ["เอกสารระบบที่พัก_Document"](https://docs.google.com/spreadsheets/d/1v8euwEZZMnsSHmJuV5ZDu3bRE5NI0TTplAW8o_1m3bw/edit) — snapshot + แผนผังแท็บอยู่ [research/truth-snapshot/](research/truth-snapshot/README.md) · **ข้อยกเว้น sheet-wins ที่ owner อนุมัติ: `daily-financial-report` ฝั่ง payment_channel (ดู §6)**

## Problem Statement

ที่พักต้องการไฟล์ Excel (.xlsx) ของรายงานทั้ง 15 ฉบับตาม template ใน Google Sheets ต้นทาง แต่ระบบปัจจุบัน (1) ไม่มี lib สร้าง Excel และไม่มีชั้น render รายงานเลย (2) audit พบว่ารายงาน 11 ฉบับขาด field บางส่วนและ 3 ฉบับไม่มีตารางรองรับเลย — เจ็บสุดคือ slip flow ไม่เคยเก็บยอดเงิน, ไม่มี concept มัดจำ, `payment_channel` โดน drop ไปแล้ว (3) manager-report ไม่มีนิยาม (template ไม่มี columns) (4) addon เก็บตัวเลขเดียวต่อทั้ง stay ทำ breakfast แยกชุด 100/200 และ extra-bed รายคืนไม่ได้ — design ที่จำเป็นทั้งหมดปิดแล้วใน tickets ของแมปนี้ + แมปพี่เลี้ยง 3 ใบ ([booking-payment-types](../booking-payment-types/map.md) · [organization-bookings](../organization-bookings/map.md) · [room-state-periods](../room-state-periods/map.md) — landed ทั้งหมด)

## Solution

เพิ่ม **phpoffice/phpspreadsheet 5.x** (ใช้ตรง) + **ชั้น report แบบ Hybrid**: engine กลาง template-driven ตัวเดียววางโครงหน้า/สไตล์/print setup จาก template JSON ที่ `resources/report-templates/` + **`ReportData` interface ต่อรายงาน 15 classes** ทำ query อย่างเดียว · API = **15 explicit routes** `GET /api/v1/reports/{slug}/export` stream ไฟล์ทันที (admin+staff ทุกใบ, housekeeping เฉพาะ 2 ใบแม่บ้าน) · ปิด data gap ด้วย **migration pack เดียว**: `bookings` +4 column, `addons` reshape (breakfast 2 ชุด / extra-bed JSON รายคืน), `global_rates` +2 code, `housekeeping_tasks` +3 boolean checklist, `room_state_periods` +2 column repair log, ตารางใหม่ `additional_charges` — ไม่แตะ invariant การเงินเดิม

---

## 1. Implementation Decisions — library + renderer + template

### 1.1 Library (ticket 01 — research + owner sign-off ผ่าน ticket 03)

- **`phpoffice/phpspreadsheet` 5.x ใช้ตรง ๆ — ไม่ห่อ maatwebsite/excel** (candidate เดียวที่เอกสารรองรับครบ: mergeCells, number format, freezePane, print title rows, `PAPERSIZE_A4`+`ORIENTATION_LANDSCAPE` · `IOFactory::load()` อ่านไฟล์ที่ generate ย้อนเขียน test ได้ · OpenSpout ตกเพราะ v5 ต้อง PHP 8.4+)
- ต้องเปิด PHP ext: `gd`, `zip`, `mbstring`, `dom`, `xmlwriter` (dev php.ini Windows + prod)
- Memory ~1.6 KB/cell — เพดานช่วงวันที่ ≤ 1 ปี (§4) ทำให้ไฟล์ไม่ใหญ่ · ถ้าอนาคตเจอรายงาน >50k แถวค่อยกลับมาคุย cache/queue (จดใน map แล้ว)

### 1.2 Renderer architecture — Hybrid (ticket 03, owner sign-off ครบ)

```
app/Services/ReportExcel/
  ExcelReportRenderer.php     ← engine กลาง: อ่าน template JSON + วางโครงหน้า + styling + print setup
  Contracts/ReportData.php    ← interface ต่อรายงาน
  Data/{Report}Data.php       ← 1 class ต่อ 1 รายงาน (15 classes) — query + normalize เป็นแถว
resources/report-templates/   ← template JSON 15 ไฟล์ + _index.json (track ใน git)
```

- **engine ห้ามมี business query · Data class ห้ามวาง cell/styling เอง** (ห้าม PHP hard-code cell ใน Data class)
- Contract:

```php
interface ReportData
{
    public function templateId(): string;               // จับคู่ template JSON
    public function filterRules(): array;               // Laravel validation ต่อรายงาน (รวมเพดานช่วงวันที่ ≤ 1 ปี)
    public function columns(array $filters): array;     // คอลัมน์ — dynamic ได้ (extra-bed ขยายตามวันที่)
    public function rows(array $filters): iterable;     // แถว keyed ด้วย column key (+ meta '_section' เมื่อมี sections)
    public function summary(array $filters): array;     // แถวรวมท้ายตาราง (default [])
}
```

- โครงหน้า xlsx มาตรฐานเป็นหน้าที่ของ engine ตามลำดับ: แถวชื่อรายงาน → แถว filter/ช่วงวันที่ → header row → sections/แถวข้อมูล (section label = แถว bold ธรรมดา — ไม่ merge ตาม §5) → summary
- รายงานพิเศษขยายผ่าน contract โดยไม่แตะ engine: `extra-bed` (คอลัมน์ dynamic ตามคืนใน filter range), `occupancy` (aggregate รายวัน), `manager` (matrix metrics×periods — columns() คืนโครงคอลัมน์ 6 คอลัมน์เอง)
- number format ต่อ `type` (`money_baht`, `date`, …) = **config map ใน engine** — ค่า format จริงตาม §5

### 1.3 ที่อยู่ template + การ sync (ticket 03)

- copy 15 ไฟล์ + `_index.json` จาก `docs/report_docAndSample/report-templates/` → **`resources/report-templates/`** (แก้ปัญหา `/docs/*` gitignore — fresh clone ต้องมี template ครบ)
- **runtime source of truth = ไฟล์ใน repo** (prod ไม่พึ่งเน็ต/Google) · ชีต = truth ต้นทางเชิงมนุษย์ ถ้าขัดกันชีต wins แล้ว re-convert + commit (ยกเว้นที่ §6 owner ตัดสิน "ระบบ wins")
- sync เป็น **manual**: ชีตแก้ → re-export → อัปเดต JSON → commit พร้อม bump `extracted_at` ใน `_index.json` — ไม่ทำ auto-sync จาก Google API ใน v1

## 2. Data model changes (migration pack เดียว — tickets 08–11)

> Convention บังคับ: money = **integer บาท** · boolean ทุก column ใหม่ = **`PgBoolean` cast** · UUID PK · ห้ามเขียน `->status =` ข้าม chokepoint · วันที่เก็บ ISO-8601 ตลอด (format พ.ศ. เฉพาะตอน render)

### 2.1 `bookings` — เพิ่ม 4 column (ticket 09)

| column | type | ความหมาย |
|---|---|---|
| `invoice_requested_at` | timestamp nullable | เวลา admin กด "ทำเรื่องแจ้งหนี้" (รายงาน erp โชว์วันที่, null = ยังไม่ทำ) |
| `special_request` | text nullable | คำขอของแขกตอนจอง — ระดับ booking ทั้งการจอง (check-in/out report โชว์ซ้ำทุกแถวห้องของ booking เดียวกัน) |
| `comment` | text nullable | หมายเหตุฝั่ง admin/บัญชีงาน ERP — **แยกจาก special_request** |
| `is_complimentary` | boolean (PgBoolean, default false) | tag-only — **ยอดเงิน booking คงเดิมทุกอย่าง** (ไม่แตะ pricing/reprice/invariant `Σ amount == total_amount`; อยากหักเงินจริงใช้ discount `set_room_price` เดิม) · ตั้งได้ **admin เท่านั้น** |

- เขียนผ่าน admin booking path เดิม (ขยาย validation admin-only — user ปกติห้ามส่ง field เหล่านี้) · ไม่มีตารางใหม่

### 2.2 `addons` — reshape ให้รองรับรายคืน/แยกชุด (ticket 10)

| เดิม | ใหม่ | หมายเหตุ |
|---|---|---|
| `breakfast` (int เดียวทั้ง stay) | **`breakfast_set_100` + `breakfast_set_200`** (integer, default 0) | data migration: `breakfast` เดิม → `breakfast_set_200` (เรทเดิม 200 ตรงกันพอดี) |
| `extra_bed` (int เดียวทั้ง stay) | **`extra_beds_by_night`** (JSON) เช่น `{"2026-09-11": 1, "2026-09-12": 2}` | data migration: int เดิม → flat map **ทุกคืนของ stay** (คงยอดเงินเดิม) |

- validate `extra_beds_by_night`: คีย์ต้องเป็นคืนใน `[check_in, check_out)` + qty ต่อคืน ≤ `room_types.max_extra_beds`
- column snapshot `breakfast_price`/`extra_bed_price`/`early_*`/`late_*` คงเดิม (reprice คำนวณใหม่)
- **Wire input (canonical + legacy alias ตาม pattern `resolveExtraBed`/`resolveEarlyLate` เดิม):** canonical `breakfast_sets: {set_100, set_200}` — legacy `breakfast` (int) → normalize เป็น `set_200` · canonical `extra_beds_by_night` — legacy `extra_bed` (int) → server normalize เป็น flat map ทุกคืน

### 2.3 `global_rates` — 2 แถวใหม่แทนแถวเดิม (ticket 10)

- เพิ่ม code **`breakfast_100`** (100 บาท) + **`breakfast_200`** (200 บาท) — แทน code `breakfast` เดิม (อัปเดต `GlobalRateSeeder` ด้วย) · admin ปรับเรทกลางได้ไม่ต้อง deploy
- code `extra_bed` คงเดิมเป็น **pricing source ของ extra-bed** — เจอ inconsistency ระหว่าง grill: `room_types.extra_bed_price` (โชว์ใน availability/calendar) **ไม่เคยถูกใช้คิดเงิน** → owner ตัดสิน global_rates ชนะ, `extra_bed_price` คงสถานะ **display-only** (จดหมายเหตุใน api_guide ตอน implement)

### 2.4 `housekeeping_tasks` — checklist แม่บ้าน (ticket 10)

- เพิ่ม **`cleaning_check_1/2/3`** (nullable boolean × 3, **PgBoolean**)
- แม่บ้าน tick ผ่าน API update task เดิม (flow `unassigned→accepted→in_progress→done` ไม่เปลี่ยน) — tick เป็นบันทึกประกอบ **ไม่ผูกเงื่อนไขกับ done** ใน v1
- หัวข้อ label เก็บ **config** (default: ① ห้องน้ำ ② เครื่องนอน/ผ้า ③ พื้น+ขยะ — แก้ได้ไม่ต้อง migrate) · รายงาน v2 ใช้ label จาก config เป็นหัวคอลัมน์ (ชีตต้นทาง G/H/I ไม่มีหัวข้อ — owner ยืนยันให้ระบบตั้งเอง)

### 2.5 `room_state_periods` — repair log ไม่มีตารางใหม่ (ticket 11)

- เพิ่ม **`work_type`** (enum string `ไฟฟ้า|ประปา|งานระบบ` — ตามชีต truth) + **`repair_detail`** (text) — nullable ทั้งคู่ ใช้เฉพาะ kind=maintenance
- นิยามรายงาน: วันที่แจ้งซ่อม = `start_date` · วันที่แก้ไขเสร็จ = `end_date` (period เปิดปลาย = ยังไม่เสร็จ แสดง "-") · duration = end − start **derive** · แถวรายงาน = maintenance period ที่ `start_date` อยู่ในช่วง filter + filter `work_type`
- CRUD ใช้ route periods ที่ landed แล้ว (`GET/POST /rooms/{roomId}/periods` + PATCH/DELETE — `routes/api.php:237-248`) · ไม่แตะ chokepoint ใหม่

### 2.6 ตารางใหม่ `additional_charges` (ticket 11)

- Columns: `booking_id` FK restrict · `transaction_date` · `item_code` (nullable free text — ชีต sample เป็น placeholder) · `item_name` · `qty` · `unit` · `price` (**integer บาท**) · `charge_type` (enum `damage` ค่าเสียหาย | `rental` ค่ายืม) · `recorded_by` → users · timestamps
- **Ledger รายงานล้วน — ยอดไม่เข้า booking**: invariant `Σ booking_rooms.amount == total_amount` + ชั้น A/B ของแมป booking-payment-types ไม่ถูกแตะ · ไม่ไหลเข้า `payments` (ledger นั้นนิยาม "เงินของ booking") — เก็บเงินสดที่เคาน์เตอร์แยกจาก booking · รายงานการเงินที่ต้องการยอดค่าปรับ list จากตารางนี้แยกหมวดตอน implement
- **Write API ใหม่ (CRUD เล็ก):** `POST/PATCH/DELETE /api/v1/additional-charges` — บันทึกอิสระทุกเมื่อ ไม่ผูก flow checked_out · สิทธิ์ admin + staff *(⚠️ default จาก agent — รอ owner sign-off ดู §9)*

### 2.7 Config ใหม่

- `reporting.extra_bed_fleet_size` (default 35) — summary `total_inventory` ของ extra-bed-report อ่านจาก config (**ไม่** derive จาก `rooms.builtin_extra_beds` — เตียงในห้อง ≠ fleet เคลื่อนที่)
- `reporting.cleaning_checks` (array 3 label แม่บ้าน — ดู 2.4)
- มัดจำ 50% ใช้ `booking.deposit_percent` (มีแล้วจากแมป booking-payment-types — ไม่เพิ่ม)

### 2.8 สิ่งที่ owner defer แล้ว — ไม่ทำใน v1

- **Supplies movement ledger + item master** — supplies-report โชว์ minimal จาก `stock_inventories` เดิม (item_name / unit / quantity→remaining) · column ที่ไม่มีตารางรอง (item_code, category, carried_over, received, issued, reorder_point, max_stock, status) **เว้นว่าง**
- **VIP ป้ายห้องผู้บริหาร** — room-status-report legend เหลือ **5 ค่า OCC/OOO/VC/VD/EA** (map จาก rooms.status/periods/booking spans ครบ) · อนาคตถ้าอยากได้ = boolean บน `rooms` + branch เดียว (fog จดใน map แล้ว)
- **Inspected = derive จาก `prep_checkin`** คงเดิม *(⚠️ default จาก agent — รอ owner sign-off ดู §9)* — available→Clean, dirty→Dirty, prep_checkin→Inspected ไม่แตะ state machine

## 3. การ map ข้อมูลรายงาน (tickets 06, 08, 09, 10, 11)

### 3.1 Financial mapping (ticket 08 — ใช้ design แมป booking-payment-types เป็น base)

- **`received_amount`** = `payments.amount` (SUM ต่อ booking ตาม flow — `payments` คือ ledger เดียวของเงินที่เข้าจริง 3 จุดเขียน) · `paid_amount`/`outstanding` = accessors ที่แมปพี่เลี้ยงทำไว้ (`outstanding` = total − paid) · ชั้น A `booking_confirmations.amount` มีอยู่แล้ว — รายงานไม่ต้องแตะเอง
- **`payment_status` (เต็มจำนวน/มัดจำ 50%)** = `bookings.payment_type` (`full|deposit|deferred`) + `deposit_amount` (null = 50% จาก `booking.deposit_percent`) — ไม่เพิ่ม storage
- **`payment_channel` → "ที่มาเงิน" 2 ค่า: สลิป (โอน/QR) | เงินสดหน้าเคาน์เตอร์** — **derive จากจุดเขียน ledger** (verify สลิป = สลิป / `recordPayment` = เงินสด) ไม่เพิ่ม column channel (ตาม design แมปพี่เลี้ยงที่ไม่กลับไปแตะ `payment_method` ที่ drop แล้ว)
- **`receipt_ref` = เก็บคอลัมน์ไว้ตาม layout ชีต แต่แสดง "-" ทุกแถว** — receipts FROZEN รอระบบใบเสร็จใหม่ (ห้ามใช้ `payments.reference_number` แทน — คงความหมายหัวคอลัมน์เดิม) · จดหมายเหตุใน template
- **ผลต่อ template `daily-financial-report.json` (adjust ตอน implement):** filter `payment_channel` เหลือ 3 options (ทั้งหมด/สลิป/เงินสด) · column เหลือ 2 ค่า · `receipt_ref` เติม "-" · หมายเหตุใน JSON ว่าชีตต้นทางยังเป็น QR/เงินสด/บัตรแต่ **owner ตัดสินให้ระบบ wins**

### 3.2 Organization / ERP / inter-unit (ticket 09)

- **`agency_name` = `organizations.name`, `erp_code` = `organizations.erp`** อ่านผ่าน `bookings.organization_id` — ไม่เพิ่ม storage · filter `agency_code` ของ erp-transfer-report = `organizations.erp`
- **`is_inter_unit_transfer` = derive** (organization_id not null — ซึ่งเป็น `deferred` เสมอตาม design payment-types) ไม่เพิ่ม flag · check-in-report: column TRUE/FALSE + **special-case ตามต้นทาง: แถว inter-unit จัด section Fully Paid** (ชำระแล้วโชว์ตามจริง อาจ 0 — หน่วยงานรับผิดชอบผ่าน ERP ไม่ใช่ค้างชำระของแขก)
- **erp-transfer-report** = list org bookings ทั้งหมด + filter erp · **deposit-report** filter หน่วยงาน/ทั่วไป = `organization_id` null / not null · รายงานอื่นไม่ break down ตาม org (fog จบแล้ว)

### 3.3 Manager-report definition (ticket 06)

- Matrix **20 metrics (แถว) × 6 คอลัมน์ช่วงเวลา** ตามชีต · แถว 9–14 = breakdown ต่อคืนของวันที่ filter: Total Rooms = Room Occupied + Comfirmed + Provisional + Unsold (แถว % และกลุ่ม OOO derive ต่อ) · Day = คืน/วันของวันที่ filter ไม่สะสม
- **`provisional`** = booking `draft` + `pending` + `verify_error` ที่ครอบคลุมคืนวันที่ filter (**ไม่เพิ่ม storage**) · Comfirmed = `paid` + `confirmed` · Room Occupied = booking_room `checked_in`/`checked_out` ครอบคลุมคืนนั้น · Unsold = Total − สามกลุ่มแรก
- **Complimentary Room** = booking_room ทั้งหมดของ booking ที่ flag `is_complimentary` (2.1)
- **mode 3 โหมดตามชีต:** Complete = Day/MTD/YTD + LY ครบ 6 · Week = แทน MTD ด้วย Week-to-Date (**สัปดาห์เริ่มวันจันทร์**) · Month = แทน MTD ด้วยช่วง From-TO ที่เลือก — LY คงอยู่ทุกโหมด
- **ค่ารวมช่วง (MTD/YTD/LY):** แถวตัวนับเหตุการณ์ (Total Persons, Arrival/Depart/No Show Rooms+Persons) = **ผลรวมทั้งช่วง** · แถวสถานะห้อง (Occupied/Comfirmed/Provisional/Unsold/OOO-family) = **เฉลี่ยต่อคืน** · %Occupied = เฉลี่ยรายวันทั้งช่วง
- **LY ตอนไม่มีข้อมูลปีก่อน = แสดง 0** (คอลัมน์คงที่ 6 คอลัมน์ — ระบบรันข้ามปีแล้วตัวเลขเด้งเอง)
- **OOO ย้อนหลัง (MTD/YTD/LY)** = derive จาก `room_state_periods` kind=maintenance (`overlapping`/`activeOn`) — แมป room-state-periods landed แล้ว **ปลดล็อกครบ** (occupancy-report ใช้วิธีเดียวกัน)

### 3.4 Addon pricing + รายงาน (ticket 10)

- **แก้ pricing ที่ chokepoint เดียว `DiscountService::reprice()`** สูตรใหม่: `amount = room_amount − discount + extra_bed_total(รายคืน) + breakfast_total(× คืน) + early + late` · `RoundToTen::round()` ท้ายสุดคงเดิม · **invariant `Σ booking_rooms.amount == total_amount` คงเดิม**
- **4 write paths ใช้ formula เดียวกัน** (createBooking / addRooms / update+batch / front-desk walk-in) — ดึงเป็น helper กลางเพื่อเลิกคำนวณซ้ำ 4 ที่
- **Breakfast:** `breakfast_total = (set_100 × เรท breakfast_100 + set_200 × เรท breakfast_200) × nights` — แก้ undercharge เดิม (คิดครั้งเดียวต่อ stay ทั้งที่ร้านเตรียมทุกคืน) · ⚠️ ยอดเงินเปลี่ยนเฉพาะ booking ที่มี breakfast — draft reprice ใหม่อัตโนมัติตอน implement (draft เกิน deadline ถูก GC อยู่แล้ว)
- **วันกินของ breakfast = เช้าวันถัดจากคืนนอน** D ∈ `(check_in, check_out]` (เช้าเช็คเอ้าท์นับ) — ตรง sample ชีต: พัก 9/11→9/12 โผล่รายงานวันที่ 9/12 · row รายงาน = per (booking_room × วันกิน) · "x" ในชีต = null คงตีความเดิม (ไม่ได้ซื้อชุดนั้น) · filter `breakfast_type` ตัด row ที่ชุดนั้น qty = 0
- **Extra-bed:** pricing รายคืน `Σ qty รายคืน × เรท extra_bed` · report `beds_by_night` อ่านจาก JSON, night columns จาก filter range, `total_allocated` รวมใน PHP, `total_inventory` จาก config

## 4. API contract + สิทธิ์ (ticket 04 — owner ตัดสินตรง)

- **Endpoint = route ต่อรายงาน (explicit 15 routes — owner เลือกแยก ไม่ใช้ generic `{report-id}`)** ทั้งหมด `GET /api/v1/reports/{slug}/export` ชี้ controller เดียว delegate เข้า `ReportData`:

| slug | รายงาน | สิทธิ์ |
|---|---|---|
| `check-in` | check-in-report | admin, staff |
| `check-out` | check-out-report | admin, staff |
| `daily-financial` | daily-financial-report | admin, staff |
| `deposit` | deposit-report | admin, staff |
| `occupancy` | occupancy-report | admin, staff |
| `breakfast` | breakfast-report | admin, staff |
| `erp-transfer` | erp-transfer-report | admin, staff |
| `housekeeping` | housekeeping-report (v1) | admin, staff, **housekeeping** |
| `housekeeping-v2` | housekeeping-report-v2 | admin, staff, **housekeeping** |
| `room-status` | room-status-report | admin, staff |
| `extra-bed` | extra-bed-report | admin, staff |
| `supplies` | supplies-report | admin, staff |
| `out-of-service-room` | out-of-service-room-report | admin, staff |
| `additional-charges` | additional-charges-report | admin, staff |
| `manager` | manager-report | admin, staff |

- **ส่งไฟล์ = stream ทันที (synchronous)** สร้างใน memory แล้ว response ออก — **ไม่มีไฟล์ค้าง disk → ไม่ต้อง cleanup job** · `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` · `Content-Disposition: attachment` + **ชื่อไฟล์ภาษาไทย + ช่วงวันที่** (ใช้ `filename*=UTF-8''` RFC 5987 สำหรับไทย)
- **สิทธิ์ = `CheckRole` middleware ต่อ route ตามธรรมเนียม (ไม่มี Policy class)** — admin+staff ทุกใบ · housekeeping เฉพาะ 2 ใบแม่บ้าน · + in-controller re-check ตาม defense-in-depth เดิม
- **validate filter ต่อรายงานผ่าน `ReportData::filterRules()`** — รวมเพดาน **ช่วงวันที่ ≤ 1 ปี ต่อการ export**
- **Throttle `10,1`** (กลุ่ม lookups) ทุก route
- **Error contract ตาม shape เดิม:** 422 filter ไม่ผ่าน validate · 403 ไม่มีสิทธิ์ · 404 slug ไม่ตรง route · body `{"status":"error","message":…}` — 500 กลับ generic message + `Log::error()` ตาม convention
- CRUD `additional-charges` ใหม่ (2.6) อยู่นอกกลุ่ม reports — throttle/สิทธิ์ตามปกติ (admin+staff)

## 5. Formatting & print policy (ticket 05 — owner ตัดสินตรง)

- **วันที่ = พ.ศ. `d/m/YYYY`** (เช่น 14/09/2569) — DB เก็บ ISO-8601, format ตอน render เท่านั้น
- **เงิน (`money_baht`) = `#,##0` ไม่มีทศนิยม** — ตรง storage integer บาท
- **สไตล์ = Minimal flat table** (owner เลือก **ไม่ mirror ชีตต้นทาง**): ไม่ merged cell ไม่ fill สี · header column bold ขาวดำ · section header (เช่น Fully Paid) = **แถว bold ธรรมดาไม่ merge** · summary = **แถวรวมท้ายตาราง bold** (ไม่ทำ block สรุปแยก) · เปิด autoFilter ให้กรองใน Excel ได้ — ขาวดำทั้งไฟล์
- **Print setup ครบธรรมเนียมชีต:** A4 landscape · freeze header row + repeat print title rows ทุกหน้า · หัวไฟล์ (แถวบนสุดก่อน header) = **ชื่อรายงานภาษาไทย + "ข้อมูลวันที่ …" ตามช่วง filter** · ชื่อชีต (tab) = ชื่อรายงานภาษาไทย

## 6. ข้อยกเว้น sheet-wins ที่ owner อนุมัติ

ชีตต้นทางยังเป็น truth ยกเว้น 2 จุดที่ **ระบบ wins** (owner ตัดสิน 2026-10-05):

1. `daily-financial-report` — channel enum ชีตเป็น QR/เงินสด/บัตรเครดิต → รายงานใช้ 2 ค่า (สลิป/เงินสด) ตามข้อมูลจริง (§3.1)
2. `housekeeping-report-v2` — หัวคอลัมน์ checklist ชีตไม่มีชื่อ → ระบบตั้งเองจาก config (§2.4)

Template JSON ที่ adjust ตอน implement ต้องจดหมายเหตุทั้ง 2 จุด

## 7. Testing strategy

- **Seam 1 — HTTP feature (15 routes):** auth/role matrix (admin ✓, staff ✓, housekeeping เฉพาะ 2 ใบ, user/guest 403), validation 422 + เพดานช่วงวันที่ > 1 ปี, stream headers (Content-Type/Disposition ไทย), 404 slug · style เดียวกับ feature test เดิม SQLite in-memory
- **Seam 2 — xlsx content:** generate ผ่าน service แล้ว `IOFactory::load()` assert: หัวไฟล์ชื่อรายงาน+ช่วงวันที่, header row, number format `#,##0`, วันที่ render พ.ศ., ค่าข้อมูลจาก mock (ใช้ `sample_data` ของ template เป็นแนว), section rows (bold ไม่ merge), summary totals ท้ายตาราง, print setup (A4 landscape, freeze/repeat title)
- **Pricing suite:** ต่อยอด test booking เดิม — breakfast × คืน, extra-bed รายคืน (จำนวนไม่เท่ากันรายคืน), legacy alias normalize, invariant `Σ amount == total_amount` + `RoundToTen` ท้ายสุด
- **Migration/data-migration:** `breakfast` → `breakfast_set_200` · `extra_bed` int → flat map คงยอด · PgBoolean ทุก column ใหม่
- Manager/occupancy aggregate: Day แม่นด้วยสถานะ, MTD/YTD เฉลี่ยต่อคืน/ผลรวมตาม 3.3, OOO ย้อนหลังจาก periods, LY = 0

## 8. Ticket breakdown สำหรับ implement (เสนอ — ผู้ implement ปรับได้)

| # | งาน | ลำดับ/คู่ขนาน |
|---|---|---|
| IMPL-01 | **Schema pack:** migrations 2.1–2.6 + data migration (breakfast→set_200, extra_bed→JSON) + `PgBoolean` + seeder global_rates (`breakfast_100/200`) + config (fleet/checklist) | ก่อนสุด — ทุกใบอื่นรอ |
| IMPL-02 | **Pricing conform:** helper กลาง addon รายคืน/แยกชุด + `DiscountService::reprice()` + 4 write paths + wire canonical/legacy + test pricing | หลัง IMPL-01 |
| IMPL-03 | **Template + engine:** copy 15 JSON → `resources/report-templates/` + adjust daily-financial (§3.1) + `ExcelReportRenderer` + `ReportData` contract + format map (พ.ศ./`#,##0`) + print setup + engine test | คู่ขนานกับ IMPL-02 ได้ (ไม่ผูก schema) |
| IMPL-04 | **API shell:** controller + 15 routes + CheckRole + throttle `10,1` + stream response + error contract + test seam 1 (stub data) | หลัง IMPL-03 |
| IMPL-05 | **Providers กลุ่ม booking/financial:** check-in, check-out, deposit, erp-transfer (+write paths ฟิลด์ booking ใหม่ admin-only), daily-financial, additional-charges (CRUD 2.6 + report) | หลัง IMPL-01+04 — คู่ขนานภายในกลุ่ม |
| IMPL-06 | **Providers กลุ่ม addon/housekeeping:** breakfast, extra-bed, housekeeping v1+v2 (+tick checklist ใน update-task API) | คู่ขนานกับ IMPL-05 |
| IMPL-07 | **Providers กลุ่มห้อง/aggregate:** room-status, out-of-service-room, supplies (minimal), occupancy, manager (matrix + OOO จาก periods) | คู่ขนานกับ IMPL-05/06 |
| IMPL-08 | **Docs:** `docs/api_guide.md` (15 endpoints + CRUD charges + หมายเหตุ extra_bed_price display-only) + `cline.md` + AGENTS.md (ย้าย Planned → landed) | ปิดท้าย |

Composer: `composer require phpoffice/phpspreadsheet` อยู่ IMPL-03 · เปิด ext gd/zip/mbstring/dom/xmlwriter ทั้ง dev+prod ก่อน

## 9. Open flags — default รอ owner sign-off (2 ข้อจาก ticket 11)

1. **ผู้บันทึก `additional_charges` = admin + staff** (บันทึกอิสระทุกเมื่อ ไม่ผูก checkout) — default ของ agent ตรง primary_users ชีต (พนักงานหน้าเคาน์เตอร์ + แอดมิน) — ถ้า owner อยากจำกัดเป็น admin เท่านั้น แก้สิทธิ์ CRUD จุดเดียว (§2.6)
2. **Inspected = derive จาก `prep_checkin`** (available→Clean, dirty→Dirty, prep_checkin→Inspected) — ไม่แตะ state machine (§2.8)

นอกจากนี้ทุกข้อเป็นคำตอบที่ owner ตัดสินแล้วใน tickets (06, 08, 09, 10, 11 — grilling ตรง) หรือ sign-off แล้ว (03, 04, 05)

## Out of scope (คงเดิมตาม map)

- เอกสาร PDF 9 ฉบับ + digital signature · export PDF · หน้าจอรายงาน/กราฟฝั่ง React · scheduling/email ส่งรายงานอัตโนมัติ · supplies movement ledger + item master (defer) · VIP flag (defer) · auto-sync จาก Google API
