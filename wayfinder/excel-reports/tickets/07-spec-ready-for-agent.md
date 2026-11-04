---
label: wayfinder:task
type: AFK
title: รวบ decisions → spec.md ฉบับ ready-for-agent
status: closed (2026-10-05 — task AFK)
assignee: zcode-session-2026-10-05
blocked-by: ["03-renderer-architecture", "04-api-contract-and-roles", "05-formatting-print-conventions", "06-manager-report-definition", "08-payment-amount-channel-deposit", "09-erp-agency-inter-unit-booking-attributes", "10-addon-product-model-breakdown", "11-room-domain-new-tables"]
---

# 07: เขียน spec.md ฉบับ ready-for-agent (ปลายทางของ map)

## Question → งาน

รวม decisions ทุกใบ (รวม fog ที่ graduate เป็น ticket ใหม่แล้ว เช่น design ตารางใหม่รายโดเมน) เป็น `wayfinder/excel-reports/spec.md` — รูปแบบเดียวกับ `wayfinder/room-type-rates/spec.md`:

- Solution / Implementation Decisions (lib, renderer, template placement)
- Data model changes (ตารางใหม่/คอลัมน์ใหม่ที่ audit + grilling ตัดสิน)
- API contract + สิทธิ์ + error contract
- Formatting/print policy (พ.ศ./ค.ศ., money format, print setup)
- Testing strategy (สร้าง xlsx จริง assert cell?)
- Ticket breakdown สำหรับ implement (01..N พร้อมลำดับ/คู่ขนาน)

- เขียนเสร็จ: ติด label `ready-for-agent` ที่ spec + append `✅ COMPLETED (<date>)` ต่อท้าย `## Destination` heading ของ map → close map
- ถ้ายังมี decision ค้าง → แตก ticket ใหม่ก่อน ห้ามปิด map ทั้งที่มีช่องว่าง

## Resolution (2026-10-05 — task AFK)

**spec ฉบับ ready-for-agent อยู่ที่ [`../spec.md`](../spec.md)** — รวม decisions ครบทุกใบ (01–06, 08–11 + research 2 ใบ) ตามโครงที่กำหนด:

- **Solution/Implementation Decisions** — phpspreadsheet 5.x ใช้ตรง · Hybrid engine (`ExcelReportRenderer`) + `ReportData` 15 classes · template ที่ `resources/report-templates/` (§1)
- **Data model changes** — migration pack เดียว: `bookings` +4 column · `addons` reshape (breakfast 2 ชุด / extra-bed JSON รายคืน) · `global_rates` +2 code · `housekeeping_tasks` +3 checklist · `room_state_periods` +2 repair log · ตารางใหม่ `additional_charges` + config (§2)
- **การ map ข้อมูล** — financial/org/manager/addon ครบทุกรายงาน (§3) · ข้อยกเว้น sheet-wins ที่ owner อนุมัติ 2 จุด (§6)
- **API contract + สิทธิ์** — 15 routes explicit + table slug/สิทธิ์ + stream + throttle + error contract (§4)
- **Formatting/print policy** — พ.ศ. `d/m/YYYY` · `#,##0` · minimal flat table · A4 landscape (§5)
- **Testing strategy** — 2 seams (HTTP feature + `IOFactory::load()` assert xlsx) + pricing/migration suites (§7)
- **Ticket breakdown** — IMPL-01..08 พร้อมลำดับ/คู่ขนาน (§8)

**ไม่มี decision ค้างที่ต้องแตก ticket ใหม่** — default ที่รอ sign-off จาก ticket 11 จำนวน 2 ข้อ (ผู้บันทึก charges = admin+staff · Inspected derive จาก prep_checkin) ติดธงไว้ชัดใน spec §9 เป็นธงรีวิว spec ไม่ใช่ช่องว่าง design · fog ที่เหลือบน map เป็นของที่ owner defer ("เอาไว้ก่อน") ไม่ใช่ decision ค้าง

→ map `excel-reports` ปลายทางครบ — append `✅ COMPLETED (2026-10-05)` ที่ `## Destination` แล้ว
