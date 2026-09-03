---
label: wayfinder:task
type: task
title: Seeder — rate card จริง (เขียนแบบบาท เก็บ satang)
status: closed
assignee: maid
blocked-by: ["02-roomtype-rates-accessor"]
---

# 05: Seeder — rate card จริง (เขียนแบบบาท เก็บ satang)

**What to build:** environment ที่ fresh seed แล้วมีราคาจริงครบทุกประเภททันที — room-type seeder สร้าง 5 rate rows ต่อ room type ใน global_rates (daily / daily_ku / group ×2 ด้วย code / month) โดยตัวเลขใน seeder เขียนเป็น **บาททศนิยมแบบอ่านง่าย** แล้วบันทึกเป็น satang (×100) พร้อมแก้ `extra_bed_price` ให้ตรงฐาน satang — demo จบเส้น: seed แล้วยิง `/room-types` เห็นการ์ดราคาบาทครบ

**Blocked by:** 02-roomtype-rates-accessor

**Status:** closed

- [x] ต่อ room type ได้ครบ 5 rows: `daily` · `daily_ku` · `group` (code min_5_rooms/min_10_rooms) · `month` — ค่า authored ตาม spec: Superior 1000/800/750/750/15000 · Deluxe 1200/1000/900/750⚠️/18000 · Suite 1800/1500/1350/1350/27000 (บาท)
- [x] ค่าใน DB เป็น integer satang (×100) — seeder seam test assert ผ่าน room-rate lookups ทีละ rate_type/code
- [x] `extra_bed_price`: 0 / 50,000 / 60,000 satang (Deluxe ตรงกับ addon extra_bed 50,000)
- [x] ⚠️ Deluxe `min_10_rooms` = 750.00 คงตาม mock ตาม spec (นายท่านแก้เลขได้ก่อนลงมือถ้าพิมพ์ผิดจริง)
- [x] seeder อื่น (addon rates / discount) ไม่ถูกแตะ
- [x] ต้องรัน `migrate:fresh --seed` — จดลง project memory ในใบ 06

## Resolution

- **Database Migration:** สร้าง migration `2026_09_03_110000_drop_code_unique_from_global_rates_table.php` เพื่อ drop unique constraint เดิม (`addon_rates_code_unique`) บนคอลัมน์ `code` ในตาราง `global_rates` ทำให้แต่ละ RoomType สามารถมี group rate codes ซ้ำกันได้ (`min_5_rooms`, `min_10_rooms`)
- **Seeder:** อัปเดต `database/seeders/RoomSeeder.php`
  - กำหนดราคา authored เป็นเลขบาททศนิยมที่อ่านง่าย แล้วแปลงเป็น satang integer (`×100`) ผ่าน `$toSatang` helper
  - สร้าง 5 rate rows ต่อ room type (`daily`, `daily_ku`, `group` with `min_5_rooms`, `group` with `min_10_rooms`, `month`) ลงใน `global_rates`
  - ตั้งค่า `code` สำหรับ group rates ('min_5_rooms', 'min_10_rooms') และเป็น null สำหรับอัตราอื่น ๆ
  - กำหนด `extra_bed_price` บันทึกเป็น satang integer: Superior 0, Deluxe 50000 (500.00 THB), Suite 60000 (600.00 THB)
  - ไม่แตะต้อง seeder อื่น ๆ (GlobalRateSeeder, UserSeeder, DiscountSeeder)
- **Automated Tests:**
  - สร้าง `tests/Feature/RoomSeederTest.php` ทดสอบการรัน `RoomSeeder::class` และ assert อัตราค่าห้องทั้ง 5 rate rows ต่อ room type ในหน่วย satang ผ่าน `GlobalRate::getRoomRate($type, $rateType, $code)`, assert `extra_bed_price` ในหน่วย satang บน DB/raw model, และตรวจสอบ `code` column
  - รัน `php artisan test --filter=RoomSeederTest` ผ่าน 100% (61 assertions)
  - รันชุดทดสอบทั้งหมด `php artisan test` ผ่านฉลุย 356 tests (1164 assertions)
  - รัน Pint code style linter ผ่านเรียบร้อย
