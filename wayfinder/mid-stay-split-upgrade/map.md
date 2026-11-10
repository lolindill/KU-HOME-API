---
label: wayfinder:map
title: "split-upgrade — แบ่งช่วงพักครึ่งหนึ่งย้ายขึ้น tier สูงกว่า (mid-stay)"
status: open
---

# Wayfinder Map — Mid-stay split-upgrade (แบ่งครึ่งช่วงพักอัปเกรด tier)

## Destination

Endpoint ฝั่ง front-desk ใหม่แบ่ง `booking_room` ที่ `checked_in`/`confirmed` ออกเป็นสองช่วง:
**BR-1** ถูกตัด `check_out` สั้นลงถึง `split_date` (คง tier/สัญญาเดิม ราคาสัดส่วนตามคืนที่เหลือ) + **BR-2** ใหม่ช่วงครึ่งหลังบน `room_type` ที่ tier สูงกว่า (ราคา manual หรือ computed) — ผ่าน `reprice()` override จบด้วย surcharge envelope
เสร็จเมื่อ: endpoint ทำงานครบตาม [tickets/01](tickets/01-implement-split-upgrade.md) · suite [tickets/02](tickets/02-tests-split-upgrade.md) ผ่านครบ + ไม่มี regression · เอกสาร [tickets/03](tickets/03-docs.md) อัปเดตครบ

## Notes (สิ่งที่สำรวจจากโค้ด — grilling session 2026-11-10)

