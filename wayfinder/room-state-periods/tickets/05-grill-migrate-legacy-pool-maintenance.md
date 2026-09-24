---
label: wayfinder:grilling
type: HITL
status: closed
assignee: kevii (session 2026-09-24)
blocked-by: []
---

# 05: Grill migration ข้อมูลเดิม — pool สำรอง + ห้อง maintenance ปัจจุบัน → period แรก

## Question

โมเดลล็อกแล้ว (ticket 01 — period แทน flag) — ข้อมูลเดิมต้องแปลง แต่ค่าของ period แรกเป็น**เจตนาทางธุรกิจ**ที่ต้องถาม owner:

1. **ห้อง `is_reserved = true` ปัจจุบัน:** โมเดลใหม่ไม่มี "สำรองถาวร" — แปลงเป็น reserved period ยาว (end_date กี่ปี — 5 ปี? 99?), หรือ owner ระบุรายห้อง (ห้องไหนควรเลิกสำรอง?), หรือทิ้งไว้เป็นห้องขายปกติทั้งหมด?
2. **ห้อง `status = maintenance` ปัจจุบัน:** สร้าง maintenance period เริ่มวันนี้ — end_date ใครกำหนด (owner ประเมินรายห้อง? default กี่วัน?) · lifecycle status ของห้องพวกนี้ตอนถอดสถานะกลับเป็นอะไร (`dirty` เหมาะสุดไหม)?
3. **จังหวะ drop:** drop column `rooms.is_reserved` พร้อม migration เดียว หรือคง column ไว้รอ period จริงขึ้นก่อนแล้วค่อยถอด (deploy ปลอดภัยกว่า — แต่ dual source ชั่วครู่)?

**Blocked by:** None (โมเดลล็อกจาก ticket 01 แล้ว — frontier)

- [x] grill 3 คำถามกับ owner (+ กิ่งลูก null end_date 1 ข้อ ที่เกิดระหว่าง grill)
- [x] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far

## Resolution

**CLOSED 2026-09-24 — owner grill HITL ครบ 3 คำถาม + 1 กิ่งลูก (ฐาน: โมเดล ticket 01 + contract ticket 04 · ข้อเท็จจริง ณ grill: local SQLite มี 100 ห้อง `available` หมด ไม่มี `is_reserved=true` และไม่มี `status=maintenance` — policy นี้จึงมีผลจริงเมื่อ prod มีข้อมูลค้าง แต่ migration ต้องเขียนรองรับทุก env):**

1. **ห้อง `is_reserved = true` → reserved period เปิดปลาย:** migration สร้าง period kind=reserved `start_date` = วัน deploy · **`end_date = NULL`** — owner: "period ถาวร มี start แต่ไม่มี end" — คงพฤติกรรมที่มองเห็นอยู่ (ห้องยังถูกกันต่อ ไม่มีอะไรหายฉับพลัน) แต่ย้ายเข้าร่าง period ที่ admin ปลดได้ตลอดผ่าน DELETE/PATCH ของ contract ticket 04 · `created_by = NULL` (row จากระบบ/migration)

2. **(กิ่งลูก) `end_date` เป็น nullable ได้ — ทั้งสอง kind = amendment ของ contract ticket 04:** owner ตัดสิน "ทั้งสอง kind" (เดิม ticket 04 ล็อก end required + ต้อง >= วันนี้) → validation ใหม่: ใส่ `end_date` หรือ `null` ก็ได้ เมื่อใส่ต้อง `>= วันนี้` ตามเดิม · นิยาม active กลายเป็น `start_date <= today AND (end_date IS NULL OR end_date > today)` (end ยัง exclusive เหมือน `check_out`) · same-kind auto-merge ชนกับ row เปิดปลาย → row ผลลัพธ์ยังเปิดปลาย (union กับ ∞ = ∞) · PATCH ใส่ `end_date` ให้ row เปิดปลาย = ปิดวันจบได้ปกติ (ทุกกฎอื่นของ ticket 04 คงเดิม)

3. **ห้อง `status = maintenance` → maintenance period เปิดปลาย + status พักที่ `available`:** migration สร้าง period kind=maintenance `start_date` = วัน deploy · **`end_date = NULL`** — ระบบไม่เดาวันจบแทนความจริง (ถ้าใส่ default N วัน ห้องอาจกลับมาขายทั้งที่ยังพัง) เจ้าหน้าที่ PATCH ใส่ end / DELETE ปิดเองเมื่อซ่อมเสร็จ · lifecycle status ของห้องพวกนี้ flip เป็น **`available`** (owner override 2026-09-24 — สวนคำแนะนำ `dirty` เพราะช่วง period active การกันห้องทำงานจาก period อยู่แล้ว status เป็นแค่ lifecycle)

4. **drop column `rooms.is_reserved` พร้อม release เดียว:** migration ก้อนเดียวจบครบ — สร้างตาราง `room_state_periods` + แปลงข้อมูล + drop column + โค้ดทุกจุดที่อ่าน `is_reserved` (RoomController, RoomType, BookingPriority, RoomAllocator, FrontDeskController, UpdateRoomRequest, IncludeReservedGate) สลับเป็น period-check ขึ้นพร้อมกัน — ไม่มี dual source ชั่วครู่ (rollback ทั้งก้อนพร้อมกัน)

**รายละเอียดที่ผูกตาม decision (ให้ ticket 06 จัดรูปลง spec):**
- migration เขียน `status_change_logs` ให้ห้องที่ flip `maintenance → available` ด้วย (entity_type `room`, from/to, role system, note period migration) — ให้ประวัติ 1 ปีจาก ticket 03 ไม่มีรู
- `down()` = best-effort: สร้าง column คืน + `is_reserved=true` ให้ห้องที่มี reserved period active ณ ตอน rollback + `status=maintenance` คืนให้ห้องที่มี maintenance period active
- ⚠️ พอ drop column แล้ว JSON ของ `GET /rooms*` จะไม่มี key `is_reserved` — ticket 06 ต้องตัดสินว่าจะ expose ค่า derived (มี reserved period active) กลับเข้า room JSON ให้ frontend ku-home หรือไม่
