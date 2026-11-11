---
label: wayfinder:task
type: AFK
title: "PUT BR โหมด 2 ต่อยอด (change-tier) + change-room ขยาย gate — downgrade-no-refund + staff manual price"
status: open
assignee: antigravity (agent-hub, dispatch โดย session 2026-11-11)
blocked-by: []
---

# 01: Tier-change family — PUT โหมด 2 + change-room

## Question

ต่อยอด 2 endpoint เดิมให้ครอบ family การเปลี่ยน tier ตาม [map](../map.md) มติ v2 ข้อ 1-4, 6 (อ่าน map ก่อนเสมอ) — บน feature branch แล้ว merge เข้า `dev`

## A. `PUT /bookings/{bookingId}/rooms/{bookingRoomId}` โหมด 2 (BookingController::updateRoom :660+)

- Gate เดิม: บิล `paid|confirmed` + BR `confirmed` (ก่อนเช็คอิน) · **เพิ่มเงื่อนไข: BR `room_id` ต้องเป็น null** — มี room_id → 422 พร้อมข้อความชี้ใช้ change-room (**breaking** — capability releaseRoom เดิมย้ายไป change-room)
- **เปิด role: admin + staff** (เดิม admin-only — โหมด 3 addon-only คงเดิมไม่แตะ) · ประกอบกับ allow-list โหมด 3 เดิมต้องยังทำงานถูกสำหรับ checked_in
- **room_type_id: ขึ้น/ลงได้** (ถอด upgrade-only validation เดิม) · ราคา: manual `price_per_night` (admin+staff — ใหม่) หรือ computed `GlobalRate::getEffectiveDailyRate($newType, $booking->user)` × nights · `room_id` param → 422 (BR scope นี้ไม่มีห้อง)
- guests cap เทียบ `max_guests` tier ใหม่ (422 แบบ reword มติ #11 เดิม — แก้ guests ได้ผ่าน PUT ตัวเดียวกันใน payload จึงบอกทางแก้ได้) · addon cap: `extra_beds_by_night` รายคืน ≤ `max_extra_beds` tier ใหม่ (เกิน 422) · dates แก้ได้ตามเดิม (availability re-check คงเดิม)
- **เงิน:** reprice ทั้งบิลตาม pipeline เดิม (reprice จาก global_rates วันนี้ — float semantics มติ #9 map เดิมยังจริง) · upgrade → surcharge envelope เดิม · **downgrade → ตามมติ v2 ข้อ 1:** ตัด ledger-guard 422 "ลดยอดต่ำกว่า paid" ในโหมดนี้, outstanding clamp 0, **`Booking::syncIsPaid()` recompute** (is_paid กลับ true ได้ถ้า paid ≥ total+charges) · `surcharge_amount` ติดลบได้
- Audit: note row from=to บันทึก direction + ราคาเดิม→ใหม่ (pattern เดิม)

## B. `POST /front-desk/{bookingId}/rooms/{bookingRoomId}/change-room` (FrontDeskController::changeRoom :346+)

- **Gate ขยาย: BR `checked_in` หรือ `confirmed` ที่มี `room_id`** (เดิม checked_in เท่านั้น) · บิล `confirmed` คงเดิม
- **พารามิเตอร์ใหม่ `price_per_night` (nullable int, admin+staff)** — ไม่กรอก = computed effective rate tier ใหม่ (เจ้าของบิล) · **ถอด upgrade-only validation เดิม (:456-466)** — เปลี่ยน tier ขึ้น/ลงได้
- **Money ตามมติ v2 ข้อ 1** — reprice pipeline เดิม (ทั้งบิลจากเรทวันนี้) · upgrade → surcharge envelope/ledger เดิม · downgrade → ตัด ledger-guard 422, outstanding clamp, `syncIsPaid()`, surcharge ติดลบได้
- guests cap tier ใหม่ (เดิม :447), validate ห้องใหม่ (สถานะ :403 / maintenance :412-421 / holdingSlot :425-438 — exclude BR ตัวเอง), **type-match pin: room ใหม่คือ tier ใหม่เสมอ** (type มาจากห้องจริงตามโครงสร้างเดิม)
- **Housekeeping ceremony เฉพาะ BR `checked_in`** (:481-534 เดิมครบ — ห้องเก่า checkout_makeup + task, ห้องใหม่ occupied) · **BR `confirmed` = re-point `room_id`/`room_type_id` ล้วน ไม่แตะสถานะห้องกายภาพทั้งเก่า/ใหม่ ไม่สร้าง task** (มติ v2 ข้อ 4) · audit note row ทั้งสองกรณี (บันทึกว่า pre-checkin move ถ้า confirmed)

## กติกาเหล็ก

- Thai error messages + emoji · ทุก state change ผ่าน transitionStatus (ไม่มีใหม่ในงานนี้ — BR status ไม่เปลี่ยนทั้งสอง endpoint) · `DB::transaction` + lock pattern เดิม · money integer baht · ห้ามแตะ tests/ (ticket 02 ของ map นี้จัดการ) · pint --dirty ก่อน commit · commit บน branch ปัจจุบัน

## Edge cases ต้องครอบ

- mode 2: BR มี room_id → 422 ชี้ change-room · staff ยิง → ผ่าน (role ใหม่) · downgrade จน paid > total → outstanding 0 + is_paid true · upgrade จนเกิดค้าง → is_paid false (เดิม) · บิล overpay (paid > total ก่อนแก้) → ทำงานปกติไม่ 422
- change-room: BR confirmed + room (post auto-assign T-2) ย้ายได้ก่อนเช็คอิน — ห้องเก่า/ใหม่สถานะกายภาพคงเดิม · checked_in ceremony ครบเหมือนเดิม (regression) · pin ห้อง type เดียวกัน (ย้ายล้วนไม่เปลี่ยน tier) ผ่าน · downgrade tier ผ่าน + เงินถูกตามมติ ข้อ 1 · billRoomCount group 5/10 reprice ยังทำงานตาม pipeline เดิม
- deferred bill: ทั้งสอง endpoint ผ่านปกติ — surcharge/ยอดลด เข้า outstanding เดิม (ประตูเงินตอนเช็คเอาท์ยกเว้น deferred อยู่แล้ว)

## Definition of done

ทั้งสอง endpoint ครบตาม spec + edge ครบ · pint สะอาด · full suite เดิม (904) ผ่าน — ถ้า suite เดิม assert พฤติกรรมเก่าที่ถูก supersede (เช่น upgrade-only ของ change-room/admin-only mode 2) ให้ **หยุดรายงาน** อย่าแก้ tests เอง (ticket 03 ของ map นี้ re-target)

## 🩹 Amendment (2026-11-11 — มติ v2 ข้อ 7, งานต่อเนื่องของ ticket นี้)

Review 01 (`bd898af`) ผ่าน แต่ orchestrator พบ dead-end: BR ก่อนเช็คอิน = `draft` เสมอ (ไม่มี path ไป confirmed ก่อน check-in) → BR draft ที่ T-2 จัดห้องแล้ว ตี 422 ทั้ง mode 2 และ change-room → owner มติ: **เพิ่ม `draft` เข้า gate ของ change-room** (เฉพาะ BR ที่มี `room_id` — guard room_id มีอยู่แล้ว)

- Gate เป็น `draft|confirmed|checked_in` + มี room_id · บิล: draft/confirmed BR → `paid|confirmed` (isInStay=false path เดิมครอบ) · checked_in → `confirmed` (เดิม)
- พฤติกรรม draft เหมือน confirmed ทุกอย่าง: re-point ล้วน ไม่แตะห้องกายภาพ ไม่สร้าง task · price_per_night ใช้ได้ · audit "pre-checkin move"
- ปรับข้อความ error ของ gate ให้ระบุ draft (ที่มีห้อง) — ไทย+emoji
- ticket 03 ต้องมี tests ครอบกรณี draft+room ด้วย
