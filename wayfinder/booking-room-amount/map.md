---
label: wayfinder:map
title: Booking per-room amount — Σ booking_rooms.amount == bookings.total_amount
status: closed
---

# Wayfinder Map — Booking per-room amount

## Destination

ตามโจทย์ "booking total_amount → add amount to each booking_room": ปลายทางคือ **spec ที่ implement ต่อได้ทันที** (หรือ change ที่ landed ถ้านายท่านสั่งต่อ) โดยตอนจบต้องได้:

1. ทุก `BookingRoom` มี `amount` ของตัวเอง — ความหมาย (net/gross/no-column) ถูก confirm แล้ว (ticket แรก)
2. Invariant ถูก maintain ครบ**ทุก write path**: create / addRooms / updateRoom / batch update / destroyRoom / discount set+remove / **walkIn** (จุดเดียวที่ตอนนี้ bypass)
3. ข้อมูลเก่าถูก backfill ด้วย strategy ที่ตัดสินใจแล้ว (รันได้ทั้ง SQLite local และ PostgreSQL prod)
4. Docs สะท้อนจริง: `docs/api_guide.md`, `docs/database-er.md`, `cline.md` + test matrix ที่บังคับ invariant

## Notes

- **Domain:** KU HOME API — money เป็น **integer satang**, amounts เปลี่ยนได้เฉพาะตอน booking เป็น `draft` (+ `walkIn` ที่สร้างสถานะอื่น direct) — หลัง `paid`/`confirmed` ตัวเลขห้ามขยับ
- **Single recompute chokepoint:** `DiscountService::reprice()` (`app/Services/Discount/DiscountService.php:157-188`) — ปัจจุบันเขียน `room_amount` + `discount_amount` ลง booking_rooms และ `total_amount` (net) ลง bookings แล้ว; ถูกเรียกครบ 5 mutation paths ผ่าน `BookingController::reconcileDiscount()` (`:1684-1702`) + `destroyRoom` (`:1067`)
- **⚠️ รูรั่วเดียวตอนนี้:** `FrontDeskController::walkIn` (`app/Http/Controllers/Api/V1/FrontDeskController.php:61-73`) เขียน `total_amount` ตรง และสร้าง BookingRoom โดยไม่มี amounts (คง default 0) + ไม่สร้าง addon row
- **ของที่มีแล้ว:** `booking_rooms.room_amount` (= rate×nights, gross ค่าห้อง) และ `discount_amount` จากยุค discount v2.1 (migration `2026_08_27_110000` :55-56) — สิ่งที่ยังไม่มีคือ per-room total ที่รวม addon (addon prices อยู่ตาราง `addons` แยก 4 columns)
- **Skills สำหรับ session ที่มาทำ ticket:** ใช้ `grill-me` แทน "grilling" (เครื่องนี้ไม่มี skill `grilling`/`domain-modeling`/`prototype`/`research` — งานสำรวจใช้ Explore agent)
- **Tracker = local-markdown** (เครื่องนี้ไม่มี `gh` CLI): map ที่ไฟล์นี้, tickets ใน `tickets/` · claim ticket = เติม `assignee:` ใน front-matter ก่อนลงมือ · blocking = front-matter `blocked-by` · ปิด ticket = `status: closed` + เขียน `## Resolution` ท้ายไฟล์
- **HITL:** ครบทั้ง 5 decision แล้ว (T1–T4, T6 — ผู้ใช้ยืนยัน 2026-09-03) · เหลือ [T5](tickets/T5-implementation-spec.md) เป็นงานประกอบ (assembly) เท่านั้น ไม่มี decision ค้าง

## Decisions so far

- [T1 — `amount` บน booking_room หมายถึงอะไร (net / gross / ไม่เพิ่มคอลัมน์)?](tickets/T1-amount-semantics.md): เลือก **(A) net ต่อห้อง** — `amount = room_amount − discount_amount + addon 4 รายการ` → invariant Σ amount == total_amount; เขียนที่ chokepoint `reprice()` เดียว
- [T2 — walkIn path เข้า invariant ไหม และด้วยกลไกใด?](tickets/T2-walkin-invariant.md): **ต้อง conform** — สั่ง `reprice()` ใน `walkIn()` ตอน booking ยัง draft (หลังสร้าง BR ก่อน transition); พิสูจน์แล้ว `reprice()` ไม่มี guard ขวาง
- [T3 — Backfill `amount` ให้ข้อมูลเก่าอย่างไร?](tickets/T3-backfill-existing-data.md): **ไม่ต้อง backfill** — ยังไม่มีข้อมูลจริง (DB local ว่างเปล่า), `migrate:fresh --seed` ยอมรับได้; migration add-column เขียนรันปกติได้ทั้ง SQLite/PostgreSQL
- [T4 — API/docs contract: field ชื่ออะไร โผล่ตรงไหน อัปเดตเอกสารใดบ้าง?](tickets/T4-api-docs-contract.md): ชื่อ **`amount`** (integer satang) — ride along อัตโนมัติทุก response; อัปเดต api_guide / database-er / cline.md; checkOut pending message ตรวจแล้วไม่ต้องแก้
- [T6 — บังคับ invariant Σ(amount) == total_amount ด้วยกลไกใด?](tickets/T6-invariant-enforcement.md): **Test-only** — assert ใน test matrix หลังทุก mutation ไม่มี runtime guard/observer
- [T5 — รวบทุก decision เป็น implementation spec + test matrix (hand-off ให้ /implement)](tickets/T5-implementation-spec.md): spec รวมอยู่ `cline.md` หัวข้อ "📐 Implementation Spec: `booking_rooms.amount` (net ต่อห้อง)" — **map ปิด ทางชัดหมด fog** ✅

## Not yet specified

- **ผลกระทบ frontend `ku-home`:** field ใหม่เป็น additive น่าจะปลอดภัย แต่ repo ไม่อยู่บนเครื่องนี้ — ต้องประสานเช็ค consumer ที่อ่าน `total_amount`/`room_amount` และ response ของ addRooms (`added_amount`) ภายหลัง

<!-- ปิดไปแล้ว: rate ย้อนหลัง (ละลายเพราะ T3 — ไม่มีข้อมูลเก่า) · checkOut pending message (ตรวจแล้วใน T4 — ไม่ต้องแก้) -->

## Out of scope

- **แก้ฐานคิดส่วนลด / discount math** — freeze ตาม D1 (`cline.md`): base = `room_amount` เท่านั้น, addons ไม่โดนลด, redemptions ผ่าน `DiscountService` เท่านั้น
- **`receipts` table (FROZEN 2026-07-24) และ payment flow** — ไม่แตะ
- **เปลี่ยนนโยบายราคา walk-in** (เช่น ให้มี addon ได้) — ทำเฉพาะ "amounts ของ walk-in สอดคล้อง invariant" เท่านั้น
- **WebSocket/realtime dashboard** — ไม่เกี่ยวกับ map นี้
