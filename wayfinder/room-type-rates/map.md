---
label: wayfinder:map
title: "Room-type `rates` object — baht wire บน room-type APIs (storage satang)"
status: closed
---

# Wayfinder Map — Room-type `rates` object

## Destination

[spec.md](spec.md) (label `ready-for-agent`) implement จบ: `rates` object ครบทั้ง 7 endpoints ที่คืน room type (wire เป็นบาท 2-dp string · storage integer satang) + seeder rate card ครบ 5 rows ต่อ type + api_guide/cline.md สะท้อนจริง — tickets 01–06 ถูก tick ครบทั้งหมดแล้ว (ปิดงานเรียบร้อย 2026-09-03)

## Notes

- **Domain:** KU HOME API — money storage เป็น **integer satang** (convention เดิม ไม่เปลี่ยน) · **wire ฝั่ง room-type เป็นบาท 2-dp string** (user-confirmed 2026-09-03 หลังพิจารณา 3 ทาง: คง satang / baht ทั้งระบบ / baht ที่ขอบ)
- **Tracker = local-markdown** (เครื่องนี้ไม่มี `gh` CLI): map ที่ไฟล์นี้, tickets ใน `tickets/` · claim ticket = เติม `assignee:` ใน front-matter ก่อนลงมือ · blocking = front-matter `blocked-by` · ปิด ticket = `status: closed` + เขียน `## Resolution` ท้ายไฟล์
- Decision ทั้งหมดอยู่ใน [spec.md](spec.md) — map เป็น index ไม่ restate
- ไม่มี skill `grilling`/`domain-modeling` บนเครื่อง — งานแนวเดียวกันใช้ `grill-me`; งานสำรวจใช้ Explore agent
- Test seams ที่ยืนยันแล้ว: HTTP feature seam (7 endpoints) + seeder seam — ไม่เพิ่ม seam ใหม่

## Decisions so far

- [spec § Solution / Implementation Decisions](spec.md): shape เดียว `rates = {daily{general,ku_member}, group{min_5_rooms,min_10_rooms}, monthly}` — personnel → `ku_member` (ตาม role), snake_case, baht string ที่ wire
- [spec § Money policy](spec.md): storage integer satang คงเดิม (booking/`amount` ที่เพิ่ง ship ไม่ถูกแตะ) — baht เฉพาะขอบ room-type API + helper กลางรองรับ
- [spec § Seeder](spec.md): rate card ตาม mock ฝั่ง front (เขียนบาท เก็บ satang ×100) + `extra_bed_price` แก้เป็น satang · ทางเดินข้อมูล = fresh seed เท่านั้น ไม่มี data migration
- [spec § Testing Decisions](spec.md): 2 seams ของเดิม (HTTP feature + seeder) ยืนยันโดยผู้ใช้ — ไม่มี unit seam แยกให้ accessor
- [tickets/](tickets/): breakdown 6 ใบอนุมัติและเสร็จสิ้นแล้ว — 01 helper (prefactor) → 02 tracer bullet → 03/04/05 ขนานได้ → 06 docs ปิดท้าย

## Not yet specified

- ⚠️ Deluxe `group.min_10_rooms` = 750.00 (ถูกกว่า min_5 = 900) ดำเนินการตาม mock ของ frontend และ spec ที่ได้รับอนุมัติ

## Out of scope

- แปลง wire เป็นบาทฝั่ง booking totals / `booking_rooms.amount` / addons / discounts (คง satang — ขยาย helper ภายหลังถ้าต้องการ)
- สิทธิ์ ku_member ตอนคิดเงิน booking / group-monthly pricing เข้าสู่ยอดจริง
- Data migration สำหรับ env เก่า (ปรับผ่าน admin API) · ลบ mock availability-ranges endpoint · แก้ repo frontend (ku-home)
