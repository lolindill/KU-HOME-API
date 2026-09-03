---
label: wayfinder:task
type: task
title: rates บน 4 calendar endpoints
status: closed
assignee: maid
blocked-by: ["02-roomtype-rates-accessor"]
---

# 04: rates บน 4 calendar endpoints

**What to build:** ปฏิทินฝั่ง frontend โชว์ราคาได้โดยไม่ต้องยิง fetch สองรอบ — row ของแต่ละ room type ใน calendar endpoints ทั้งสี่ (availability-per-day, availability-ranges, unavailable-dates, availability-ranges-all) มี `rates` object ติดมาด้วย โดย eager-load rate rows ครั้งเดียวต่อ request (กัน N+1 — endpoints เหล่านี้ map row เองใน controller จึงต้องใส่ field ชัด ๆ)

**Blocked by:** 02-roomtype-rates-accessor

**Status:** closed

- [x] ทั้ง 4 endpoints: ทุก room-type row มี `rates` (baht string ครบ daily/group/monthly)
- [x] rate rows ถูก eager-load — จำนวน query ไม่พุ่งตามจำนวน room types (assert ผ่าน query count หรือเทียบเท่า)
- [x] โครง response เดิม (date keys / intervals / search window) ไม่เปลี่ยน — เพิ่ม field เดียวเท่านั้น
- [x] mock availability-ranges endpoint ไม่ถูกแตะ (อยู่ใน deletion plan แยก)
- [x] feature tests ราย endpoint ครบทั้งสี่

## Resolution

- ตรวจสอบ `RoomController.php` ทั้ง 4 endpoints (`availabilityPerDay`, `availabilityRanges`, `unavailableDates`, `unavailableRanges`):
  - ทุก method ทำการ eager-load `rateRows` ผ่าน `RoomType::withCount(...)->with('rateRows')->get()` ป้องกัน N+1 query อย่างสมบูรณ์
  - ทุก room-type row ใน response mapper คืน `'rates' => $type->rates` เป็น canonical rates object สตริงบาททศนิยม 2 ตำแหน่ง (`daily: {general, ku_member}`, `group: {min_5_rooms, min_10_rooms}`, `monthly`)
  - โครงสร้างเดิมของทั้ง 4 endpoints (date keys, intervals, search window, status, message) คงเดิมครบถ้วน
  - mock endpoint `/mock/availability-ranges` ไม่ถูกแตะต้อง
- สร้าง Feature Test `tests/Feature/RoomTypeRatesCalendarTest.php` ครอบคลุม:
  1. `test_availability_per_day_includes_rates_object_in_2dp_baht_strings`
  2. `test_availability_ranges_includes_rates_object_in_2dp_baht_strings`
  3. `test_unavailable_dates_includes_rates_object_in_2dp_baht_strings`
  4. `test_unavailable_ranges_includes_rates_object_in_2dp_baht_strings_when_no_bookings`
  5. `test_unavailable_ranges_includes_rates_object_in_2dp_baht_strings_with_active_bookings`
  6. `test_rates_fall_back_to_zero_strings_when_no_rate_rows_exist`
  7. `test_rate_rows_are_eager_loaded_and_query_count_does_not_scale_with_room_types` (ยืนยันผ่าน `DB::enableQueryLog()` ว่า query count คงที่และ eager load ตาราง `global_rates` ครั้งเดียวผ่าน `WHERE room_type_id IN (...)`)
- รัน `php artisan test --filter=RoomTypeRatesCalendarTest` ผ่านครบทั้ง 7 tests (57 assertions) 100% เขียวบริสุทธิ์ ✨

