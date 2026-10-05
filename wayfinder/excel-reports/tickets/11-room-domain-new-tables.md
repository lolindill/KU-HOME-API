---
label: wayfinder:grilling
type: HITL
title: โดเมนห้อง/สต๊อก — maintenance log, supplies tables, charges, VIP, room history
status: closed (2026-10-05 — grilling)
assignee: kevii
blocked-by: ["02-data-coverage-audit"]
---

# 11: โดเมนห้อง/สต๊อก — ตารางใหม่ที่จำเป็น

## Question

Audit ยืนยัน 3 รายงานไม่มีตารางรองรับเลย + รายงานอื่นตามหลอด (gap กลุ่ม 6, 7, 8, 10, 11) — ออกแบบ storage ใหม่ยังไง?

- **Room maintenance/repair log (out-of-service-room-report ❌):** report_date, work_type (ไฟฟ้า/ประปา/งานระบบ), repair_detail, fixed_date, duration_days — ผูกกับ `Room::transitionStatusTo()` (maintenance) อย่างไร + เก็บ OOO reason ใช้เป็น `note` ของ room-status-report ด้วย
- **Supplies/inventory (supplies-report ⚠️ 8/11):** `stock_inventories` เดิมมีแค่ item_name/quantity/unit/notes — ต้องเพิ่ม item master attributes (item_code, category, reorder_point, max_stock) + **movement ledger** (carried_over/received/issued ต่อ period) — ใช้ ledger + derive หรือเก็บ snapshot ต่องวด?
- **Additional charges (additional-charges-report ❌):** ค่าปรับ/ค่ายืมอุปกรณ์ — ตาราง charges ผูก booking_id (sample ทุกแถวมี booking_no), transaction_date, item, qty, unit, price, charge_type — ใครบันทึก (หน้าเคาน์เตอร์ตอน checkout?) และเข้ายอดเงิน booking ไหม (ถ้าเข้า กระทบ invariant `Σ amount == total_amount` — ประสานกับ ticket 08)
- **Room flags (room-status-report):** VIP / ห้องผู้บริหาร — column บน rooms? และ "Inspected" จะแยกจาก `prep_checkin` จริงไหม
- **Room status history:** `status_change_logs` ตั้งใจไม่รวม room (`entity_type` = booking|booking_room เท่านั้น) — occupancy/manager report ต้องการ OOO ย้อนหลังต่อวัน → ขยาย log ถึง room, ตาราง history ใหม่, หรือยอมรับ current-state-only ใน v1? *(🆕 [ticket 06](./06-manager-report-definition.md) ตัดสิน 2026-10-05: manager-report กลุ่มแถว OOO 4 แถวในคอลัมน์ MTD/YTD/LY รอ outcome ของ bullet นี้โดยตรง — Day แม่นด้วยสถานะปัจจุบันแล้ว, คอลัมน์ประวัติครบเมื่อตาราง history มา)*

**Precondition:** อ่าน audit §3.10, §3.12–3.14 + audit §4 gap 6, 7, 8, 10, 11 ก่อน

## Resolution

(2026-10-05 — grilling กับ owner ตรง · AskUserQuestion + คำตอบข้อความ · premise อ่านใหม่จากแมปพี่เลี้ยง [`room-state-periods`](../../room-state-periods/map.md) ที่ landed แล้ว 2026-09-24 — bullet "room status history" หมดคำถามก่อน grill)

**🛠️ Repair log — out-of-service-room-report + note OOO ใน room-status-report:**

- **ไม่มีตารางใหม่ — เพิ่ม 2 column บน `room_state_periods`** (owner เลือก "เพิ่ม column บน periods"): `work_type` (enum `ไฟฟ้า|ประปา|งานระบบ` — ตามชีต truth source) + `repair_detail` (text) — nullable ทั้งคู่ ใช้เฉพาะ kind=maintenance
- นิยาม column รายงาน: **วันที่แจ้งซ่อม = `start_date`** · **วันที่แก้ไขเสร็จ = `end_date`** (period เปิดปลาย = ยังไม่เสร็จ แสดง "-") · **duration = end − start derive** · แถวรายงาน = maintenance period ที่ start_date อยู่ในช่วง filter + filter `work_type` ตรง enum
- note OOO ของ room-status-report ("ปรับปรุงห้องน้ำ") = อ่าน `repair_detail` ของ maintenance period ที่ active วันรายงาน
- CRUD ใช้ route periods ที่ landed แล้ว (`GET/POST /rooms/{roomId}/periods` + PATCH/DELETE — สิทธิ์ maintenance staff ตาม ticket 04 ของแมป periods) · audit ผ่าน entity_type `room_state_period` เดิม — ไม่แตะ chokepoint ใหม่

**💰 Additional charges — additional-charges-report:**

