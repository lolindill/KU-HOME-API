---
label: wayfinder:task
type: task
title: "rates object บน RoomType + endpoint แรก (detail) — tracer bullet"
status: closed
assignee: maid
blocked-by: ["01-satang-to-baht-helper"]
---

# 02: rates object บน RoomType + endpoint แรก (detail) — tracer bullet

**What to build:** RoomType ถูก serialize พร้อม `rates` object ใหม่ (baht string ทั้งก้อน) แทน integer `daily_rate` เดิม — ตัดเส้นทางแนวตั้งเส้นแรกจนจบ: relationships ไป global_rates (daily / daily_ku / group+code / month) แบบ eager-load ได้, accessor คำนวณผ่าน helper จาก 01, และ `GET /room-types/{id}` โชว์ของจริงพร้อม feature test

**Blocked by:** 01-satang-to-baht-helper

**Status:** closed

- [x] `GET /room-types/{id}` คืน shape นี้ (จาก spec/session — decision-rich shape):

  ```json
  "rates": {
    "daily":   { "general": "1000.00", "ku_member": "800.00" },
    "group":   { "min_5_rooms": "750.00", "min_10_rooms": "750.00" },
    "monthly": "15000.00"
  }
  ```

- [x] `extra_bed_price` ถูก serialize เป็น baht string เช่น `"500.00"` (storage ยัง integer satang)
- [x] rate row หายหรือ inactive → `"0.00"` ทุกช่องที่เกี่ยวข้อง (fallback เดียวกับ room-rate lookup เดิม)
- [x] integer `daily_rate` ที่ append เดิมหายไปจาก response ทุกจุดที่ model ถูก serialize
- [x] relation objects ทั้งหมดถูก hide ออกจาก JSON; eager-load ได้โดยไม่ N+1
- [x] feature test (HTTP seam): shape, ค่า baht string เป๊ะ, extra_bed_price, zero-fallback (สร้าง room type ไม่มี rate rows)
- [x] endpoint อื่นที่ยังไม่แตะในใบนี้ต้องไม่พัง (CI เขียว)

## Resolution

- อัปเดต `RoomType`: `$appends = ['rates']`, ซ่อน `dailyRateRow` และ `rateRows`, เพิ่ม accessor `rates` ดึง active rows จาก `rateRows` ผ่าน helper `Money::satangToBaht`
- เพิ่ม accessor + mutator `extra_bed_price` แปลงเป็น baht string ที่ edge และเก็บ integer satang
- อัปเดต `GlobalRate::getRoomRate` รองรับ `$code` สำหรับ group rates
- อัปเดต `GlobalRateController::update` ให้รองรับ `daily_ku` และเก็บ `code` สำหรับ group rates
- อัปเดต `RoomController::getRoomTypeById` ให้ eager-load `rateRows`
- เพิ่ม Feature tests ใน `RoomTest`: `test_get_room_type_by_id_returns_rates_object_and_baht_strings` และ `test_get_room_type_by_id_falls_back_to_zero_for_missing_or_inactive_rates` ผ่านฉลุย 100%
