---
label: wayfinder:grilling
type: HITL
title: Addon product model — breakfast แยกชุด 100/200, extra-bed รายคืน, checklist แม่บ้าน
status: open
assignee:
blocked-by: ["02-data-coverage-audit"]
---

# 10: Addon product model breakdown

## Question

Audit พบว่า addons เก็บเป็นตัวเลขเดียวต่อทั้ง stay (gap กลุ่ม 4, 5, 9) — โมเดลข้อมูลฝั่ง product ต้องละเอียดขึ้นแค่ไหน?

- **Breakfast ชุด 100 / ชุด 200:** `addons.breakfast` เป็น int เดียว — breakfast-report ต้องแยก `qty_set_100`/`qty_set_200` + filter `breakfast_type` → ต้องเก็บตามชุดตั้งแต่ตอนจอง (โครงสร้างใหม่บน addons: split เป็น 2 field? child rows? config-driven set types?)
- **Extra bed รายคืน (`beds_by_night` type `integer_by_date`):** `addons.extra_bed` เป็น int เดียวทั้ง stay — extra-bed-report ต้องการจำนวนเตียงต่อคืน (คอลัมน์ dynamic ตามวันที่) → เก็บ per-night ยังไง + ยอด fleet inventory (summary `total_inventory` = 35 เตียง) เก็บที่ไหน
- **ผลต่อ pricing:** ถ้าแยกชุด/รายคืน การคิดเงิน (`breakfast_price`, `extra_bed_price`, `room_amount`/`amount` ผ่าน `reprice()`) ต้อง conform อย่างไร — ห้ามทำ invariant `Σ amount == total_amount` พัง
- **`cleaning_check_1/2/3`:** housekeeping_tasks ไม่มี boolean checklist — หัวข้อตรวจคืออะไรบ้าง (template notes บอกว่ารอ owner ยืนยัน) เก็บ generic (JSON) หรือ 3 columns ตรง ๆ

**Precondition:** อ่าน audit §3.6, §3.9, §3.11 + audit §4 gap 4, 5, 9 ก่อน
