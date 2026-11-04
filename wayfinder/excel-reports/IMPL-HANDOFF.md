# 🔁 IMPL Handoff — excel-reports implementation (session ถัดไปอ่านก่อนลงมือ)

> **สถานะ ณ 2026-10-05 (session head-agent + antigravity รอบสอง):** งานค้างข้อ 1-3 + 5 เสร็จหมด — IMPL-01..08 ครบ · suite เขียว (ดู commit ล่าสุด)
> worktree `C:\dev\hotel\.worktree\excel-reports-impl` · branch `feature/excel-reports-impl`
> spec = [spec.md](./spec.md) (label `ready-for-agent`) — อ่าน spec ก่อนเสมอ โดยเฉพาะ §2 (schema), §3 (mapping), §4 (API), §9 (ธงรอ sign-off 2 ข้อ)
> ธรรมเนียม: claim งานในไฟล์นี้ (เติม `assignee:` ด้านล่าง) · commit ต่อบน branch เดิม

```
assignee: head-agent zcode session (dispatch งาน implement ให้ antigravity ผ่าน agent-hub) 2026-10-05
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

## ✅ เสร็จเพิ่ม (2026-10-05 session ที่สอง — head-agent zcode + antigravity dispatch)

| งาน | สถานะ | หมายเหตุ |
|---|---|---|
| booking attributes ฝั่ง createBooking | ✅ | `StoreBookingRequest` rules 4 ฟิลด์ + guard 403 ต่อจาก payment_type guard + `Booking::create` (commit 5a7b862) |
| checklist tick ใน update-task | ✅ | `required_without_all` + tick ล้วน = บันทึกไม่ผูก done (commit 5a7b862) |
| Feature test Seam 1 `ReportExportTest` | ✅ | 10 เคส 62 assertions — role matrix 15 ใบ/4 role, validation 422 (เพดาน 366 วัน), stream headers, 404 slug, additional-charges ข้อมูลจริงผ่าน IOFactory (commit 26b8c59) · suite bypass ThrottleRequests (throttle ไม่ใช่สิ่งที่ทดสอบ) |
| 🐞 บั๊กจริงที่ test จับได้ (fix แล้ว) | ✅ | ① `ReportController::exportReport` — `ValidationException` ตกใน `catch \Exception` กลายเป็น 500 แทน 422 → rethrow แล้ว ② ParseError `?$spans`/`?$tasks` (nullable type hint ไม่มี type) ใน `RoomStatusReportData`/`HousekeepingReportData`/`HousekeepingReportV2Data` → `?Collection` แล้ว (ไฟล์เหล่านี้เดิมไม่เคยถูกโหลดจึงรอดจาก suite เดิม) |
| IMPL-08 docs | ✅ | `docs/api_guide.md` (section รายงาน 15 ฉบับ + CRUD additional-charges + booking attributes + wire format addon) · `cline.md` (entry ระบบรายงาน + gotchas) · `AGENTS.md` (ย้ายออกจาก Planned → Critical Conventions) |

## 🟡 ค้างอยู่ (เหลือน้อยที่สุด)

### 1. Test โครงสร้างเสริม (จาก spec §7 — **optional** ยังไม่ทำ)
- Migration/data-migration test: `breakfast → breakfast_set_200` · `extra_bed int → flat map คงยอด`
- Manager/occupancy aggregate: Day แม่นด้วยสถานะ · MTD/YTD เฉลี่ยต่อคืน/ผลรวม · OOO จาก periods · LY = 0

### 2. ปิดงาน
- ⚠️ **ธงรอ sign-off spec §9 (ยังค้างเหมือนเดิม — ทำตาม default ไว้แล้ว):** ① ผู้บันทึก charges = admin+staff ② Inspected = derive จาก prep_checkin — ถ้า owner ตัดสินกลับ แก้: ① สิทธิ์ที่ routes/api.php จุดเดียว ② method `houseStatus()` ใน `HousekeepingReportData`
- PR เข้า `agust-11`/`main` เมื่อ owner อนุมัติ (ยอด suite เขียวเต็มแล้ว — ดู commit ล่าสุด)

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
