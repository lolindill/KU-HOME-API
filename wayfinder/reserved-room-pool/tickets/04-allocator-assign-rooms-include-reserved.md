---
label: wayfinder:task
type: task
title: "Allocator + assign-rooms รับ include_reserved — full-chain E2E"
status: open
assignee:
blocked-by: ["03-booking-capacity-sellable-pool"]
---

# 04: Allocator + assign-rooms รับ `include_reserved` — full-chain E2E

**What to build:** จุด assign ห้องจริงเดียวของระบบ (assign-rooms → allocator) รับ flag — allocator pool loading + booking-priority sellable filter เลิกตัด `reserved_closed` เมื่อ flag ถูกส่ง (ตัด `maintenance` เสมอ) · จบใบนี้ได้ **full-chain E2E**: admin สร้าง booking ด้วย flag → assign-rooms ด้วย flag → booking ได้ `room_id` ชี้ห้อง `reserved_closed` จริง โดยสถานะห้อง**คงเดิม** (ไม่ auto-flip — ตาม grill #6, lifecycle อยู่ที่ ticket 90) · ถ้า admin ลืมส่ง flag ตอน assign แล้วเหลือแต่ห้องสำรอง → allocation fail อย่างสุภาพ (fail-safe ทิศทางปลอดภัย)

**Blocked by:** 03-booking-capacity-sellable-pool

**Status:** ready-for-agent

- [ ] assign-rooms ด้วย flag → assign เข้าห้อง `reserved_closed` ได้เมื่อ sellable pool หมด
- [ ] ห้องคงสถานะ `reserved_closed` หลังถูก assign (ไม่มี auto-flip)
- [ ] assign-rooms ไม่ส่ง flag เมื่อเหลือแต่ reserved → fail message ชัดเจน ไม่ crash ไม่ assign เกิน
- [ ] `maintenance` ไม่ถูก allocate ทุกกรณี
- [ ] default (ไม่มี flag) พฤติกรรม allocator เดิมเปลี่ยนแค่ผ่าน optional param — เรียกเดิมได้ผลเดิม
- [ ] full-chain E2E: create-with-flag → assign-with-flag → ตรวจ room_id + สถานะห้อง
- [ ] feature tests ผ่าน HTTP seam (allocator test ผ่าน assign-rooms แบบ indirect ตาม seam decision — ไม่เพิ่ม unit seam)
