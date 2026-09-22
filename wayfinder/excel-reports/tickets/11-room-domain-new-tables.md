---
label: wayfinder:grilling
type: HITL
title: โดเมนห้อง/สต๊อก — maintenance log, supplies tables, charges, VIP, room history
status: open
assignee:
blocked-by: ["02-data-coverage-audit"]
---

# 11: โดเมนห้อง/สต๊อก — ตารางใหม่ที่จำเป็น

## Question

Audit ยืนยัน 3 รายงานไม่มีตารางรองรับเลย + รายงานอื่นตามหลอด (gap กลุ่ม 6, 7, 8, 10, 11) — ออกแบบ storage ใหม่ยังไง?

- **Room maintenance/repair log (out-of-service-room-report ❌):** report_date, work_type (ไฟฟ้า/ประปา/งานระบบ), repair_detail, fixed_date, duration_days — ผูกกับ `Room::transitionStatusTo()` (maintenance) อย่างไร + เก็บ OOO reason ใช้เป็น `note` ของ room-status-report ด้วย
- **Supplies/inventory (supplies-report ⚠️ 8/11):** `stock_inventories` เดิมมีแค่ item_name/quantity/unit/notes — ต้องเพิ่ม item master attributes (item_code, category, reorder_point, max_stock) + **movement ledger** (carried_over/received/issued ต่อ period) — ใช้ ledger + derive หรือเก็บ snapshot ต่องวด?
- **Additional charges (additional-charges-report ❌):** ค่าปรับ/ค่ายืมอุปกรณ์ — ตาราง charges ผูก booking_id (sample ทุกแถวมี booking_no), transaction_date, item, qty, unit, price, charge_type — ใครบันทึก (หน้าเคาน์เตอร์ตอน checkout?) และเข้ายอดเงิน booking ไหม (ถ้าเข้า กระทบ invariant `Σ amount == total_amount` — ประสานกับ ticket 08)
- **Room flags (room-status-report):** VIP / ห้องผู้บริหาร — column บน rooms? และ "Inspected" จะแยกจาก `prep_checkin` จริงไหม
- **Room status history:** `status_change_logs` ตั้งใจไม่รวม room (`entity_type` = booking|booking_room เท่านั้น) — occupancy/manager report ต้องการ OOO ย้อนหลังต่อวัน → ขยาย log ถึง room, ตาราง history ใหม่, หรือยอมรับ current-state-only ใน v1?

**Precondition:** อ่าน audit §3.10, §3.12–3.14 + audit §4 gap 6, 7, 8, 10, 11 ก่อน
