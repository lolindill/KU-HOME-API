---
label: wayfinder:grilling
type: HITL
title: "ชะตา findings 3 ข้อจาก baseline suite — รอ owner ตัดสินก่อนเขียนโค้ด"
status: open
assignee: (ว่าง)
blocked-by: []
---

# 02: ชะตา findings 3 ข้อ (owner decision)

> *(graduated 2026-11-04 จาก "Not yet specified" ของ map — เดิมแขวนเป็น prose ทำให้ monitor มองไม่เห็น frontier · ตัว findings เดิมอยู่ใน Resolution ของ [ticket 01](01-baseline-feature-suite.md))*

## Question

Findings ที่ ticket 01 เจอระหว่าง characterization (ยืนยันซ้ำ 2026-11-04 ว่ายังอยู่ครบบน HEAD `3feacd2`) — นายท่านจะ**เปิด fix** หรือ**ปล่อยเป็น baseline behavior** ต่อข้อ?

## Findings + ทางเลือก

### F1 — catch-all `\Exception` ตอบ raw message เป็น HTTP 422

- **ที่อยู่ปัจจุบัน:** `app/Http/Controllers/Api/V1/BookingController.php:2208-2216` (เดิมตอน ticket 01 อ้าง `:1636-1643` — line เลื่อน)
- **ปัญหา:** allocator throw `\Exception` ทั้งกรณี "ห้องเต็ม" (fault ของ client จริง) และ fault ภายใน (เช่น DB error) — ทั้งหมดถูกตีความเป็น 422 + ส่ง raw `$e->getMessage()` กลับ (อาจ leak ข้อความภายใน)
- **ถ้า fix:** แยก exception type สำหรับ "pool ว่าง" → 422 ตามธรรมเนียม envelope ส่วน fault อื่น → 500 generic + `Log::error` (มี Log::error อยู่แล้วที่ `:2211`)
- **ถ้าปล่อย:** risk ต่ำ — ยังไม่มีเคสจริงที่ leak จนเจ็บ

### F2 — owner-path ใน controller เป็น unreachable code

- **ที่อยู่ปัจจุบัน:** 401 branch `:2117-2123` · owner-403 branch `:2126-2130` (เดิมอ้าง `:1551`/`:1560`)
- **ปัญหา:** route ถูก gate ด้วย `role:admin` ก่อนเข้า controller (`routes/api.php:252`) → ทั้ง 2 branch ถึงไม่ได้ทาง HTTP จริง (defense-in-depth ตาม convention — เก็บไว้ได้แต่เป็น dead code ในสายตา coverage)
- **ถ้า fix:** ลบ 2 branch (controller เบาลง) **หรือ** คงไว้และจดเป็น convention ชัด ๆ
- **หมายเหตุ:** guard มี `staff` ใน in_array ด้วย (`:2126`) — ขัดกับ route ที่ admin-only อีกชั้น

### F3 — การ assign `room_id` ไม่มี audit trail

- **ที่อยู่ปัจจุบัน:** `BookingController.php:2183-2186` — `BookingRoom::where('id', $brId)->update(['room_id' => $roomId])` ตรง ๆ ไม่ผ่าน `status_change_logs` (audit ครอบเฉพาะ status transition)
- **ปัญหา:** ใครจัดห้องให้ห้องไหนเมื่อไหร่ ไม่มีหลักฐานย้อนหลัง — เกี่ยวตรงกับ **ticket 04 ของ map `reserved-room-pool`** (assign-rooms รับ `include_reserved` — ยิ่งสิทธิ์สำคัญยิ่งต้องมี audit)
- **ถ้า fix:** เพิ่ม row ใน `status_change_logs` (เช่น `entity_type=booking_room`, `from_status=unassigned`, `to_status=assigned:<room_id>`) หรือ audit channel แยก — ต้อง grill รูปแบบก่อนเขียน
- **ถ้าปล่อย:** ticket 04 ของ reserved-room-pool ควรรับเรื่องนี้ไปคิดด้วยเมื่อเปิดงาน

## Out of scope

- แก้ behavior การ allocate เอง (ห้าม re-litigate algorithm Hybrid+ / X09 / bed_preference — นอกเรื่อง ticket นี้)
- ticket 04 ของ reserved-room-pool (งาน include_reserved) — map นั้นจัดการเอง

## Resolution

*(รอ owner grilling — ตัดสินครบ 3 ข้อแล้วเขียนที่นี่ → `status: closed` → append 1 บรรทัดใน "Decisions so far" ของ map)*
