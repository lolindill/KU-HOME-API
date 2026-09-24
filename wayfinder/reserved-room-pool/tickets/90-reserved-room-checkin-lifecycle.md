---
label: wayfinder:grilling
type: HITL
title: "Reserved-room lifecycle ตอน check-in/checkout — flip สถานะด้วยมือ vs auto-flip"
status: closed
assignee: zcode session (2026-09-24 — HITL grill สดกับ owner)
blocked-by: []
---

# 90: Reserved-room lifecycle ตอน check-in/checkout

**ที่มา:** grill-me session 2026-09-11 ข้อ 6 — ผู้ใช้สั่ง "have mark this in ticket" — งาน implement ตาม [spec.md](../spec.md) จะ **ไม่ auto-flip** สถานะห้อง แล้วให้เปิด ticket นี้รอตัดสินใจภายหลัง (อิสระ ไม่ block implementation)

**สถานการณ์ปัจจุบัน (audit 2026-09-11):**

- เมื่อ admin ใช้ `include_reserved` assign booking เข้าห้อง `reserved_closed` → ห้อง**คงสถานะ** `reserved_closed` (booking เป็นแค่ date-range block)
- State machine ปัจจุบัน (`Room::transitionStatusTo`): `reserved_closed → available` **legal** (available รับ reserved_closed เป็น source) · `reserved_closed → occupied` **illegal** (occupied รับเฉพาะจาก available/prep_checkin — ถูก lock ด้วย `RoomStateTest`)
- แปลว่า runbook ตอนแขกมา check-in จริง: admin ต้อง flip `reserved_closed → available` ผ่าน `PUT /rooms/{id}/status` ก่อน 1 ครั้ง แล้ว flow check-in (available → occupied) ถึงจะเดินได้

**คำถามที่ต้อง grill ต่อ (HITL):**

1. ตอน check-in จริง จะให้ flip เป็น `available` ด้วยมือ (คง explicit transition ตามปรัชญาระบบเดิม) หรือให้ flow check-in รับ `reserved_closed → occupied` โดยตรง (แก้ state machine + แก้ `RoomStateTest` เดิม)?
2. หลัง checkout ห้อง reserved ที่เคยถูกใช้ ควรกลับ `reserved_closed` เองไหม หรือเดิน checkout_makeup → dirty → available ตามปกติแล้ว admin ค่อยดองกลับ?
3. ถ้าเลือก auto-flip ฝั่งไหน (assign-rooms / check-in) และ audit trail จะจดอย่างไร (transitionStatusTo ไม่มี status_change_logs แบบ Booking — จดที่ status_updated_by พอไหม)

**Blocked by:** None

**Status:** closed (2026-09-24 — ดู Resolution ท้ายไฟล์)

- [x] grill 3 คำถามข้างบนกับผู้ใช้ *(ย้ายมา grill ก่อน implement — decision เปลี่ยนโมเดล ต้องรู้ก่อนเขียน ticket 01 ไม่งั้นต้องแก้สองรอบ)*
- [x] ตัดสินใจ + จด resolution ที่ไฟล์นี้
- [x] ถ้าต้องแก้ state machine: อย่าลืมอัปเดต `RoomStateTest` + `docs/api_guide.md` + จดใน `cline.md` *(RoomStateTest + api_guide แก้ใน ticket 01 · จด cline.md ย้ายไป ticket 06 closeout)*
- [x] ถ้าคง flip มือ: เขียน runbook ลง `docs/api_guide.md` (walk-in + check-in ของห้อง reserved) *(ไม่เลือกทางนี้ — runbook ไม่ต้องมี)*

## Resolution (2026-09-24 — owner เลือกทาง C)

**Decision: "ห้องสำรอง" เป็นคุณสมบัติ (pool membership) ของห้อง ไม่ใช่ lifecycle state — เพิ่ม `rooms.is_reserved` (boolean, PgBoolean) แล้วถอดสถานะ `reserved_closed` ออกจาก state machine ทั้งหมด**

- เหตุผล: ปัญหาทั้งหมดเกิดจากการยุบ 2 มิติ (pool membership = คุณสมบัติถาวร × physical lifecycle = เปลี่ยนทุกวัน) ไว้ใน status เสาเดียว — พอแขก check-in ต้อง `reserved_closed → occupied` (illegal) และพอหลุดออกจากสถานะแล้วระบบจำไม่ได้ว่าเคยเป็นห้องสำรอง
- **คำตอบคำถามที่ 1 (check-in):** ไม่ต้อง flip อะไรเลย — ห้องสำรองว่างมี `status=available` + `is_reserved=true` → check-in เดิน `available → occupied` ตาม machine เดิม (legal อยู่แล้ว) · ไม่แก้ `RoomStateTest` คู่ transition ให้ แต่**ลบ**คู่ transition ของ reserved_closed ออกพร้อมกับถอดสถานะ
- **คำตอบคำถามที่ 2 (หลัง checkout):** กลับเข้า pool สำรอง**อัตโนมัติโดยไม่มีโค้ด** — flag เดินตามห้องตลอด `occupied → checkout_makeup → available` เพราะห้องไม่เคยออกจาก pool ไปไหน
- **คำตอบคำถามที่ 3 (auto-flip/audit):** ไม่มี auto-flip ให้จดแล้ว — จุดเปลี่ยน pool เดียวคือ admin toggle `is_reserved` (จะมาพร้อม endpoint จัดการ pool ภายหลัง) · การเปลี่ยนสถานะกายภาพจดผ่าน `status_updated_by` เดิมเหมือนเดิม
- **Migration:** `is_reserved` default false + แปลงข้อมูลเดิม `reserved_closed → (available, is_reserved=true)` ใน migration เดียว
- **ผลต่อ spec/tickets:** spec ยกเลิกข้อ "no schema change / no state-machine change" (amendment 2026-09-24) · ticket 01 รับงาน model pivot ทั้งก้อน (migration + สลับ filter `reserved_closed → is_reserved` ทุกจุด + ถอดสถานะจาก machine) พร้อม tracer bullet · tickets 02–05 งาน include_reserved-flag คงเดิม (เปลี่ยนแค่ถ้อยคำ query จากสถานะเป็น flag) · HTTP contract หน้าบ้านไม่เปลี่ยน
- **ทางเลือกที่ปัด:** (A) flip มือทั้งหมด — ops ต้องจำดองกลับ เสี่ยง pool รั่วเงียบ ๆ · (B) คงสถานะ + auto-flip + column จำที่มา — migration เท่ากันแต่ logic กระจายและอ่อนไหวกับแขกย้ายห้อง
