# 🔁 IMPL Handoff — excel-reports implementation (session ถัดไปอ่านก่อนลงมือ)

> **สถานะ ณ 2026-10-05 (สิ้นสุด session implement รอบแรก):** suite เขียว **615 passed / 0 failed**
> worktree `C:\dev\hotel\.worktree\excel-reports-impl` · branch `feature/excel-reports-impl`
> spec = [spec.md](./spec.md) (label `ready-for-agent`) — อ่าน spec ก่อนเสมอ โดยเฉพาะ §2 (schema), §3 (mapping), §4 (API), §9 (ธงรอ sign-off 2 ข้อ)
> ธรรมเนียม: claim งานในไฟล์นี้ (เติม `assignee:` ด้านล่าง) · commit ต่อบน branch เดิม

```
assignee: (ว่าง — session ถัดไปเติมชื่อก่อนลงมือ)
```

## ✅ เสร็จแล้ว (commit บน branch แล้วทั้งหมด — ห้าม redo)

| งาน | สถานะ | หมายเหตุ |
|---|---|---|
| IMPL-01 schema pack | ✅ | migrations `2026_10_05_100001..100006` · data migration addons reshape · seeder global_rates `breakfast_100/200` · `config/reporting.php` · migrate:fresh ผ่าน |
| IMPL-02 pricing conform | ✅ | `App\Services\Addon\AddonPricing` (helper กลาง) · `DiscountService::reprice()` เขียน snapshot ทุกตัว · 4 write paths normalize ผ่าน helper · suite รวม `AddonPerNightPricingTest` (7 เคส) เขียว |
| IMPL-03 template + engine | ✅ | `resources/report-templates/` 16 ไฟล์ · daily-financial adjust ตาม §6.1 แล้ว · `ExcelReportRenderer` + contract + `BaseReportData` + `ThaiDate` · **engine เขียนโดย antigravity (gemini-3.8-flash) ผ่าน agent-hub session `hub_sess_9aa507f1c28d` แล้ว zcode รีวิว+รัน test ยืนยัน** — test ผ่าน 4 เคส 91 assertions |
| IMPL-05 providers booking/financial | ✅ | CheckIn (sections + inter-unit special-case §3.2) · CheckOut · Deposit (filter หน่วยงาน/ทั่วไป) · ErpTransfer · DailyFinancial (derive ที่มาเงิน: `reference_number` = confirmation UUID → "สลิป (โอน/QR)" ไม่งั้น "เงินสด") · AdditionalCharges |
| IMPL-06 providers addon/housekeeping | ✅ | Breakfast (วันกิน D ∈ (check_in, check_out]) · ExtraBed (columns dynamic รายคืน + fleet config) · Housekeeping v1 (Inspected = derive prep_checkin §2.8) · v2 (emoji status + checklist header จาก config §6.2) |
| IMPL-07 providers ห้อง/aggregate | ✅ | RoomStatus (legend 5 ค่า OCC/OOO/EA/VD/VC — VIP defer) · OutOfServiceRoom (repair log) · Supplies (minimal ตาม defer §2.8) · Occupancy (7 คืน + %Occ/ADR) · **Manager (matrix 20×6, mode Complete/Week/Month, LY=0, OOO จาก periods)** |
| IMPL-04 API shell — **เสร็จบางส่วน** | 🟡 | ทำแล้ว: `ReportController` (registry 15 + 15 methods + re-check) · routes 15 (throttle 10,1 · 13 ใบ role:admin,staff / 2 ใบแม่บ้าน +housekeeping) · CRUD `additional-charges` (POST/PATCH/DELETE role:admin,staff) · booking attributes 4 ฟิลด์: validation + write path ที่ **PUT /bookings/{id}** แล้ว |

## 🔴 ค้างอยู่ (ทำตามลำดับนี้)

### 1. booking attributes — เติมฝั่ง createBooking (~10 นาที)
- `app/Http/Requests/StoreBookingRequest.php` — เพิ่ม rules 4 ฟิลด์ (nullable เช่นเดียวกับ `UpdateBookingPaymentRequest`): `invoice_requested_at` (date) · `special_request`/`comment` (string max:2000) · `is_complimentary` (boolean)
- `BookingController::createBooking` — guard ต่อจาก payment-type guard เดิม: ถ้า `$request->has()` ฟิลด์ใด และ role ไม่ใช่ admin/system → throw 403 (pattern เดียวกับ payment_type guard ที่บรรทัด ~1239) · แล้วใส่ค่าใน `Booking::create([...])` (จะได้ตั้งค่าตอนสร้าง, PgBoolean cast จัดการ boolean เอง)
- ⚠️ ตอนนี้ validation ยังไม่รับ 4 ฟิลด์ฝั่ง POST — ส่งมาถูก strip เงียบ (ปลอดภัย แค่ admin ตั้งค่าตอนสร้างยังไม่ได้)

### 2. checklist tick ใน update-task API (~20 นาที)
- `DashboardController::updateStatus` (PATCH `/dashboard/tasks/{id}/status`) — เพิ่ม validation `cleaning_check_1/2/3` nullable boolean + persist ก่อน transition
- ปัญหา: `status` เป็น `required` อยู่ — แก้เป็น `nullable|required_without_any:cleaning_check_1,cleaning_check_2,cleaning_check_3` แล้ว transition เมื่อ status มีค่าเท่านั้น (tick ล้วน = บันทึกประกอบ ไม่ผูก done ตาม spec §2.4)
- รัน `php artisan test --filter=HousekeepingTaskTest` — ถ้า test เดิม fail เพราะ status ไม่ส่ง ให้ดู assertion ของเคส 422 แล้วปรับ test ให้สอดคล้อง (เจตนาเดิม: status หาย = 422 เมื่อไม่มี checklist มาด้วย)

