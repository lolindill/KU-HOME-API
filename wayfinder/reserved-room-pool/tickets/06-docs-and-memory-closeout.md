---
label: wayfinder:task
type: AFK
title: "Docs + memory closeout (api_guide / cline.md / tracker)"
status: open
assignee:
blocked-by: ["02-calendar-availability-include-reserved", "03-booking-capacity-sellable-pool", "04-allocator-assign-rooms-include-reserved", "05-walk-in-include-reserved"]
---

# 06: Docs + memory closeout (api_guide / cline.md / tracker)

**What to build:** สรุปและบันทึกเอกสารระบบเมื่อ implementation ครบถ้วนทุก surface ให้สะท้อนความจริงในโค้ด:
1. **API Guide (`docs/api_guide.md`):**
   - บันทึก contract ของ `include_reserved` ครบทั้ง 5 surfaces (summary availability, per-day calendar, ranges, booking create/edit capacity, auto-assign, walk-in)
   - บันทึกพฤติกรรม response payload ภายใต้ flag: การแทนที่ `available_rooms` ด้วย extended pool + ฟิลด์โปร่งใส `sellable_rooms` และ `reserved_rooms`
   - บันทึกพฤติกรรม Ignore Contract: Non-admin ส่ง flag จะถูกเมินเฉยเงียบ ๆ ไม่เกิด 403 และได้ผลลัพธ์เหมือนเดิม
   - บันทึก **Bug Fix Denominator**: อธิบายว่า booking capacity checks เปลี่ยนมานับจาก sellable pool (ตัด maintenance + reserved) ป้องกัน overbooking
   - บันทึก **Runbook การใช้งานห้องสำรอง (Operations)**:
     - ทางเลือกที่ 1: Flip สถานะด้วยมือ (`reserved_closed` → `available`) ผ่าน `PUT /api/v1/rooms/{id}/status` ก่อน check-in
     - ทางเลือกที่ 2: ใช้ `include_reserved` ในการ walk-in หรือ auto-assign
     - ชี้ reference ไปยัง Ticket 90 สำหรับประเด็น check-in lifecycle ที่ยังเปิดอยู่
2. **Project Memory (`cline.md`):**
   - บันทึก Decision Log จาก grill-me (7 grilled decisions)
   - บันทึกการเพิ่ม `include_reserved` param และการ align capacity denominator
3. **Wayfinder Tracker (`map.md` & `tickets/*.md`):**
   - อัปเดต `wayfinder/reserved-room-pool/map.md` สถานะ Destination เป็น implemented/closed
   - ตรวจสอบว่า tickets 01–05 ถูกบันทึก `status: closed` พร้อมหัวข้อ `## Resolution` และ commit hash ครบถ้วน
   - ปิด ticket 06 นี้เป็นใบสุดท้าย

**Files / Surfaces touched:**
- `docs/api_guide.md`
- `cline.md`
- `wayfinder/reserved-room-pool/map.md`
- `wayfinder/reserved-room-pool/tickets/*.md`

**Blocked by:** 02-calendar-availability-include-reserved, 03-booking-capacity-sellable-pool, 04-allocator-assign-rooms-include-reserved, 05-walk-in-include-reserved (ต้องรอทุกใบ implementation land ก่อน)

**Status:** ready-for-agent

- [ ] `docs/api_guide.md`: บันทึก contract ของ `include_reserved` ครบ 5 surfaces + ตัวอย่าง payload + กติกา ignore
- [ ] `docs/api_guide.md`: บันทึก bug fix การ align denominator ฝั่ง booking capacity check
- [ ] `docs/api_guide.md`: บันทึก runbook ห้องสำรองสำหรับ front desk / operations + ชี้ ticket 90
- [ ] `cline.md`: บันทึก refactor/decision changelog ครบถ้วน
- [ ] `wayfinder/reserved-room-pool/map.md`: อัปเดต Destination + ปิด tickets 01–06 พร้อม `## Resolution` และ commit hashes
- [ ] ตรวจสอบว่าไม่มี lint error หรือ format เสียหาย

**Spec Reference:**
- [spec.md § Further Notes](../spec.md#further-notes)
- [spec.md § Implementation Decisions](../spec.md#implementation-decisions)
- [spec.md § Out of Scope](../spec.md#out-of-scope)
