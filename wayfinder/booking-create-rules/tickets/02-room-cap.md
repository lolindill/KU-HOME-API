---
label: wayfinder:task
type: task
title: "Room cap — ลิมิต 4 ห้องต่อ 1 booking (non-admin)"
status: open
assignee: antigravity (2026-09-16)
blocked-by: ["01-advance-notice-rule"]
---

# 02: Room cap — ลิมิต 4 ห้องต่อ 1 booking (non-admin)

**What to build:** พฤติกรรมปลายทางที่ user เจอ — non-admin สร้าง booking เกิน 4 ห้อง →
422 ข้อความไทยแนะนำให้ติดต่อผู้ดูแล, ครบ 4 ห้อง → ผ่าน, และการเพิ่มห้องทีหลังนับห้องเดิม
ใน booking รวมกับห้องใหม่ (3+2 → 422, 3+1 → ผ่าน) — admin ยัดกี่ห้องก็ผ่าน · batch edit
ไม่เพิ่มจำนวนห้องจึงไม่ถูกตรวจ · ใช้ config + helper (admin check / เพดานห้อง) จากใบ 01

**Blocked by:** 01-advance-notice-rule (config + helper + ไฟล์ form-requests ชุดเดียวกัน)

**Status:** ready-for-agent

- [ ] non-admin create 5 ห้อง → 422 / 4 ห้อง → 201 (form-request layer)
- [ ] non-admin add-rooms: ห้องเดิม 3 + ใหม่ 2 → 422 / 3 + 1 → 201 (controller guard นับห้องเดิมจริงจาก booking)
- [ ] admin ทุกกรณี (create 6 ห้อง, add-rooms เกินเพดาน) → ผ่านหมด
- [ ] guard ของ add-rooms วางก่อน beginTransaction ตาม pattern early guards (401/422) ของ method เดิม
- [ ] batch edit คงพฤติกรรมเดิม (ไม่เช็ค cap — ไม่มีการเพิ่มห้อง)
- [ ] test ใหม่: ชุดกรณี cap ตาม spec §Testing Decisions (HTTP seam เดียว)
- [ ] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`
- [ ] docs ส่วน cap: api_guide + cline.md (ต่อท้ายหัวข้อเดียวกับใบ 01)
