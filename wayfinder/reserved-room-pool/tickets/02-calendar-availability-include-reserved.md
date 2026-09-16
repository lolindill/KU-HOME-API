---
label: wayfinder:task
type: task
title: "Calendar availability (per-day + ranges) รับ include_reserved"
status: open
assignee:
blocked-by: ["01-include-reserved-gate-and-availability"]
---

# 02: Calendar availability (per-day + ranges) รับ `include_reserved`

**What to build:** endpoint ปฏิทินทั้งสองรูป (per-day calendar + ranges) รองรับ flag แบบเดียวกับ summary endpoint ทุกด้าน — **admin** ส่ง flag → จำนวนห้องว่างรายวัน/ช่วงวัน นับจาก pool ที่รวม `reserved_closed` · **non-admin/anonymous** → เมยายีเงียบ ๆ · **ไม่ส่ง flag** → byte-identical · `maintenance` ตัดทิ้งเสมอ — ใช้ gate resolution จาก 01 ไม่ implement ซ้ำ

**Blocked by:** 01-include-reserved-gate-and-availability

**Status:** ready-for-agent

- [ ] admin flag บนทั้งสอง endpoint → ตัวเลขรายวัน/ช่วงวันนับ pool รวม reserved
- [ ] semantics ตรงกันกับ summary endpoint (กติกาเดียวกันทุกด้าน — admin dashboard ใช้แทนกันได้)
- [ ] non-admin + anonymous → response เท่า no-flag ทุกไบต์
- [ ] ไม่ส่ง flag → byte-identical กับเดิม
- [ ] `maintenance` ไม่ถูกนับทุกกรณี
- [ ] feature tests ผ่าน HTTP seam
