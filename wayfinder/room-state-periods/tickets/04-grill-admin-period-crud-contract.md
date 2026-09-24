---
label: wayfinder:grilling
type: HITL
status: closed
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

- [x] grill 5 คำถามกับ owner (+ กิ่งลูก auto-merge 2 ข้อ + กิ่งลูกสิทธิ์ staff 1 ข้อ)
- [x] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far

## Resolution

**CLOSED 2026-09-24 — owner grill HITL ครบ 5 คำถาม + 3 กิ่งลูกที่เกิดระหว่าง grill (ฐาน: โมเดล ticket 01 + กฎ booking ticket 02):**

1. **Route ซ้อนใต้ room + kind เป็น body field**
   - `GET /api/v1/rooms/{roomId}/periods` — ลิสต์ period ของห้อง (ทุก kind · active/past/future อ่านจากวันที่)
   - `POST /api/v1/rooms/{roomId}/periods` — สร้าง → **201**
   - `PATCH /api/v1/rooms/{roomId}/periods/{periodId}` — แก้ช่วงวันที่
   - `DELETE /api/v1/rooms/{roomId}/periods/{periodId}` — ยกเลิก
   - body: `{ kind: reserved|maintenance, start_date, end_date }` — kind ไม่แยก path (attribute เดียว validate จุดเดียวใน FormRequest, เพิ่ม kind ใหม่ไม่ต้องแตะ route)
   - middleware `role:admin,staff` (OR semantics ตามธรรมเนียม `role:admin,housekeeping`) + **guard แยก kind ใน controller** (defense-in-depth ตาม AGENTS.md) — ดูข้อ 5

2. **Validation ระหว่าง period ห้องเดียวกัน**
   - **kind เดียวกัน overlap → auto-merge (owner ตัดสิน สวนคำแนะนำ reject):** row **เดิม**ถูกขยายวันที่ครอบ union (id/created_by เดิมคงอยู่ — เช่น เดิม 28 ก.ย.–2 ต.ค. + สร้างใหม่ 25–30 ก.ย. → row เดิมกลายเป็น 25 ก.ย.–2 ต.ค.) · ช่วงใหม่ซ้อนข้างในจนไม่ขยายอะไร → ตอบ row เดิมตามสภาพ (ไม่มีการเขียน) · merge ทำซ้ำ **cascade** จนไม่เหลือ same-kind overlap
   - **PATCH ใช้กฎเดียวกัน** — ขยาย/ขยับช่วงจนชนของ kind เดียวกัน → merge ทันที (ไม่งั้น spec จะแปลก: POST ชนกันได้แต่ PATCH โดน 422)
   - **ต่าง kind ซ้อน → ร่วมอยู่ได้ ช่วงทับ maintenance ชนะทุก semantics:** ตามปรัชญา ticket 02 "บันทึกของจริงได้เสมอ" (ห้องถูกกัน event แล้วท่อระเบิดกลางคันต้องบันทึกซ่อมได้) · วันที่ทับกัน availability ตัดอยู่แล้วทั้งคู่ + **บล็อก check-in + ห้าม include_reserved** (ไม่งั้น reserved เปิดช่องเข้าห้องที่กำลังซ่อม) · นอกช่วงทับแต่ละ kind ทำงานปกติ
   - **วันย้อนหลัง:** `start_date` ย้อนอดีตได้ (เคสจริง "ท่อระเบิดคืนวาน admin มาบันทึกเช้านี้") แต่ **ห้าม period หมดแล้วทั้งช่วง** (`end_date` ต้อง >= วันนี้) — availability คำนวณสด ไม่มีเหตุผลสร้างช่วงอดีตจบแล้ว
   - *(derived จากสูตร overlap ที่ล็อกใน ticket 02)* `end_date` เป็น **exclusive** เหมือน `check_out` — maintenance "ถึงวันที่ 30" = คืนวันที่ 29 เป็นคืนสุดท้ายที่ห้องหาย คืนวันที่ 30 ขายปกติ
   - **ผลกระทบต่อ booking ครบตาม ticket 02 ทุกครั้งที่ช่วงครอบวันใหม่ (POST และ PATCH):** ลบ draft ที่ overlap ทันที + audit `draft → deleted` · คำนวณบน **union window หลัง merge**

