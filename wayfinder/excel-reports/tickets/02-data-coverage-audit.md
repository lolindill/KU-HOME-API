---
label: wayfinder:research
type: AFK
title: Data-coverage audit — 15 รายงาน vs DB ปัจจุบัน
status: closed (2026-09-22 — research)
assignee: maid
blocked-by: []
---

# 02: Data-coverage audit — 15 รายงาน vs DB ปัจจุบัน

## Question

รายงานแต่ละฉบับ (columns/filters/sections จาก `docs/report_docAndSample/report-templates/*.json`) มีตาราง/คอลัมน์ใน DB + model ปัจจุบันรองรับครบไหม?

- ทำตาราง audit ต่อรายงานทั้ง 15: ✅ ข้อมูลพอ / ⚠️ ขาด field บางส่วน (ระบุ field ที่ขาดเป็นรายชื่อ) / ❌ ไม่มีตารางรองรับเลย
- จุดที่รู้ตัวล่วงหน้าแล้ว ต้องเช็คให้ชัด:
  - `supplies-report` — สต๊อกวัสดุสิ้นเปลือง (น่าจะไม่มีตาราง)
  - `additional-charges-report` — ค่าปรับ/ค่ายืมอุปกรณ์ (น่าจะไม่มีตาราง)
  - `out-of-service-room-report` — ประวัติซ่อมบำรุง (Room มีแค่สถานะปัจจุบัน ไม่มี log)
  - `erp-transfer-report` — `erp_code` / `agency_name` / การโอนระหว่างหน่วยงาน
  - `daily-financial-report` — `payment_channel` (⚠️ payments เดิม drop `payment_method` ไปแล้ว — flow เป็น slip-image-only) และ `receipts` เป็น FROZEN legacy
  - `check-in/out-report` — `special_request`, `early_checkin_extra_bed_note`, `is_inter_unit_transfer`
  - `manager-report` — template ไม่มี `columns` เลย (มีแค่ filters) → ยืนยันว่าต้นทางไม่ได้นิยามจริง
- แหล่งอ่าน: `database/migrations/`, `app/Models/`, `docs/database-er.md`, `docs/api_guide.md`, `cline.md` (หัวข้อ FROZEN/deprecated), `AGENTS.md` (Critical Conventions)

**Output:** `research/data-coverage-audit.md` (ตาราง audit + หลักฐานอ้างไฟล์ migration/คอลัมน์) + เขียน `## Resolution` ใน ticket นี้

## Resolution (2026-09-22 — research)

**ตาราง audit ครบ 15 รายงานอยู่ใน [`../research/data-coverage-audit.md`](../research/data-coverage-audit.md)** — สรุปผล:

- **1 ✅** (housekeeping-report v1) · **11 ⚠️** (ขาด field บางส่วน) · **3 ❌** (ไม่มีตารางรองรับเลย: supplies-report [⚠️ leaning ❌ — 8/11 columns ไม่มี], out-of-service-room-report, additional-charges-report)
- **Gap ที่เจ็บที่สุด (ผูกกับการเงิน):** slip flow (`booking_confirmations`) **ไม่เก็บยอดเงิน** — `received_amount`/`outstanding_amount` ของหลายรายงานจึงคำนวณไม่ได้จริง · `payment_channel` ถูก drop ไปแล้ว (`2026_08_19_100000`) · ไม่มี concept มัดจำ 50% · `receipts` FROZEN
- **Gap 12 กลุ่ม** ถูก graduate ออกจาก fog เป็น grilling tickets ใหม่: [08 payment/deposit](./08-payment-amount-channel-deposit.md) · [09 ERP/agency/booking attributes](./09-erp-agency-inter-unit-booking-attributes.md) · [10 addon product model](./10-addon-product-model-breakdown.md) · [11 room/supplies domain tables](./11-room-domain-new-tables.md)
- ไม่เป็น gap (derive ได้แล้ว): nights, guest names (guests JSON), slip photo (signed URL), room_type, booking_no, no_show timing (status_change_logs), ADR input
- manager-report: ยืนยันว่า template **ไม่มี columns จริง** — layout เป็น `metrics(20 แถว) × periods(6 คอลัมน์: Day/MTD/YTD + LY)`; metric `provisional` ไม่มีสถานะตรงเครื่อง, `complimentary_rooms` ไม่มี flag → ผูกกับ ticket 06 (นิยาม) + 09 (complimentary flag)
