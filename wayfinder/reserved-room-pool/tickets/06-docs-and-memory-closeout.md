---
label: wayfinder:task
type: task
title: "Docs + memory closeout (api_guide / cline.md / tracker)"
status: open
assignee:
blocked-by: ["02-calendar-availability-include-reserved", "03-booking-capacity-sellable-pool", "04-allocator-assign-rooms-include-reserved", "05-walk-in-include-reserved"]
---

# 06: Docs + memory closeout

**What to build:** เอกสารสะท้อนของจริงครบทุก surface — API guide จด contract ของ `include_reserved` (surfaces ทั้ง 5, admin-only semantics, response shape ใหม่, ignore contract, bug fix ของ capacity) + **runbook ห้องสำรอง** (flip ด้วยมือก่อน check-in / ทางเลือก walk-in ด้วย flag) พร้อมชี้ ticket 90 สำหรับ lifecycle ที่ยังเปิด · cline.md จด decision log (7 grilled decisions + audit findings) · ปิด tracker ทุกใบพร้อม Resolution

**Blocked by:** 02, 03, 04, 05 (ทุกใบต้อง land ก่อน — docs ต้องสะท้อนของจริง ไม่ใช่ของที่ว่าจะทำ)

**Status:** ready-for-agent

- [ ] api_guide: flag ครบ 5 surfaces + ตัวอย่าง response + กติกา ignore สำหรับ non-admin
- [ ] api_guide: จด bug fix — capacity นับ sellable pool (ผู้อ่านเก่าต้องรู้ว่า behavior เข้มขึ้น)
- [ ] api_guide: runbook ห้องสำรอง (flip มือ / walk-in flag) + ชี้ ticket 90
- [ ] cline.md: decision log ครบ (7 decisions, maintenance-absolute rule, request-scoped)
- [ ] map.md Destination อัปเดต + tickets 01–05 ปิดด้วย `## Resolution` + แนบ commit
