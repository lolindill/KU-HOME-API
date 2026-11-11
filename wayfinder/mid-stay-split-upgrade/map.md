---
label: wayfinder:map
title: "split-upgrade — แบ่งช่วงพักครึ่งหนึ่งย้ายขึ้น tier สูงกว่า (mid-stay)"
status: open
---

# Wayfinder Map — Mid-stay split-upgrade (แบ่งครึ่งช่วงพักอัปเกรด tier)

## Destination

Endpoint ฝั่ง front-desk ใหม่แบ่ง `booking_room` ของบิลที่ยังไม่ปิด (container `confirmed`/`draft` — BR `checked_in`/`confirmed`/`draft`) ออกเป็นสองช่วง:
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

## Locked decisions — grill-me session 2026-11-10 (8 มติ) + scrutinize session 2026-11-11 (ข้อ 9-11) — ห้าม re-litigate โดยไม่มีเหตุผลใหม่

1. **Scope (แก้รอบสอง 2026-11-10):** **container `confirmed` หรือ `draft` เท่านั้น** (เดิม `paid|confirmed` — ตัด `paid` ออก เพิ่ม `draft`) · container `confirmed` → BR `checked_in|confirmed` · container `draft` → BR `draft` (BR-2 mirror สถานะบริบท)
2. **split_date (แก้รอบสอง 2026-11-10):** ต้องอยู่ใน `(check_in, check_out)` เคร่งครัด — ครึ่งละ ≥ 1 คืน · **ต้อง ≥ วันนี้ ห้ามย้อนหลัง** (เพิกถอน backdated ของรอบแรก) · อนาคตได้ (BR-2 ยึด slot ผ่าน `holdingSlot()`)
3. **พิธีกรรม: Manual ทุกกรณี** — split สร้างโครงเท่านั้น (trim BR-1 + BR-2 สถานะ mirror: `confirmed` ภายใต้บิล `confirmed` / `draft` ภายใต้บิล `draft` — ไม่ auto เช็คอิน/เอาท์ ไม่ auto ย้ายสถานะห้อง) · front desk เช็คเอาท์ BR-1 / เช็คอิน BR-2 เองวันจริงด้วย flow ปกติ
4. **Money guard:** airtight — รวมแล้วต้องไม่ลด (รายละเอียดสูตรเทียบที่ ticket 01: ใช้ส่วนค่าห้องก่อนปัดเป็นตัวเทียบ exact เลี่ยง artifact `RoundToTen`)
5. **ราคา = สัญญาสัดส่วน + manual ครึ่งหลัง:** BR-1 `room_amount ใหม่ = intdiv(room_amount₀ × n₁, n₀)` (คงเรทสัญญา ไม่คำนวณจาก global_rates) · BR-2 ใช้ `price_per_night` ที่กรอก (**admin only**) หรือ computed `getEffectiveDailyRate` ของ tier ใหม่ (staff) · ทั้งหมดไหลผ่าน `reprice()` ด้วย override map — chokepoint ยังเขียน `amount` ทางเดียว · upgrade-only: tier ใหม่ต้องแพงกว่า + manual price ≥ เรทเฉลี่ยคืนของ BR-1 (ceil)
6. **ส่วนลด:** บิลที่มี `discount_code`/redemption (held หรือ used) → **422 ใน v1** (ข้อความชัด)
7. **Addon: carry + re-slice ที่ split_date** — breakfast sets คัดลอก BR-2 (× คืนของแต่ละ BR อัตโนมัติ) · `extra_beds_by_night` แบ่ง map (คีย์ < split_date = BR-1, ≥ split_date = BR-2 + validate `max_extra_beds` tier ใหม่) · `early_hours` คง BR-1 · `late_hours` ย้าย BR-2
8. **ห้อง BR-2: จัดทันทีตอน split** — ส่ง `room_id` = pin (validate ตาม change-room) · ไม่ส่ง = `RoomAllocator::allocate([$br2])` · fail → rollback 422 ทั้ง transaction · ยึด slot ทันทีผ่าน holdingSlot (BR-2 confirmed กันเช็คอินซ้อนโดย guard ที่ FrontDeskController.php:230-245)
9. **(scrutinize 2026-11-11) ราคา split เป็น one-shot (float + document):** `reprice()` ครั้งถัดไปหลัง split (เช่นเพิ่ม addon ผ่าน updateRoom โหมด 3 BookingController.php:912, change-room :538) จะคิดใหม่ BR-1/BR-2 จาก global_rates วันนี้ — ราคาตกลงตอน split (manual price + intdiv สัญญา) ไม่รอด · ยอมรับโดยตั้งใจ **ไม่มี migration/persist override** — จด warning ใน cline.md + api_guide ให้ front desk ตรวจ surcharge envelope หลังแตะ addon บนบิล split ทุกครั้ง + test ยืนยันพฤติกรรม
10. **(scrutinize 2026-11-11) Money guard ชุดสุดท้าย = pair guard + pin ทุก BR + ledger guard:** ตอน split ส่ง override map **ครบทุก BR ของบิล** (BR อื่น freeze ที่ `room_amount` ปัจจุบันของตัวเอง) → total delta = คู่ split เท่านั้น ไม่มี drift/group-flip · guard: (a) `br1' + br2 ≥ room_amount₀` exact (b) ledger `newTotal + charges < paid → 422` + reset `is_paid=false` เมื่อ surcharge ทำยอดค้าง (pattern BookingController.php:920-934) · **ไม่มี guard total** (ตาม step 11 เดิม) — edge #63/test group-flip-422 ของ ticket เดิมยกเลิก
11. **(scrutinize 2026-11-11) guests-cap 422 reword:** ข้อความ "บอกให้ลดก่อน" เป็นทางตัน (แก้ guests บน BR `checked_in` ไม่ได้ทาง API — allow-list โหมด 3) → เปลี่ยนเป็นแจ้งตรงว่า "split ขึ้น tier นี้ไม่ได้เพราะแขกเกิน cap — เลือก tier ที่รองรับครบ" (แก้ข้อความเท่านั้น ไม่เพิ่ม guests override ใน payload)

