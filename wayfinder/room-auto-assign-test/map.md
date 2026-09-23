---
label: wayfinder:map
title: "assign-rooms baseline — feature test suite ก่อนงาน include_reserved"
status: open
---

# Wayfinder Map — assign-rooms baseline test suite

## Destination ✅ COMPLETED (2026-09-22)

ชุด HTTP feature tests แบบ baseline (characterization) ของ endpoint จัดห้องอัตโนมัติ `PUT /api/v1/bookings/{bookingId}/assign-rooms` (→ `RoomAllocator::allocate`) สร้างใน **worktree แยก** โดยไม่แตะโค้ด production — จบเมื่อ suite ใหม่ผ่านครบ และ full suite ไม่มี regression

## Notes

- **Domain:** KU HOME API — assign-rooms เป็น admin-only (`auth:sanctum` + `role:admin`, `routes/api.php:158`) · gate สถานะ `paid|confirmed` · allocation ผ่าน RoomAllocator (Hybrid+ v3, X09 seed, bed_preference hard constraint, ตัด `maintenance`/`reserved_closed` ออกจาก pool)
- **ทำงานใน worktree** `.worktree/room-auto-assign-test` (branch `feature/room-auto-assign-test`) — แยกจาก `agust-11` ทั้งหมด ตามโจทย์ "minimum clone" (checkout + copy `.env` + `composer install`)
- **Test seam ตาม decision ของ map reserved-room-pool (2026-09-11):** ทดสอบ allocator ผ่าน HTTP feature seam เดียว (assign-rooms แบบ indirect) — ไม่เพิ่ม unit seam
- **การแบ่งงาน (dispatch policy):** zcode = head research manager (trace contract, เขียน brief, ตรวจสอบอิสระ) · antigravity (gemini-3.8-flash ผ่าน agent-hub) = implement + run tests
- ปลายทางชุดนี้เป็น **safety net ของ ticket 04 (allocator + assign-rooms รับ include_reserved)** ใน map reserved-room-pool

## Decisions so far

- [01-baseline-feature-suite](tickets/01-baseline-feature-suite.md): suite 15 เคส / 62 assertions ผ่านครบ + full suite 426/426 (1,475 assertions) ไม่มี regression — characterization ล้วน ห้ามแก้ prod code · findings จากการ scrutinize: catch-all `\Exception` ตอบ raw message เป็น 422, owner-path ใน controller เป็น unreachable (middleware กั้นก่อน), การ assign ห้องไม่มี audit trail

## Not yet specified

- ชะตา findings 3 ข้อ (catch-all 422 / dead-code owner path / ไม่มี audit trail ตอน assign) — รอ owner ตัดสินว่าจะเปิด fix หรือปล่อยเป็น baseline behavior (findings #3 น่าจับคู่กับ ticket 04 ของ reserved-room-pool)

## Out of scope

- แก้โค้ด production ทุกกรณี (งานนี้ characterization เท่านั้น)
-  implement `include_reserved` บน assign-rooms — เป็นของ map reserved-room-pool ticket 04
- X09 pin coverage เชิงลึก (unit suite เดิมครอบอยู่แล้ว — suite นี้จับแค่ผ่าน HTTP)
