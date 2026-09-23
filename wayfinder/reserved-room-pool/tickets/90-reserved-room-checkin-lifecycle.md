---
label: wayfinder:grilling
type: HITL
title: "Reserved-room lifecycle ตอน check-in/checkout — flip สถานะด้วยมือ vs auto-flip"
status: open
assignee:
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

**Blocked by:** None (แต่ควรทำหลัง implement หลัก land เพราะต้องเห็นพฤติกรรมจริง)

**Status:** open

- [ ] grill 3 คำถามข้างบนกับผู้ใช้
- [ ] ตัดสินใจ + จด resolution ที่ไฟล์นี้
- [ ] ถ้าต้องแก้ state machine: อย่าลืมอัปเดต `RoomStateTest` + `docs/api_guide.md` + จดใน `cline.md`
- [ ] ถ้าคง flip มือ: เขียน runbook ลง `docs/api_guide.md` (walk-in + check-in ของห้อง reserved)