3. **PATCH แก้ start+end / DELETE = hard delete**
   - PATCH อนุญาตเฉพาะ `start_date` + `end_date` · **kind immutable** (เปลี่ยนใจ = ลบสร้างใหม่) · ทุก PATCH re-run กฎครบชุด: ห้ามหมดทั้งช่วง + same-kind merge + ผลกระทบ ticket 02 บนช่วงใหม่ · เคสครบ: ซ่อมเสร็จก่อนกำหนด → หด end · ยืดเวลา → ต่อ end · งานยังไม่เริ่มเลื่อนไปหลัง → ขยับ start
   - DELETE = **hard delete + เขียน audit log ก่อนลบ** (row คือตารางเวลา ไม่ใช่หลักฐานการเงิน — soft delete บังคับให้ทุก period-scope ตาม ticket 01–02 ต้องกรอง `deleted_at` ผิดธรรมเนียม `holdingSlot()`) · ใช้ได้ทั้ง period ยังไม่ถึง (ยกเลิกแผน) และ active (ซ่อมเสร็จก่อนกำหนด — ห้องกลับมาขายทันทีเพราะ derived ตอน query) · DELETE ไม่มีผลข้างเคียงกับ booking (เพียงปล่อยห้องคืน)

4. **Audit = `created_by` column + `status_change_logs` entity ใหม่**
   - column `created_by` (uuid → users) บน row — ตอบ "ใครเปิด period นี้" ได้ทันทีไม่ต้อง join
   - เหตุการณ์ทั้งหมด (สร้าง/merge/ขยาย/หด/ลบ) เขียน `status_change_logs` ด้วย `entity_type = 'room_state_period'` — ตารางออกแบบ polymorphic ไว้ขยายอยู่แล้ว (comment ใน migration) · `from_status`/`to_status` เก็บ **ช่วงวันที่เดิม→ใหม่** (string เช่น `2026-09-25..2026-09-30`), `note` บอกเหตุการณ์ (`created`/`merged`/`extended`/`shortened`/`deleted`) → hard delete แล้วประวัติยังครบ
   - ไม่มี `updated_by` (ซ้ำซ้อนกับ log)

5. **สิทธิ์ — maintenance: staff เทียบเท่า admin ทุก verb (owner override 2026-09-24 "staff can do like admin for this")**
   - **kind=maintenance: admin และ staff ทำได้ครบ** POST/PATCH/DELETE/GET — หัวหน้าหน้างานเห็นห้องพังจัดการเองได้ไม่ต้องรอ
   - **kind=reserved: admin เท่านั้นทุก verb** — staff POST/PATCH kind=reserved → 403 (guard ใน controller, kind immutable ทำให้ PATCH ตรวจครั้งเดียวพอ)
   - GET รายห้องเปิดทั้งสอง role เห็นทุก kind (ข้อมูลห้อง public อยู่แล้ว — `GET /rooms*` pre-auth)

**Response 201/200 ของ POST/PATCH (proposal ให้ ticket 06 จัดรูป):** period ผลลัพธ์หลัง merge (ตาม convention repo — model ตรง ๆ ไม่มี data wrapper) + `merged: bool` (มีเมื่อ auto-merge เกิด) + `deleted_drafts: [booking_id...]` + `affected_bookings: [{booking_id, confirmation_number, status, check_in, check_out}]` (เฉพาะ confirmed/checked_in ที่ overlap union window) · throttle ตาม precedent กลุ่ม admin (`/discounts`) ไม่ใส่เพิ่ม

> 📝 **Amendment (ticket 05, owner grill 2026-09-24):** `end_date` เป็น **nullable ได้ทั้งสอง kind** (เปิดปลาย — "มี start ไม่มี end") — ขัดข้อบังคับเดิมในข้อ 2 ที่ว่า "ห้าม period หมดทั้งช่วง (end_date ต้อง >= วันนี้)" ใช้เฉพาะเมื่อใส่ค่า · นิยาม active = `start_date <= today AND (end_date IS NULL OR end_date > today)` · same-kind merge กับ row เปิดปลาย → ยังเปิดปลาย · รายละเอียดครบที่ ticket 05 Resolution
