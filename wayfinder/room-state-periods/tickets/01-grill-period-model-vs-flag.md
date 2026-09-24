---
label: wayfinder:grilling
type: HITL
status: open
assignee:
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

- [ ] grill 4 คำถามกับ owner
- [ ] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far
- [ ] ถ้ากระทบ map `reserved-room-pool` (frozen) — จดผลไว้ที่ ticket นี้เพื่อ unfreeze รอบหน้า