## Decisions so far

- (2026-11-10) grilling session: ล็อกมติทั้ง 8 ข้อข้างบน + สำรวจโค้ดยืนยันว่าพิธี manual บนวันย้อนหลังทำงานได้ (ไม่มี date validation ใน checkIn/checkOut) — spec ฉบับเต็มอยู่ที่ tickets/01
- (2026-11-10 รอบสอง) owner แก้ 2 มติ: (1) container gate = **`confirmed|draft`** — ตัด `paid`, เพิ่ม `draft` (2) **ห้าม split_date ย้อนหลัง** — ต้อง ≥ วันนี้ · tickets 01/02 sync ตามแล้ว
- (2026-11-11 scrutinize session) ตรวจ end-to-end พบ 9 findings (3 ใหญ่) — positive: holdingSlot ครอบ checked_in, syncStatusFromRooms ปิดเมื่อครบจริง, addon re-slice ทำได้จริง, intdiv manual path airtight พิสูจน์ผ่าน · owner ตัดสิน 3 มติเพิ่ม (ข้อ 9-11): float+document / pin ทุก BR / reword message · objective amendments (ledger guard + is_paid, type-match pin, BR-2 ผ่าน transitionStatus, staff pre-flight, tz, Collection, ตัด exclusion) ยัด tickets 01/02/03 ครบแล้ว

## Not yet specified

- (ว่าง — ทุกโหนด grill แล้วตอบครบ ณ 2026-11-10 · scrutinize session 2026-11-11 เพิ่ม 3 มติ (ล็อกแล้ว ดู Locked decisions ข้อ 9-11) + objective amendments ยัด tickets 01/02/03 เรียบร้อย · ถ้า implement เจอเคสที่ spec มืด ให้ append ที่นี่แล้วเปิด ticket ใหม่)

## Out of scope

- บิลมีโค้ดส่วนลด (v1 บล็อก — เปิดภายหลังเมื่อมี demand ต้องออกแบบชะตา redemption ต่อ)
- ระบบ refund / total ลดหลัง split (airtight guard 422 ทุกเคส)
- auto-ceremony ทุกรูปแบบ รวม cron เช็คอิน/เอาท์แทนคน (มติ #3 manual — ผิดปรัชญา polling/final-design ด้วย)
- การแบ่งที่ทำให้ครึ่งใด "แพงน้อยกว่าต่อคืน" (downgrade split ห้ามเสมอ)
- WebSocket/realtime notify — ไม่เกี่ยวกับ map นี้ (ดู house_keep_plan ที่ตัดสินไปแล้ว)
