---
label: wayfinder:map
title: "Excel report export — สร้าง Excel 15 รายงานจาก template docs/report_docAndSample"
status: open
---

# 🗺️ Wayfinder Map — Excel report export (15 รายงาน)

- **label:** `wayfinder:map`
- **status:** open
- **tracker:** local-markdown — tickets อยู่ใน `tickets/`, research อยู่ใน `research/` (blocking = field `blocked-by` ของแต่ละ ticket)
- **charted:** 2026-09-22

## Destination

Design spec (label `ready-for-agent`) ฉบับเดียว สำหรับฟีเจอร์ **export ไฟล์ Excel (.xlsx) ของรายงานทั้ง 15 ฉบับ** ตาม template ใน `docs/report_docAndSample/report-templates/` — ตัดสินครบ: library, renderer architecture + ที่อยู่ของ template, API contract + สิทธิ์, formatting/print conventions และ data-coverage รวม design ตารางใหม่ที่จำเป็น — พอให้ implement ต่อได้โดยไม่ต้องตัดสินใจใหญ่อีก

## Notes

- **Domain:** KU HOME API (Laravel 13 · PHP 8.3 · API-only · PostgreSQL prod / SQLite test) — คือ roadmap "Generate & print report templates" (Planned ใน AGENTS.md) ฉบับ Excel · server-side rendering ตาม AGENTS.md (frontend React แยก repo)
- **Template source:** `docs/report_docAndSample/report-templates/*.json` 15 ไฟล์ + `_index.json` — ⚠️ โฟลเดอร์นี้ถูก `/docs/*` gitignore (มีเฉพาะเครื่อง dev นี้) → การวาง template ฝั่ง app เป็น decision ใน ticket 03
- **🌟 TRUTH SOURCE (owner ประกาศ 2026-09-22):** Google Sheets ["เอกสารระบบที่พัก_Document"](https://docs.google.com/spreadsheets/d/1v8euwEZZMnsSHmJuV5ZDu3bRE5NI0TTplAW8o_1m3bw/edit) (แท็บรายงาน `gid=1127367576`) — **ชีตนี้คือความจริง** ทุก ticket ที่คุยเลย์เอาต์/คอลัมน์/metric ต้องเทียบชีตใบนี้ · template JSON เป็นตัวกลางที่แปลงจากชีต (`_index.json` อ้าง id เดียวกัน) — ถ้าขัดกัน **ชีต wins** แล้ว re-convert · public view-only · snapshot xlsx + แผนผังแท็บ↔JSON อยู่ที่ [research/truth-snapshot/](research/truth-snapshot/README.md)
- **Schema จาก template:** `money_baht` = integer บาท (convention 2026-09-11), `date` = ISO-8601, มี `sample_data` จากชีตต้นทางใช้ mock ได้, typo/ความไม่สม่ำเสมอของต้นทางระบุใน `notes` ของแต่ละไฟล์
- **Tracker ธรรมเนียมเดียวกับ map อื่น:** claim = เติม `assignee:` ก่อนลงมือ · 1 ticket/session · ปิด = `status: closed` + `## Resolution` · commit tracker ไปกับ branch ปัจจุบันเสมอ
- ไม่มี skill `grilling`/`domain-modeling`/`research` บนเครื่อง — grilling ถาม owner ตรง (AskUserQuestion), งานสำรวจใช้ Explore agent (precedent ku-sso)
- **🆕 Facts จากแมปพี่เลี้ยงที่ปิดแล้ว (2026-09-25 — ตรวจสถานะ):**
  - **[`booking-payment-types`](../booking-payment-types/map.md) ✅ closed** — `bookings.payment_type` = `full|deposit|deferred` (default full, admin-only) · ชั้น A `booking_confirmations.amount` (required สลิปใหม่) · **`payments` = ledger เดียวเงินที่เข้าจริง** (3 จุดเขียน) · ชั้น B derive ล้วน (`paid_amount` = SUM(payments), `outstanding` = total − paid) · `deposit_amount` nullable บน bookings (null = 50% config) · ปัดเศษขึ้นหลักสิบ → **ticket 08 ปลดล็อก** — ธง "ยอดสลิป/มัดจำ 50%" มีคำตอบพร้อมใช้; ธง `payment_channel` ถูกยกมาไว้ที่ ticket 08 ตัดสินเอง (แมป payment-types ตัดสินว่า derive จากจุดเขียน ledger ได้ ไม่ต้องกลับ column — จะมี column ชัด ๆ ไหมเป็นของ ticket 08); `receipt_ref` ยังติด `receipts` FROZEN เหมือนเดิม
  - **[`organization-bookings`](../organization-bookings/map.md) ✅ closed** — ตาราง `organizations` (`erp` string unique nullable + `is_active`) + `bookings.organization_id` (FK restrict) + snapshot `customer_name`/`customer_phone`/`customer_email` บน bookings · `GET /bookings?organization_id` filter แล้ว แต่ **aggregation รายงานตามองค์กรยกให้แมปนี้** → **ticket 09 premise หมดอายุบางส่วน** — audit เดิมบอก "ไม่มี concept หน่วยงาน/ERP เลย" ตอนนี้ไม่จริงแล้ว (มี org/erp/`guest_type` หน่วยงานผ่าน organization_id แล้ว) เหลือ design เฉพาะ `is_inter_unit_transfer`, แจ้งหนี้, `special_request`, `complimentary`
- **Charting defaults (2026-09-22 — owner ไม่ได้ตอบ grill ตอน chart, ใช้ best judgment, กลับมาแก้ได้):**
  1. Destination = **spec อย่างเดียว** (plan-don't-do — implement เป็นงานถัดไปหลัง spec ผ่าน)
  2. ขอบเขต = **Excel เท่านั้น** — เอกสาร PDF 9 ฉบับ (document-register.json) เป็น effort แยก
  3. รายงานที่ข้อมูลไม่พอ → **ออกแบบตารางใหม่รวมใน spec** (ไม่ตัดรายงานทิ้ง)

## Decisions so far

<!-- ดัชนี: 1 บรรทัดต่อ ticket ที่ปิดแล้ว — zoom เข้า ticket/research อ่านรายละเอียด -->

- [Research: เลือก library สร้าง Excel](tickets/01-excel-library-choice.md) *(2026-09-22)*: แนะนำ **phpoffice/phpspreadsheet 5.x ใช้ตรง ๆ** — styling ครบทุกข้อ (merged cells, number format, freeze panes, print titles, A4 landscape) + `IOFactory::load()` test ได้; OpenSpout ตกเพราะ v5 ต้อง PHP 8.4+ · sign-off รอใน ticket 03 — รายละเอียด [research/excel-library.md](research/excel-library.md)
- [Research: data-coverage audit](tickets/02-data-coverage-audit.md) *(2026-09-22)*: 15 รายงาน = **1 ✅ / 11 ⚠️ / 3 ❌** — gap เจ็บสุดคือ slip flow ไม่เก็บยอดเงิน + `payment_channel` ถูก drop แล้ว · gap 12 กลุ่ม graduate เป็น tickets 08–11 — รายละเอียด [research/data-coverage-audit.md](research/data-coverage-audit.md)
- [สถาปัตยกรรม renderer + ที่อยู่ของ template](tickets/03-renderer-architecture.md) *(2026-09-22 — grilling defaults ✅ owner sign-off แล้วในวันเดียวกัน)*: **phpspreadsheet 5.x ใช้ตรง** (sign-off ticket 01) · **Hybrid** = engine กลาง template-driven + `ReportData` ต่อรายงาน · template JSON วางที่ **`resources/report-templates/`** ใน repo (runtime source) — ชีต truth = upstream, sync manual re-convert — snapshot ชีต + แผนผังแท็บอยู่ที่ [research/truth-snapshot/](research/truth-snapshot/README.md)
- [Formatting & print conventions ของไฟล์ Excel](tickets/05-formatting-print-conventions.md) *(2026-09-22 — grilling)*: วันที่ **พ.ศ. `d/m/YYYY`** · เงิน **`#,##0` ไม่มีทศนิยม** · สไตล์ = **minimal flat table** (owner เลือกไม่ mirror ชีต — ไม่ merged ไม่ fill, filter ใน Excel ได้, summary เป็นแถวรวมท้ายตาราง bold) · print ครบธรรมเนียมชีต (A4 landscape, freeze + repeat title, หัวไฟล์ชื่อรายงาน + ช่วงวันที่, ชื่อชีตไทย)
- [API contract + สิทธิ์](tickets/04-api-contract-and-roles.md) *(2026-09-22 — grilling)*: endpoint = **route ต่อรายงาน (explicit 15 routes, ไม่ generic)** · ส่งไฟล์ = **stream ทันที** (ไม่มีไฟล์ค้าง/ไม่ต้อง cleanup) · สิทธิ์ = **admin+staff ทุกใบ + housekeeping เฉพาะใบแม่บ้าน 2 ใบ** · throttle `10,1` + จำกัดช่วงวันที่ ≤ 1 ปี · error ตาม shape เดิม — ในรอบเดียวกัน owner **sign-off ticket 03 ครบทั้ง 4 ข้อ**

## Not yet specified

- ~~Design ตารางใหม่รายโดเมน~~ *(graduated 2026-09-22: audit ทำให้ gap ชัด → tickets 08 payment/deposit · 09 ERP/agency/booking attributes · 10 addon product model · 11 room/supplies domain tables)*
- เอกสาร PDF ฉบับที่อาจต้องมี Excel ตารางร่วม (เช่น ใบงานแม่บ้านเป็นตารางรายวัน) — ถ้า owner ขอค่อยขยาย destination
- ~~เพดานขนาดไฟล์/ช่วงวันที่ export สูงสุด~~ *(จบ 2026-09-22 ใน ticket 04 — คุมช่วง ≤ 1 ปี + throttle `10,1` ตาม default · stream ทันทีไม่มีไฟล์ค้าง · ข้อสังเกตจาก research ticket 01: ถ้าจริงเจอรายงาน >50k แถว ค่อยกลับมาคุย cache/queue — เพราะ stream สมมติไฟล์ไม่ใหญ่)*
- ~~Design ฝั่ง payment type ที่ยังไม่เคยออกแบบจริง~~ *(fog 2026-09-22 → graduated 2026-09-24 เป็นแมป [`booking-payment-types`](../booking-payment-types/map.md) → **จบ 2026-09-25** — แมปปิดสมบูรณ์ design + implement, suite 554 เขียว → [ticket 08](tickets/08-payment-amount-channel-deposit.md) ปลดล็อก กลับเป็น frontier — เหลือตัดสินเฉพาะ `payment_channel` กับ `receipt_ref` ในบริบทรายงาน)*
- **รายงาน/aggregation ตามองค์กร** *(fog ใหม่ 2026-09-25 — จากแมป [`organization-bookings`](../organization-bookings/map.md) ที่ปิดแล้ว: org filter บน `GET /bookings` ทำแล้วแต่ aggregation ถูกยกมาไว้ที่แมปนี้)*: ข้อมูลพร้อมแล้ว (`bookings.organization_id` + snapshot `customer_name`/phone/email) — คำถามว่ารายงานไหนต้อง break down ตามองค์กร (erp-transfer-report น่าจะใช่) ยังไม่ชัดพอ graduate — ตัดสินร่วมตอน grill ticket 08/09

## Out of scope

- เอกสาร PDF 9 ฉบับจาก `document-register.json` (Registration/PDPA/Quotation/Receipt/Guest Folio/ใบงานแม่บ้าน/ใบแจ้งซ่อม) + digital signature — roadmap effort แยก ("Digital signature on physical documents")
- Export รายงานเป็น **PDF** (ต้นทางระบุ PDF/Excel — effort นี้ทำ Excel ก่อน, PDF ผลักไปหลัง)
- หน้าจอแสดงรายงาน/กราฟฝั่ง React frontend (ku-home)
- ระบบ scheduling/email ส่งรายงานอัตโนมัติ (ถ้ามีความต้องการจริง ค่อยเปิด effort ใหม่)
