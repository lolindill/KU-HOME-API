---
label: wayfinder:task
type: AFK
title: รวบ decisions → spec.md ฉบับ ready-for-agent
status: open
assignee:
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
