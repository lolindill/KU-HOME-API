---
label: wayfinder:grilling
type: HITL
status: open
assignee:
blocked-by: []
---

# 05: Grill migration ข้อมูลเดิม — pool สำรอง + ห้อง maintenance ปัจจุบัน → period แรก

## Question

โมเดลล็อกแล้ว (ticket 01 — period แทน flag) — ข้อมูลเดิมต้องแปลง แต่ค่าของ period แรกเป็น**เจตนาทางธุรกิจ**ที่ต้องถาม owner:

1. **ห้อง `is_reserved = true` ปัจจุบัน:** โมเดลใหม่ไม่มี "สำรองถาวร" — แปลงเป็น reserved period ยาว (end_date กี่ปี — 5 ปี? 99?), หรือ owner ระบุรายห้อง (ห้องไหนควรเลิกสำรอง?), หรือทิ้งไว้เป็นห้องขายปกติทั้งหมด?
2. **ห้อง `status = maintenance` ปัจจุบัน:** สร้าง maintenance period เริ่มวันนี้ — end_date ใครกำหนด (owner ประเมินรายห้อง? default กี่วัน?) · lifecycle status ของห้องพวกนี้ตอนถอดสถานะกลับเป็นอะไร (`dirty` เหมาะสุดไหม)?
3. **จังหวะ drop:** drop column `rooms.is_reserved` พร้อม migration เดียว หรือคง column ไว้รอ period จริงขึ้นก่อนแล้วค่อยถอด (deploy ปลอดภัยกว่า — แต่ dual source ชั่วครู่)?

**Blocked by:** None (โมเดลล็อกจาก ticket 01 แล้ว — frontier)

- [ ] grill 3 คำถามกับ owner
- [ ] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far
