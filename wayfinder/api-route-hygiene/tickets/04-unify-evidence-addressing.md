---
label: wayfinder:task
type: HITL
title: "Unify evidence addressing — /front-desk/booking-rooms/{id}/evidence → nested (breaking)"
summary: ย้าย evidence endpoint เป็น nested {bookingId}/rooms/{bookingRoomId} ตรงพี่น้อง — grep frontend ยืนยัน caller ก่อน
status: open
assignee: kevii (orchestrator session 2026-11-11 — dispatch antigravity/gemini-3.8-flash @ .worktree/unify-evidence-addressing · frontend grep ไม่ได้ repo ไม่อยู่บนเครื่อง — ยืนยันจาก reverse index snapshot 2026-11-06 + จด limitation ใน patch note)
blocked-by: []
---

# 04: Unify evidence addressing

## Question

จะย้าย `POST/GET /front-desk/booking-rooms/{id}/evidence` ให้อ้าง booking_room แบบ nested (`/front-desk/{bookingId}/rooms/{bookingRoomId}/evidence`) ตรงแบบพี่น้อง check-in/out/change-room/split-upgrade ได้เมื่อไหร่

## Verified inventory (2026-11-11 — จากไฟล์จริง)

### Route ปัจจุบัน

- `POST /front-desk/booking-rooms/{id}/evidence` → `uploadEvidence` — routes/api.php:302 (`where-uuid` มีแล้ว, กลุ่ม `role:admin,staff` — ยุบกลุ่มแล้ว 2026-11-11)
- `GET /front-desk/booking-rooms/{id}/evidence` → `getEvidences` — :304
- พี่น้อง nested ใน prefix เดียวกัน: check-in :279 · check-out :282 · change-room :288 · split-upgrade :293 — ทุกเส้นใช้ `{bookingId}/rooms/{bookingRoomId}`

### Method ปัจจุบัน

- `uploadEvidence` (`FrontDeskController.php:1339–1429`) — resolve BR ตรงจาก `{id}` (`findOrFail`) · `getEvidences` (:1430–1477) เช่นเดียวกัน
- เป้าหมาย lookup หลังย้าย: `BookingRoom::where('id', $bookingRoomId)->where('booking_id', $bookingId)` → **404 ถ้า BR ไม่อยู่ใต้ booking นี้** (กัน BR ข้ามบิล + กัน enumeration — 404 ไม่ใช่ 403, ตรง precedent ownership ของระบบ)

### Caller ฝั่ง frontend — ยังพิสูจน์ไม่จบ (ก้าวแรกก่อนลงมือ)

- Reverse index (api_pages README, snapshot 2026-11-06): **ไม่ปรากฏ caller** ของ `POST/GET /front-desk/booking-rooms/{id}/evidence` เลย — เหตุผลเดิม "frontend ใช้เส้นตรงอยู่แล้ว" อิงความเข้าใจ ไม่ใช่หลักฐาน
- **ก้าวแรก:** grep frontend repo (`booking-rooms` · `evidence` · `uploadEvidence`) — ถ้ายืนยัน zero caller → **ไม่ต้องประสาน frontend** เหลือแค่ api_patches + suite (ถูกกว่าเดิมมาก) · snapshot doc อายุ 5+ วัน ต้อง re-check ตอนถึงคิวเสมอ

## ทางเลือก (รอ lock ตอน grilling)

### (a) ย้าย + ถอดเส้นเก่า 1 รอบ breaking (แนะนำ ถ้า grep ยืนยัน zero/น้อย caller)

- มาตรฐาน nested เดียวกันทั้ง prefix `front-desk` — entity `booking_room` ถูก address รูปเดียว ไม่ใช่ 2 แบบ
- patch note + suite อัปเดต = จบ ไม่มีหนี้ค้าง

### (b) คู่ขนาน + deprecate

- ไม่แนะนำ — เหตุผลเดียวกับ ticket 03 (ค้างหนี้ตรงข้ามเป้า hygiene) · พิจารณาเฉพาะถ้า grep เจอ caller และประสานไม่ทัน

## Decision points (ต้อง lock ก่อนลงมือ)

1. **ผล grep frontend** — zero caller หรือไม่ → ตัดสินประสาน/ไม่ประสาน (ตัวเลือกใหญ่สุดของต้นทุนงานนี้)
2. **404 semantics:** BR ไม่อยู่ใต้ booking ที่ระบุ → 404 (ไม่ 403) — lock ให้ชัดก่อนเขียน error matrix
3. **จับคู่ ticket 02** — landing spot `CheckinEvidenceController` (ถ้า split 3-way) · ย้าย address + ย้ายคลาสใน branch เดียว = patch note ฉบับเดียว frontend ไม่งง 2 รอบ
4. คงพฤติกรรมเดิมให้เป๊ะทุกด้านอื่น: 365-day retention · ownership check · signed URL 15 นาที · `RequireJsonAccept` (evidence เป็น JSON response ธรรมดา — **ไม่ใช่** exemption แบบ `images/{id}/file`)

## Constraints

- patch note ใน `docs/api_patches` + ประสาน frontend ก่อน merge (precedent tier-endpoints-v2) — **ยกเว้นได้ถ้า grep ยืนยัน zero caller** (จดหลักฐาน grep ลง patch note แทน)
- `uploadEvidence`/`getEvidences` resolve BR จาก `{id}` — ต้องเพิ่ม lookup ผ่าน booking + guard bookingId ตรงกับ BR จริง (404 ถ้าไม่ตรง)
- จับคู่ suite CheckinEvidence (365-day retention + ownership) ต้องผ่านครบ

## Tests ที่ต้องแก้ (อนุญาต — URL-level change)

- `CheckinEvidenceTest` — ยิงเส้นตรงเดิมทั้ง POST/GET · `ImageTest` (evidence ผ่าน images table)
- เพิ่ม case ใหม่: BR ไม่ใช่ลูกของ bookingId → **404** (guard ใหม่)

## Acceptance criteria

- [ ] หลักฐาน grep frontend จดใน patch note (zero caller หรือรายการ call site ที่สลับแล้ว)
- [ ] patch note ใน `docs/api_patches` — breaking + error matrix (BR/booking mismatch = 404) + คง retention/ownership เดิม
- [ ] เส้นเก่า `/front-desk/booking-rooms/{id}/evidence` ถอดออก (ถ้าเลือก a)
- [ ] `CheckinEvidenceTest` + `ImageTest` อัปเดต URL + เคส guard 404 แล้วผ่านครบ
- [ ] suite 917 ผ่าน · `vendor/bin/pint --dirty` ผ่าน · จด `cline.md` + AGENTS.md

## Resolution

(open — ยังไม่ได้ทำ)
