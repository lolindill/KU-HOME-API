---
label: wayfinder:grilling
type: HITL
status: open
assignee: kevii (session 2026-09-24)
blocked-by: ["02-grill-overlap-expiry-availability-rules"]
---

# 04: Grill contract ของ admin endpoint จัดการ period (CRUD + validation + audit)

## Question

โมเดลล็อกแล้ว (ticket 01: `room_state_periods` ตารางเดียว + kind, derived ตอน query) และกฎ overlap กับ booking ปิดแล้ว (ticket 02) — contract ของ endpoint ที่ admin ใช้จัดการ period เป็นอย่างไร?

1. **รูปร่าง route:** `POST/PATCH/DELETE /rooms/{roomId}/periods[...]` ซ้อนใต้ room, หรือ `/room-periods` แยกตรง? kind เป็น path แยกหรือ body?
2. **Validation ระหว่าง period ด้วยกันเอง:** ห้องเดียวมี period ซ้อนช่วงกันได้ไหม — kind เดียวกันทับกัน (ต้อง merge/reject?), ต่าง kind ซ้อน (maintenance ทับ reserved — ใครชนะ?), วันที่ย้อนหลัง (สร้าง period เริ่มเมื่อวาน — อนุญาตเพราะ "ซ่อมด่วนวันนี้" ต้องเริ่มวันนี้ แล้วย้อนเมื่อวานล่ะ?)
3. **การแก้/ยกเลิก:** PATCH เปลี่ยน end_date ของ period ที่กำลัง active ทำได้ไหม · DELETE ยกเลิก period ที่ยังไม่ถึง vs active — hard delete หรือ soft (เก็บ audit)?
4. **Audit trail:** ใครสร้าง/แก้/ยกเลิก period — columns `created_by/updated_by` บน row พอ หรือเขียน `status_change_logs` ต่อ (entity ใหม่)?
5. **สิทธิ์:** admin เท่านั้น (role:admin) หรือ staff ตั้ง maintenance period เองได้?

**Blocked by:** ~~ticket 02~~ ✅ ปิดแล้ว (2026-09-24) — **frontier** (validation ข้อ 2 อยู่บนกฎจาก ticket 02 แล้ว)

> 📌 **ผลจาก ticket 02 ที่ contract นี้ต้องครอบ:** POST period ทับ BR ค้างได้เสมอ → รูปร่าง response 201 + `affected_bookings` และกลไกลบ draft ที่ overlap ทันที (`draft → deleted`) อยู่ใน contract ที่ grill ที่นี่ด้วย

- [ ] grill 5 คำถามกับ owner
- [ ] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far

- [ ] grill 5 คำถามกับ owner
- [ ] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far
