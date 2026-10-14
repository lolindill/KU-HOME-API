# 13: Implement — charges ต่อห้อง/ต่อ booking บวกยอดจริง (จาก ticket 12)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** [12-charges-toward-total-grilling](./12-charges-toward-total-grilling.md)
- **assignee:** (ว่าง)
- **summary:** Implement charges กรอกเองต่อห้อง/ต่อ booking บวกยอดตาม design ticket 12 + tests + reports
- **phase:** 4

## Question

ลงมือ implement ตาม design ที่ปิดแล้วใน [ticket 12](./12-charges-toward-total-grilling.md) — ห้ามเปลี่ยน decision:

- Schema ตาม Resolution (polymorphic หรือตารางใหม่) + FormRequest (item, ราคา, จำนวน — integer baht) + endpoints ให้ admin จัดการ (สร้าง/แก้/ลบ ก่อนมีการชำระตามเงื่อนไขที่ล็อก)
- กลไกยอดตาม Resolution ข้อ 1 — แตะเฉพาะ accessors/fields ที่ Resolution อนุญาต (`total_amount`/`outstanding_amount`/`is_paid`) · จุดชำระตาม Resolution ข้อ 4
- **รายงานต้องยังตรง:** ปรับ `AdditionalChargesReportData`, `DailyFinancialReportData`, `ManagerReportData` ให้สะท้อนยอดใหม่ได้ถูกหมวด (เงินเข้า/ค้างชำระ) — suite excel reports เขียวต่อ
- Tests: charge ต่อห้อง + ต่อ booking · ยอดรวม/ค้างชำระเปลี่ยนตาม design · ชำระตาม flow ที่ล็อก · สิทธิ์ · regression is_paid/deposit เดิมไม่เปลี่ยนเมื่อไม่มี charge
- `php artisan test` เขียวทั้ง suite · อัปเดต `docs/api_guide.md` + deviation ledger (REQ-042/044 ปิด) + จด `cline.md`

## Resolution

(ยังว่าง)
