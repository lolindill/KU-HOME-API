---
label: wayfinder:task
type: AFK
title: "Baseline HTTP feature suite — assign-rooms 15 เคส (worktree)"
status: closed
assignee: antigravity (2026-09-22) · ตรวจสอบอิสระโดย zcode
blocked-by: []
---

# 01: Baseline HTTP feature suite — assign-rooms 15 เคส

## Question

พฤติกรรมจริง (current behavior) ของ `PUT /api/v1/bookings/{bookingId}/assign-rooms` ครอบคลุมจุดไหนบ้าง และ lock เป็น regression net ก่อนงาน include_reserved ได้ตรงไหน — โดยไม่แก้โค้ด production

## Resolution

**สร้าง `tests/Feature/BookingAutoAssignRoomsTest.php` (585 บรรทัด, 15 เคส, 62 assertions)** บน worktree `.worktree/room-auto-assign-test` — port `seedTopology()` (100 ห้อง ชั้น 5-9, ชั้น 8 king_size) จาก RoomAllocatorIntegrationTest + helpers แบบ FrontDeskTest · รันบน SQLite :memory:

ครอบครบ: 401 unauthenticated · 403 non-admin (และ owner-ก็โดน 403 — middleware กั้นก่อน controller) · 404 unknown id · 422 สถานะ `draft`/`pending` · 422 booking ไม่มีห้อง · 200 `status:info` idempotent · 200 happy path (Deluxe+Suite, `allocation.algo = "Hybrid+ (C+D+A+FF)"`, สถานะ booking/BR ไม่เปลี่ยน) · ห้องไม่ซ้ำใน booking เดียว · `bed_preference=king_size` → ได้ห้องชั้น 8 · allocator fail (pool ว่าง) → 422 + rollback (room_id คง null) · overlap exclusion (ไม่เลือกห้องที่มี confirmed BR ทับช่วง) · partial assign (assign เฉพาะ BR ที่ยังว่าง)

**ผล:** suite ใหม่ 15/15 ผ่าน · full suite **426/426 (1,475 assertions)** ไม่มี regression · commit `bbe4ea6`

**Findings จากการ scrutinize (จดไว้ ยังไม่แก้ — อยู่นอก scope ของ map นี้):**
1. `BookingController.php:1636-1643` — catch `\Exception` กว้าง ส่ง raw `$e->getMessage()` เป็น HTTP 422 (fault ฝั่ง server ถูกตีความเป็น client error + อาจ leak ข้อความภายใน)
2. 401 branch (`:1551`) และ owner-403 branch (`:1560`) เป็น unreachable code ทาง HTTP (defense-in-depth ตาม convention — เก็บได้ แต่ owner เรียกเองไม่ได้จริง)
3. การ assign `room_id` ไม่เข้า `status_change_logs` (audit ครอบเฉพาะ status transition) — เกี่ยวตรงกับ reserved-room-pool ticket 04
4. response `status: "info"` (`:1592`) อยู่นอก envelope `success|error` ตาม docs
5. overlap window ใช้ union `[min check_in, max check_out)` ของทุก BR (`RoomAllocator.php:154-170`) — conservative ปลอดภัยแต่ overblock ได้ใน sub-window
