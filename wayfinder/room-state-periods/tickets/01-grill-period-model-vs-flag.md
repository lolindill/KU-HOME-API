---
label: wayfinder:grilling
type: HITL
status: closed
assignee: kevii (session 2026-09-24)
blocked-by: []
---

# 01: Grill โมเดล period — แทนที่ is_reserved flag หรือเกาะกัน

## Question

Req ใหม่: "ห้องสำรอง" และ "ซ่อมแซม" มีระยะเวลากำหนดแบบ booking (ช่วงเริ่ม–สิ้นสุด) — โมเดลข้อมูลควรเป็นอะไร?

- **ตาราง periods ใหม่** (เช่น `room_state_periods`: room_id, kind `reserved`|`maintenance`, start_date, end_date, …) แล้วคำนวณสถานะปัจจุบันแบบ derived จากวันที่ — หรือ
- **fields บน rooms** (start/end ต่อสถานะ) — หรือ
- **คง `is_reserved` flag + period เป็นแค่ metadata** ประกอบ?

คำถามที่ต้องได้คำตอบจาก owner:
1. Period **แทนที่** decision `is_reserved` flag (ticket 90 ของ reserved-room-pool) หรือทั้งสองอยู่ด้วยกัน (flag = membership ถาวร, period = กำหนดการ)? — ผลคือ frozen ticket 01 ของ map เดิมต้องปรับแค่ไหน
2. Maintenance กับ reserved ใช้กลไกเดียวกัน (ตารางเดียว column kind) หรือแยก?
3. Period หมดอายุ → ห้องกลับอะไรเอง (sweep แบบ `CleanupExpiredDrafts` หรือ derived สดตอน query)?
4. ห้องใน period ยังถูก assign ผ่าน include_reserved ได้ไหม (ต่อยอด feature เดิม) — และ period บังคับเมื่อไหร่?

**Blocked by:** None (frontier — เริ่มได้เลย · session นี้ HITL grill กับ owner ตรง ๆ)

- [x] grill 4 คำถามกับ owner
- [x] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far
- [x] ถ้ากระทบ map `reserved-room-pool` (frozen) — จดผลไว้ที่ ticket นี้เพื่อ unfreeze รอบหน้า

## Resolution

**CLOSED 2026-09-24 — owner grill HITL ครบ 4 คำถาม + 1 ที่เกิดระหว่าง grill:**

1. **โมเดล = period แทน flag (ตัวเลือก B)** — ถอด `rooms.is_reserved` ทิ้ง · "ห้องสำรอง" = ห้องที่มี period kind=reserved **active** เท่านั้น · concept pool สำรองถาวรเลิก — decision ticket 90 ของ map `reserved-room-pool` ถูก override ทั้งก้อน
2. **ตารางเดียว + kind** — `room_state_periods` (room_id, kind enum `reserved`|`maintenance`, start_date, end_date) — overlap logic/CRUD/query ชุดเดียว, อนาคตเพิ่ม kind ใหม่ได้
3. **Derived ตอน query** — ไม่มี sweep/cron; availability/allocator เช็กวันที่สด (`start_date <= X <= end_date`) — philosophy เดียวกับ `BookingRoom::scopeHoldingSlot()` ที่ owner ตัดสินไว้แล้ว · status lifecycle ของห้องไม่ถูกแตะโดย period
4. **reserved period ถูก override ได้ / maintenance ตัดเด็ดขาด** — admin ส่ง `include_reserved` (admin-only ผ่าน `IncludeReservedGate` — helper คงใช้ต่อได้) มองเห็น/จองทับห้องที่มี reserved period active ได้ · maintenance period ตัดเด็ดขาดทุกกรณี (กฎเหล็กเดิมจาก ticket 90 ถ่ายทอดมาที่ period) — period บังคับทุก path นับ availability + allocator + calendar ผ่าน shared scope เดียว (ห้าม whereIn เอง เหมือนธรรมเนียม `holdingSlot()`)
5. **(emerged) ถอดสถานะ `maintenance` ออกจาก room state machine** — single source of truth = period · ห้องระหว่างซ่อมเก็บ lifecycle status เดิม (เช่น `dirty`/`available`) · 3 จุด `whereNotIn('status', ['maintenance'])` (`RoomController` availability, `BookingPriority`, `RoomAllocator`) สลับเป็น period-check · flow "ซ่อมด่วนวันนี้" = สร้าง period เริ่มวันนี้

**ผลกระทบ map `reserved-room-pool` (ยัง 🧊 FROZEN — ห้าม unfreeze รอบนี้):** decision ticket 90 ถูก override — column `is_reserved` + migration เดิมจะถูกถอด, `IncludeReservedGate` คงอยู่แต่ความหมายเปลี่ยน (gate reserved **period** ไม่ใช่ pool), ticket 01 ของ map นั้น (code landed suite 446) ต้องรื้อให้เข้ากับ period model เมื่อ unfreeze — **ห้ามปิดตาม spec เดิม**