- ตารางใหม่ `additional_charges`: `booking_id` FK (restrict), `transaction_date`, `item_code` (nullable free text — ชีต sample เป็น placeholder "xxxx"), `item_name`, `qty`, `unit`, `price` (integer บาท — money_baht), `charge_type` (enum `damage ค่าเสียหาย | rental ค่ายืม`), `recorded_by`, timestamps
- **ยอดไม่เข้า booking** (owner ตัดสิน "ไม่เข้า — ledger รายงาน"): เป็น ledger เพื่องานรายงานล้วน — invariant `Σ booking_rooms.amount == total_amount` + ชั้น A/B ของแมป booking-payment-types **ไม่ถูกแตะ** · เก็บเงินสดที่เคาน์เตอร์แยกจาก booking (ไม่ไหลเข้า `payments` — ledger นั้นนิยาม "เงินของ booking"; รายงานการเงินที่ต้องการยอดค่าปรับ list จากตารางนี้แยกหมวดตอน implement)
- ใครบันทึก = **admin + staff ผ่าน API เฉพาะ บันทึกอิสระทุกเมื่อ** *(⚠️ default จาก agent — owner ไม่ได้ตัดสินข้อนี้ ติดธง sign-off ใน ticket 07)* — ไม่ผูก flow checked_out (flow เดิมไม่มีขั้นเก็บเงินเพิ่ม) ตรง primary_users ของชีต (พนักงานหน้าเคาน์เตอร์ + แอดมิน)
- filter `item_type` = `charge_type` · summary `total_items` + `total_amount_baht` = ผลรวมช่วง

**🧴 Supplies — supplies-report: "เอาไว้ก่อน" (owner defer 2026-10-05):**

- **ไม่ออกแบบตารางใหม่ใน v1** — รายงานยังอยู่ครบ 15 routes แบบ **minimal**: โชว์จาก `stock_inventories` เดิม (item_name / unit / quantity→remaining) · column ที่ไม่มีตารางรอง (item_code, category, carried_over, received, issued, reorder_point, max_stock, status) **เว้นว่าง**
- design movement ledger + item master (item_code/category/reorder_point/max_stock + `stock_movements` in/out) ผลักเป็น fog ของแมป — graduate เมื่อ owner วงข้อมูลจริง (ชีตต้นทางเองก็ยังไม่กรอกรับเข้า/เบิกจ่าย/คงเหลือ)

**🚩 Room flags — VIP / Inspected:**

- **VIP: "ไว้ก่อน" (owner defer 2026-10-05)** — ไม่เพิ่ม storage ใน v1 · room-status-report legend เหลือ **5 ค่า OCC/OOO/VC/VD/EA** (แมปจาก rooms.status/periods/booking spans ครบแล้ว) · ห้องผู้บริหารโชว์สถานะปกติของมัน (ว่าง = VC) · อนาคตถ้าอยากได้ป้าย = เพิ่ม boolean บน `rooms` + branch เดียวในรายงาน (fog จดไว้)
- **Inspected: derive จาก `prep_checkin` คงเดิม** *(⚠️ default จาก agent ตาม audit ระดับ ✅ — owner ไม่ได้ตัดสิน ติดธง sign-off ใน ticket 07)* — available→Clean, dirty→Dirty, prep_checkin→Inspected · ไม่แตะ state machine 5 สถานะ

**🗓️ Room status history (gap 11) — หมดคำถามก่อน grill เพราะแมปพี่เลี้ยง landed แล้ว:**

- `status_change_logs` entity_type **`room`** (REQ-039, landed 24/09/24) เขียนใน `Room::transitionStatusTo()` chokepoint เดียว — retention 1 ปี ผ่าน `app:cleanup-status-logs` (02:45)
- ประวัติ OOO/ห้องสำอง = ตาราง `room_state_periods` เอง (start/end + audit entity_type `room_state_period`) — **occupancy-report กับ manager-report กลุ่มแถว OOO ย้อนหลัง (ticket 06 คอลัมน์ MTD/YTD/LY ที่รอ outcome ของ bullet นี้) derive จาก scope `overlapping`/`activeOn` kind=maintenance ได้ทันที — ปลดล็อก**
- premise เดิมของ ticket ("status_change_logs ไม่รวม room", "สถานะ maintenance บน machine") หมดอายุ — audit 22/09 เขียนก่อน work ของแมป room-state-periods

**ผลต่อ spec (ticket 07):** ตาราง/สคีมาใหม่จริงมี 2 ก้อน — ตาราง `additional_charges` + 2 column บน `room_state_periods` (`work_type`, `repair_detail`) · supplies = minimal, VIP = deferred (ติดธง) · default ที่รอ owner sign-off: ผู้บันทึก charges + นิยาม Inspected