- **`booking_rooms` เก็บวันต่อห้องอยู่แล้ว** (`check_in`/`check_out` เป็น date columns, BookingRoom.php:28-29 — comment "แต่ละห้องมีวันที่ต่างกันได้") · บิลเดียวมีหลาย BR ช่วงต่อเนื่องได้ · `syncStatusFromRooms()` ปิดบิลเมื่อทุก BR `checked_out|no_show` (Booking.php:349-362)
- **ไม่มี code path แตะวันที่/tier ของห้อง `checked_in`** — `updateRoom` โหมด 3 allow-list มีแค่ `addons`/`extra_beds` (BookingController.php:732-738) · `changeRoom` ย้ายทั้งช่วงในหนึ่ง BR ไม่แบ่ง (FrontDeskController.php:346+)
- **`change-room` = precedent หลักที่ copy ได้:** upgrade-only เทียบ `GlobalRate::getEffectiveDailyRate` (:456-466) · validate ห้อง `available|prep_checkin|dirty` (:403) · maintenance period overlap reject (:412-421) · overlap guard `holdingSlot()` (:425-438) · guests cap (:447) · audit `StatusChangeLog` from=to + note (:569-578) · housekeeping side effects (:481-534)
- **`checkInRoom`/`checkOutRoom` ไม่ผูก validation กับวันปัจจุบัน** (FrontDeskController.php:155-268, 620-672) — พิธี manual บนวันที่ย้อนหลังใช้ได้จริง · ประตูเงินตอนเช็คเอาท์ (มติ "ตั้งแต่ห้องแรก" :652-667) คุม surcharge ค้างชำระอัตโนมัติ (ยกเว้นบิล deferred)
- **`reprice()` เป็น chokepoint เดียวที่เขียน `amount`** (DiscountService.php:179-292) — ปัจจุบันคำนวณใหม่**ทุก BR จาก global_rates วันนี้** → กับดักเขตราคา (monthly gate 20 คืน :194-207, group min_5/min_10 นับ `billRoomCount` :195, :219-229) — split ใช้**สัญญาสัดส่วน + manual price** เลี่ยงกับดักทั้งหมด (มติ #5) โดยเพิ่ม override map เข้า reprice ไม่ bypass
- **addon เป็น row ต่อ BR** (`$br->addon`): breakfast = sets × rate × **คืนของ BR นั้น** (AddonPricing::breakfastTotal) · `extra_beds_by_night` = map รายคืน คีย์ต้องอยู่ใน `[check_in, check_out)` (AddonPricing.php:133) แต่ **reprice อ่าน map ตรงไม่ re-validate** → ตัด BR-1 แล้วต้อง re-slice map เอง ไม่งั้นนับเงินคืนที่ห้องไม่ได้พัก
- **discount redemption ผูก per-BR** + `isEligible` เช็คหน้าต่างวัน BR ครบ (DiscountService.php:116-128) + ใส่โค้ดได้เฉพาะ draft (`applyToDraft` :24-28) → BR-2 ใหม่กลางทางได้โค้ดไม่ได้ตามสถาปัตยกรรม → v1 บล็อกบิลมีโค้ด (มติ #6)
- `booking_type`/`stay_type`/`nights` เป็น derived per-BR — split แล้ว mirror ปรับเอง · 90-night cap นับต่อห้อง (ตัดแล้วเล็กลง ไม่มีปัญหา) · ไม่ต้อง migration ใหม่ (ไม่มี column เพิ่ม)

## Locked decisions — grill-me session 2026-11-10 (8 มติ ห้าม re-litigate โดยไม่มีเหตุผลใหม่)

1. **Scope:** BR สถานะ `checked_in` + `confirmed` (confirmed = staff มีเครื่องมือจัดบิลก่อนเช็คอินโดยไม่ต้องรอ admin mode 2)
2. **split_date:** ต้องอยู่ใน `(check_in, check_out)` เคร่งครัด — ครึ่งละ ≥ 1 คืน · **ย้อนหลังได้** (เคสจริง: กรอกเอกสารช้ากว่าวันย้ายจริง) · อนาคตได้ (BR-2 confirmed ยึด slot ผ่าน `holdingSlot()`)
3. **พิธีกรรม: Manual ทุกกรณี** — split สร้างโครงเท่านั้น (trim BR-1 + BR-2 `confirmed` เสมอ ไม่ auto เช็คอิน/เอาท์ ไม่ auto ย้ายสถานะห้อง) · front desk เช็คเอาท์ BR-1 / เช็คอิน BR-2 เองวันจริงด้วย flow ปกติ
4. **Money guard:** airtight — รวมแล้วต้องไม่ลด (รายละเอียดสูตรเทียบที่ ticket 01: ใช้ส่วนค่าห้องก่อนปัดเป็นตัวเทียบ exact เลี่ยง artifact `RoundToTen`)
5. **ราคา = สัญญาสัดส่วน + manual ครึ่งหลัง:** BR-1 `room_amount ใหม่ = intdiv(room_amount₀ × n₁, n₀)` (คงเรทสัญญา ไม่คำนวณจาก global_rates) · BR-2 ใช้ `price_per_night` ที่กรอก (**admin only**) หรือ computed `getEffectiveDailyRate` ของ tier ใหม่ (staff) · ทั้งหมดไหลผ่าน `reprice()` ด้วย override map — chokepoint ยังเขียน `amount` ทางเดียว · upgrade-only: tier ใหม่ต้องแพงกว่า + manual price ≥ เรทเฉลี่ยคืนของ BR-1 (ceil)
6. **ส่วนลด:** บิลที่มี `discount_code`/redemption (held หรือ used) → **422 ใน v1** (ข้อความชัด)
7. **Addon: carry + re-slice ที่ split_date** — breakfast sets คัดลอก BR-2 (× คืนของแต่ละ BR อัตโนมัติ) · `extra_beds_by_night` แบ่ง map (คีย์ < split_date = BR-1, ≥ split_date = BR-2 + validate `max_extra_beds` tier ใหม่) · `early_hours` คง BR-1 · `late_hours` ย้าย BR-2
8. **ห้อง BR-2: จัดทันทีตอน split** — ส่ง `room_id` = pin (validate ตาม change-room) · ไม่ส่ง = `RoomAllocator::allocate([$br2])` · fail → rollback 422 ทั้ง transaction · ยึด slot ทันทีผ่าน holdingSlot (BR-2 confirmed กันเช็คอินซ้อนโดย guard ที่ FrontDeskController.php:230-245)

## Decisions so far

- (2026-11-10) grilling session: ล็อกมติทั้ง 8 ข้อข้างบน + สำรวจโค้ดยืนยันว่าพิธี manual บนวันย้อนหลังทำงานได้ (ไม่มี date validation ใน checkIn/checkOut) — spec ฉบับเต็มอยู่ที่ tickets/01

## Not yet specified

- (ว่าง — ทุกโหนด grill แล้วตอบครบ ณ 2026-11-10 · ถ้า implement เจอเคสที่ spec มืด ให้ append ที่นี่แล้วเปิด ticket ใหม่)

## Out of scope

- บิลมีโค้ดส่วนลด (v1 บล็อก — เปิดภายหลังเมื่อมี demand ต้องออกแบบชะตา redemption ต่อ)
- ระบบ refund / total ลดหลัง split (airtight guard 422 ทุกเคส)
- auto-ceremony ทุกรูปแบบ รวม cron เช็คอิน/เอาท์แทนคน (มติ #3 manual — ผิดปรัชญา polling/final-design ด้วย)
- การแบ่งที่ทำให้ครึ่งใด "แพงน้อยกว่าต่อคืน" (downgrade split ห้ามเสมอ)
- WebSocket/realtime notify — ไม่เกี่ยวกับ map นี้ (ดู house_keep_plan ที่ตัดสินไปแล้ว)
