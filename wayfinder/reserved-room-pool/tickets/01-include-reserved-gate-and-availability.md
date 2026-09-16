---
label: wayfinder:task
type: task
title: "Param gate helper + availability summary endpoint — tracer bullet"
status: open
assignee:
blocked-by: []
---

# 01: Param gate helper + availability summary endpoint (tracer bullet)

**What to build:** เส้นทางแนวตั้งเส้นแรกจนจบ — `include_reserved` บน summary availability endpoint ทำงานครบ contract: **admin** ส่ง flag → ตัวเลข pool รวม `reserved_closed` (available_rooms = sellable + reserved − booked แทนที่ตัวเดิม) พร้อม field โปร่งใส `sellable_rooms` / `reserved_rooms` + search criteria บันทึกว่า flag ถูกใช้ + king counters (`king_total_rooms` ฯลฯ) เดินกฎเดียวกัน · **non-admin/anonymous** ส่ง flag → เมยายีเงียบ ๆ payload เท่า no-flag · **ไม่ส่ง flag** → byte-identical กับพฤติกรรมปัจจุบัน · `maintenance` ไม่เข้า pool ทุกกรณี

ใบนี้ยังต้องสร้าง **shared gate resolution** ตัวเดียว (flag effective เมื่อ authenticated role = admin เท่านั้น — เช็ค in-controller เพราะ availability เป็น public route) ให้ tickets 02–05 เอาไปใช้ต่อ — ห้าม copy-paste logic กระจาย

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] admin flag → `available_rooms` ถูกแทนที่ด้วย extended pool + breakdown fields + criteria ระบุ flag
- [ ] king counters เดิน extended pool ตรงกับตัวเลขรวม
- [ ] non-admin + anonymous ส่ง flag → response ตรงกับ no-flag ทุกไบต์ (ignore contract)
- [ ] ไม่ส่ง flag → byte-identical กับเดิม (regression)
- [ ] `maintenance` ไม่ถูกนับใน pool ใด ๆ ทั้ง flag/no-flag
- [ ] gate resolution อยู่จุดเดียว reuse ได้ (02–05 ต้องไม่ implement ซ้ำ)
- [ ] feature tests ผ่าน HTTP seam (รูปแบบ king-size availability test — รวมเคส byte-identical)