### 3. Feature test seam 1 — `tests/Feature/ReportExportTest.php` (งานหลัก ~2 ชม.)
ตาม spec §7: auth/role matrix (admin ✓ · staff ✓ · housekeeping เฉพาะ `/reports/housekeeping/export` + `/reports/housekeeping-v2/export` · user/guest/ไม่ล็อกอิน 403 หรือ 401) · validation 422 + เพดานช่วงวันที่ > 366 วัน (เช่น extra-bed/erp-transfer ใส่ date_to เกิน from+366) · stream headers (Content-Type `application/vnd...spreadsheetml.sheet` + Content-Disposition มี `filename*=UTF-8''`) · slug ไม่ตรง = 404 (route ไม่มีอยู่เอง)
- Tip: สร้าง user ด้วย `User::factory()->create(['role' => 'staff'])` · ทุกใบอย่างน้อยต้อง assert 200 + header ถูก (data ว่าง = ไฟล์ยังออก มีแค่หัวไฟล์+header row)
- เคสที่ควรมีข้อมูลจริงอย่างน้อย 1 ใบ: additional-charges (สร้าง row เอง ง่ายสุด) เพื่อยืนยัน cell ผ่าน `IOFactory::load()` ต่อยอดจาก engine test ได้

### 4. Test โครงสร้างเสริม (จาก spec §7 — เลือกทำตามเวลา)
- Migration/data-migration test: `breakfast → breakfast_set_200` · `extra_bed int → flat map คงยอด` (สร้าง row เก่าด้วย DB::table แล้วรัน migration บางส่วนยาก — พอให้ assert ผ่าน `migrate:fresh` + สร้างข้อมูลใหม่แบบ canonical แทนได้ ตามดุลยพินิจ)
- Manager/occupancy aggregate: Day แม่นด้วยสถานะ · MTD/YTD เฉลี่ยต่อคืน/ผลรวม · OOO จาก periods · LY = 0

### 5. IMPL-08 docs
- `docs/api_guide.md` — เพิ่ม section รายงาน 15 endpoints (query params ต่อใบ ดู `filterRules()` ของแต่ละ Data class) + CRUD additional-charges + **หมายเหตุ `room_types.extra_bed_price` = display-only** (spec §2.3) + canonical wire ของ addon (`breakfast_sets` / `extra_beds_by_night` + legacy alias)
- `cline.md` — entry ใหม่ (ระบบรายงาน Excel: engine/Data pattern, ห้าม hard-code cell ใน Data, AddonPricing chokepoint, channel derive จาก reference_number)
- `AGENTS.md` — ย้าย "Generate & print report templates" ออกจาก Planned → section จริง

### 6. ปิดงาน
- `vendor/bin/pint --dirty` + `php artisan test` เขียวเต็ม
- commit ต่อบน branch + (ถ้า owner ให้ merge) PR เข้า `agust-11`/`main`
- ⚠️ **ธงรอ sign-off spec §9 (ยังค้างเหมือนเดิม — ทำตาม default ไว้แล้ว):** ① ผู้บันทึก charges = admin+staff ② Inspected = derive จาก prep_checkin — ถ้า owner ตัดสินกลับ แก้: ① สิทธิ์ที่ routes/api.php จุดเดียว ② method `houseStatus()` ใน `HousekeepingReportData`

## 🧭 จุดที่ต้องระวัง (จาก session นี้)

- **PgBoolean**: DB::table insert/update boolean ต้อง `DB::raw('TRUE'/'FALSE')` เสมอ (Eloquent model + cast ปลอดภัย)
- **ห้าม bypass chokepoint**: ยอด booking แก้ที่ `DiscountService::reprice()` จุดเดียว · snapshot addon เขียนที่นั่น · `additional_charges` = ledger รายงานล้วน **ไม่ห้าม** ไหลเข้า payments/total_amount
- **Engine/Data แบ่งกันชัด**: Data class ห้ามวาง cell/styling · engine ห้ามมี query
- **ตัวอย่าง antigravity ผ่าน agent-hub**: engine สำเร็จด้วย prompt แบบละเอียด + ต้อง **verify ไฟล์บน disk จริง** (มีรอบที่ session ตอบ completed แต่ไม่ได้เขียนไฟล์ — ให้ `ls` + รัน test ทุกครั้ง)
- ชื่อชีต Excel sanitize `/` → `-` แล้วใน engine (`รายงานห้องชำรุด/บำรุงรักษา`)

## 📁 แผนที่ไฟล์ใหม่ทั้งหมดของ feature นี้

```
app/Services/Addon/AddonPricing.php            ← สูตรเงิน addon กลาง
app/Services/ReportExcel/
  ExcelReportRenderer.php                       ← engine (antigravity)
  BaseReportData.php · ThaiDate.php
  Contracts/ReportData.php
  Support/AddonNote.php
  Data/ (15 classes)                            ← CheckIn/CheckOut/DailyFinancial/Deposit/
                                                   ErpTransfer/AdditionalCharges/Breakfast/
                                                   ExtraBed/Housekeeping/HousekeepingV2/
                                                   RoomStatus/OutOfServiceRoom/Supplies/
                                                   Occupancy/Manager
app/Http/Controllers/Api/V1/
  ReportController.php · AdditionalChargeController.php
app/Http/Requests/ Store|UpdateAdditionalChargeRequest.php
config/reporting.php · resources/report-templates/ (16 json)
database/migrations/2026_10_05_100001..100006_*.php
tests/Feature/AddonPerNightPricingTest.php · tests/Unit/ReportExcel/ExcelReportRendererTest.php
```
