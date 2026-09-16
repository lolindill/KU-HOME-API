---
label: wayfinder:task
type: task
title: "Walk-in รับ include_reserved"
status: open
assignee:
blocked-by: ["01-include-reserved-gate-and-availability"]
---

# 05: Walk-in รับ `include_reserved`

**What to build:** front-desk walk-in (admin เจาะจงห้อง + check-in ทันที) รับ flag — room status guard รับ `reserved_closed` **เพิ่ม** เมื่อ flag ถูกส่งโดย admin · ไม่ส่ง flag → ถูก reject เหมือนเดิม (runbook เดิมยังใช้ได้: flip เป็น `available` ก่อน) · `maintenance` ถูก reject เสมอ — ใช้ gate resolution จาก 01 · flow หลัง guard เดิน state machine เดิม (draft → confirmed → checked_in) ไม่แตะ

**Blocked by:** 01-include-reserved-gate-and-availability

**Status:** ready-for-agent

- [ ] walk-in เข้าห้อง `reserved_closed` ด้วย flag สำเร็จ (booking ถึง checked_in ครบ flow เดิม)
- [ ] ไม่ส่ง flag → reject เหมือนเดิม (guard message ชี้สถานะปัจจุบันได้)
- [ ] `maintenance` → reject ทุกกรณี
- [ ] route ยัง admin-only + เช็ค admin ใน controller สม่ำเสมอกับ gate helper
- [ ] feature tests ผ่าน HTTP seam (รูปแบบ front-desk test)
