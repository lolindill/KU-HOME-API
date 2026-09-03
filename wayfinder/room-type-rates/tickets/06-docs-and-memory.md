---
label: wayfinder:task
type: task
title: Docs + project memory (api_guide / cline.md)
status: closed
assignee: maid
blocked-by: ["03-rates-list-and-availability", "04-rates-calendar-endpoints", "05-seeder-rate-card"]
---

# 06: Docs + project memory (api_guide / cline.md)

**What to build:** สัญญา (contract) ที่ integrator อ่านตรงกับของจริง — API guide สะท้อน `rates` object (baht string) บนทั้ง 7 endpoints + rate types ใหม่ + money policy ("storage satang / wire baht ที่ขอบ room-type") แก้ตัวอย่าง `daily_rate` เก่าที่สอดขึ้นกัน (baht-scale บ้าง satang-scale บ้าง) ให้หมด และ project memory จด decision + Migration Required — ปิดท้ายให้ทั้ง effort ตรวจยอดรวมได้จากเอกสาร

**Blocked by:** 03-rates-list-and-availability, 04-rates-calendar-endpoints, 05-seeder-rate-card

**Status:** closed

- [x] API guide: ตัวอย่าง response ทั้ง 7 endpoints มี `rates` แบบ baht string ตรง wire จริง (list / detail / availability embed / per-day / ranges / unavailable-dates / ranges-all)
- [x] document rate types ใหม่ใน global_rates: `daily_ku`, `group` (code min_5_rooms/min_10_rooms), `month` + fallback "0.00"
- [x] ลบ/แก้ตัวอย่าง `daily_rate` integer เก่าทุกจุดให้เหลือแบบใหม่เดียว
- [x] ระบุ money policy ชัดใน guide: storage integer satang · room-type wire เป็นบาท string · ฝั่ง booking ยัง satang (out of scope ใน effort นี้)
- [x] project memory (cline.md): decision สรุป (จาก grill session) + **Migration Required: `migrate:fresh --seed`**
- [x] spec ใน tracker (`wayfinder/room-type-rates/spec.md`) status อัปเดตหลังทุกใบเสร็จ (ปิด effort)

## Resolution

- อัปเดต `docs/api_guide.md` ทั้ง 7 endpoints ให้แสดง `rates` object (2-dp baht string) และ `extra_bed_price` (baht string)
- อัปเดต `DB Models Reference` ของ `RoomType` ใน `docs/api_guide.md` พร้อมระบุ Money policy และอัตราเรทใหม่ทั้งหมด
- อัปเดต `cline.md` บันทึก architecture decision, การเปลี่ยนแปลงทุกไฟล์, และข้อกำหนด Migration Required (`migrate:fresh --seed`)
- อัปเดต `wayfinder/room-type-rates/spec.md` และ `map.md` เป็น closed
