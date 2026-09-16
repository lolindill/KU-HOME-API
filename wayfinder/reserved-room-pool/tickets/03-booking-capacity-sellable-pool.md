---
label: wayfinder:task
type: task
title: "Booking capacity checks — align เป็น sellable pool + flag extension (bug fix)"
status: open
assignee:
blocked-by: ["01-include-reserved-gate-and-availability"]
---

# 03: Booking capacity checks — align เป็น sellable pool + flag extension (bug fix)

**What to build:** แก้ bug denominator — การตรวจ capacity ของการจองทั้ง 3 ทาง (สร้าง booking / เพิ่มห้อง / แก้ห้อง) นับ denominator จาก **physical rooms ทั้งหมด** อยู่ ทำให้ user จองเกิน capacity ที่ขายได้จริงผ่าน แล้ว booking ค้างไม่มีห้อง assign — ให้ align เป็น **sellable pool** (ตัด `maintenance` + `reserved_closed`) ให้ตรงกับ display/allocator และเมื่อ **admin** ส่ง flag → denominator ขยายเป็น sellable + reserved · จบใบนี้ user จองเกินจริงไม่ผ่านอีกต่อไป และ admin วางแผนใช้ห้องสำรองใน booking ได้

**Blocked by:** 01-include-reserved-gate-and-availability

**Status:** ready-for-agent

- [ ] regression: user จองเกิน sellable capacity (ทั้งที่ physical มีมากกว่า) ต้อง 422 ไม่ผ่าน — ทั้ง 3 ทาง
- [ ] admin flag → denominator = sellable + reserved
- [ ] `maintenance` ไม่ถูกนับใน denominator ทุกกรณี
- [ ] ทั้ง 3 ทาง (create/add/edit) เดินกติกาเดียวกัน ใช้ gate resolution จาก 01
- [ ] booking tests เดิมยังเขียว (behavior no-flag เปลี่ยนเฉพาะทิศทางเข้มขึ้น — จองเกินกว่าจะ assign ได้อยู่แล้วไม่ควรมี test ล็อกไว้)
- [ ] feature tests ผ่าน HTTP seam
