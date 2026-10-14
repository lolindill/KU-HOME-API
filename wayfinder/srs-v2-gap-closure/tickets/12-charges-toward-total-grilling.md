# 12: Grill — ค่าปรับ/ค่าเสียหาย/charges กรอกเอง บวกยอดรวมจริง (REQ-042/044)

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** (ว่าง)
- **summary:** Grill owner กลไก charges ต่อห้อง/ต่อ booking ที่บวกยอดจริงโดยไม่พัง ledger/money chokepoint
- **phase:** 4

## Question

Owner ล็อกแล้ว (2026-10-14): ค่าปรับ/ค่าเสียหายกรอกเอง (item/ราคา/จำนวน) ต่อห้องและต่อ booking ต้อง**บวกเข้ายอดรวมจริง** — ปัจจุบัน `additional_charges` ผูก `booking_id` อย่างเดียว และเป็น report-only ("รายงานล้วน — ยอดไม่เข้า booking") ส่วน addon เป็น field โครง fixed — ตัดสินใจ 4 ข้อนี้ให้ชัดก่อนลงมือ:

1. **ยอดรวมเปลี่ยนยังไงโดยไม่พัง ledger:** แตะ `total_amount` ตรงๆ จะกระทบ `is_paid = SUM(payments) ≥ total`, `deposit` 50%, `outstanding_amount` — ทางเลือก: (a) mutate `total_amount` จริง (b) คง total เดิม + charge เข้ายอดค้างชำระแยกหมวด (c) ใช้ pattern surcharge เดิมจาก `booking-payment-types` ticket 10 (ส่วนต่างเข้ายอดค้างชำระ ไม่แตะ total) — ข้อไหน และเกิด booking ที่ confirm แล้ว/เข้าพักแล้วด้วยหรือเปล่า
2. **ผูกห้อง:** REQ-042 ต้องการ "รายห้องพัก" — ยกระดับ `additional_charges` เป็น polymorphic (booking | booking_room) + flag กลายเป็น chargeable หรือตารางใหม่ (จดเหตุผล + อย่าทำ report 15 ฉบับพัง — `additional-charges`, `daily-financial`, `manager` ต้องยังตรง)
3. **REQ-044 addon free-text:** เพิ่ม free-text item บน `addons` หรือรวมเป็นกลไกเดียวกับ charges ข้อ 2 (อย่าสร้างกลไกคู่ขนานสามตัว — addon โครง fixed, additional_charges, และตัวใหม่)
4. **การชำระ:** หลัง charge เพิ่มหลังจ่ายแล้ว — ผู้ใช้ส่งสลิปเพิ่มผ่าน confirm flow เดิมได้ไหม หรือเก็บสดหน้าเคาน์เตอร์ (`recordPayment`) เท่านั้น

**⚠️ ข้อห้ามข้าม (AGENTS.md):** ห้าม bypass `transitionStatus()`/ledger chokepoints · เงิน = integer baht · PgBoolean · อย่า mutate `total_amount` โดยไม่ได้ตัดสินข้อ 1 ชัดๆ

**Done เมื่อ:** Resolution ตอบครบ 4 ข้อ + ยกตัวอย่าง flow จริง 1 เคส (เช่น แก้วแตก 250 บาท หลัง check-in ของ org booking deferred) — ticket 13 ห้ามเริ่มก่อนปิด ticket นี้

## Resolution

(ยังว่าง — รอ grilling คู่กับ owner)
