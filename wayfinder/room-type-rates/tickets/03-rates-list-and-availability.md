---
label: wayfinder:task
type: task
title: rates บน room-type list + availability search
status: closed
assignee: maid
blocked-by: ["02-roomtype-rates-accessor"]
---

# 03: rates บน room-type list + availability search

**What to build:** consumer ได้ `rates` object เดียวกันจากการ list ห้องทั้งหมดและจากผลค้นหาห้องว่าง — ทั้งสอง endpoint serialize ผ่าน RoomType model อยู่แล้ว จึงได้ของจริงมาพร้อมใบ 02 ที่เหลือคือตรวจผ่านหน้า end-to-end และปิดด้วย feature tests ให้ครบทั้ง list และ embed

**Blocked by:** 02-roomtype-rates-accessor

**Status:** closed

- [x] `GET /room-types` ทุก item มี `rates` (baht string) ครบทั้ง daily/group/monthly
- [x] `GET /availability` — ทั้ง top-level row และ embed `room_type` ให้ `rates` เดียวกัน (`search_criteria` / `available_rooms` ไม่ถูกกระทบ)
- [x] feature tests ครอบทั้งสอง endpoint (รวม assert ว่า embed ไม่แตกเวลา rate row บางประเภทหาย)

## Resolution

- ตรวจสอบ `RoomController::allRoomTypes` และ `RoomController::availability` พบว่าทั้งสอง endpoint ใช้งาน `rates` accessor และ eager load `rateRows` ตาม architecture ที่วางไว้ใน Ticket 02
- สร้าง Feature Test `tests/Feature/RoomTypeRatesListTest.php` ทดสอบ 3 กรณีอย่างละเอียด (81 assertions ผ่าน 100%):
  1. `test_all_room_types_endpoint_returns_rates_object_and_extra_bed_price_in_baht_string`: ตรวจสอบว่าทุก item มี `rates` (daily, group, monthly) และ `extra_bed_price` เป็น 2-dp decimal baht string, ซ่อน relations ทั้งหมด และไม่มี `daily_rate` ตกค้าง
  2. `test_availability_endpoint_returns_identical_rates_in_top_level_and_embedded_room_type`: ตรวจสอบว่า top-level row และ embedded `room_type` มี `rates` object ตรงกันทุกประการ, `daily_rate` ไม่ปรากฏทั้งสองจุด, และ `available_rooms` กับ `search_criteria` ทำงานถูกต้อง
  3. `test_availability_endpoint_falls_back_to_zero_when_rate_rows_are_missing_or_inactive`: ตรวจสอบว่าหาก rate rows ขาดหายหรือ inactive ระบบจะ fallback เป็น `"0.00"` สม่ำเสมอ และ embedded `room_type` ไม่แตก (graceful fallback)
- รัน `php artisan test --filter=RoomTypeRatesListTest` ผ่านฉลุย 100% (3 passed, 81 assertions)
