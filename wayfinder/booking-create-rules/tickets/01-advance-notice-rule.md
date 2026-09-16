---
label: wayfinder:task
type: task
title: "Advance-notice rule — จองล่วงหน้า ≥ 2 วัน (4 write paths, tracer bullet)"
status: open
assignee: antigravity (2026-09-16)
blocked-by: []
---

# 01: Advance-notice rule — จองล่วงหน้า ≥ 2 วัน (tracer bullet)

**What to build:** พฤติกรรมปลายทางที่ user เจอ — non-admin สร้าง/แก้การจองด้วยวันเช็คอิน
เร็วกว่า +2 calendar days (Asia/Bangkok) → โดน 422 พร้อมข้อความไทย, +2 วันขึ้นไป → ผ่าน,
admin (ตัดสินจาก login role เท่านั้น ไม่ใช่ field `source`) จองวันเดียวกัน/พรุ่งนี้ได้
แต่ยังห้ามย้อนหลังตามกฎเดิม — ครบทั้ง 4 write paths (create / add-rooms / แก้รายห้อง /
แก้ batch) พร้อม booking config ใหม่ (ใส่คีย์ทั้ง 2 ตัว — อีกคีย์รอใบ 02 ใช้) + helper
กลาง + test + docs ส่วนกฎวัน

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] non-admin จองเช็คอินวันนี้/+1 วัน → 422 ข้อความไทยระบุเงื่อนไขล่วงหน้า 2 วัน — ครบทั้ง create / add-rooms / แก้รายห้อง / แก้ batch
- [ ] non-admin เช็คอิน +2 วัน → 201 (boundary ผ่าน)
- [ ] admin วันนี้/+1 วัน → 201 และยังโดนกฎ not-in-past เดิม — exempt ตัดสินจาก sanctum role
- [ ] booking config: `min_advance_days` (env `BOOKING_MIN_ADVANCE_DAYS`, default 2) + `max_rooms_per_booking` (env `BOOKING_MAX_ROOMS_PER_BOOKING`, default 4 — รอใบ 02) อ่าน env ได้ สไตล์เดียวกับ allocation config
- [ ] helper กลางตัวเดียวใน support namespace เดิม: admin check + วันเช็คอินขั้นต่ำ + เพดานห้อง — ครอบผ่าน HTTP seam เท่านั้น (fewest-seams)
- [ ] test ใหม่ชุด boundary ตาม spec §Testing Decisions (HTTP feature seam เดียว)
- [ ] test เดิม ~17 จุดที่ใช้ check-in พรุ่งนี้ เลื่อนเป็น +2/+3 โดยคงเจตนาเดิมของแต่ละ test — draft-guard/ownership-403/unknown-404 ต้องยังโดน error ที่ตัวเองทดสอบ (validation ใหม่วิ่งก่อน controller)
- [ ] `php artisan test` เขียวทั้ง suite + `vendor/bin/pint --dirty`
- [ ] docs ส่วนกฎวัน: api_guide (validation ของ booking endpoints) + cline.md (decision จาก grill-me)
