---
label: wayfinder:grilling
type: grilling
status: closed
assignee: kevii
blocked-by: ["T1-amount-semantics"]
---

# T3 — Backfill `amount` ให้ข้อมูลเก่าอย่างไร?

## Question

เมื่อเพิ่มคอลัมน์ `amount` (หรือเปลี่ยน semantics ตาม T1) — booking ที่มีอยู่แล้วใน DB ต้องได้ค่า ไม่ใช่ 0 ทิ้งไว้ ให้ตัดสินใจ:

1. **กลุ่ม (ก) booking ยุคหลัง discount system** (`room_amount`/`discount_amount` มีค่า + มี addon row): คำนวณ `amount` จาก **stored data ล้วนๆ** ได้เลย — ไม่ต้องยุ่ง GlobalRate
2. **กลุ่ม (ข) booking ยุคก่อน discount system** (`room_amount` ค้าง 0): เอา rate จากไหน — (i) `GlobalRate::getRoomRate()` **ปัจจุบัน** × nights (best-effort, อาจเพี้ยนจากราคายุคนั้นจริง), (ii) ทิ้ง 0 + document, (iii) ถ้าข้อมูลเก่าน้อย/เป็น dev data → `migrate:fresh --seed` ตาม gotcha ของ repo
3. **ขอบเขต backfill:** แตะทุก booking รวม `checked_out`/`complete` (เป็นแค่ denormalized snapshot เดิมที่อยู่แล้ว ไม่ใช่การเปลี่ยนประวัติ?) หรือเฉพาะ booking ที่ยัง active เท่านั้น
4. **Migration mechanics:** backfill ใน migration เดียวกับ add-column หรือแยก / ต้อง idempotent / รันได้ทั้ง SQLite (local) และ PostgreSQL (prod) — ระวังอย่าใช้ SQLite-only SQL

## Evidence / Context

- เว้นแต่ T1 จะเลือก (C) no-column — งั้นตั๋วนี้จะกลายเป็น "backfill `room_amount` ที่ค้าง 0 จาก walk-in เก่าๆ" แทน
- `cline.md` มี precedent: การ refactor ที่ seeder/migration เปลี่ยน shape ใช้ `migrate:fresh --seed` — แต่ prod (PostgreSQL) ต้อง migration จริง
- booking ที่ `paid`/`confirmed`/`complete` เป็นหลักฐานทางการเงิน — การ backfill ด้วย rate ปัจจุบันอาจทำ snapshot เพี้ยนจากที่เก็บสลิปไว้

## Recommendation

แยก migration: (1) add column, (2) backfill กลุ่ม (ก) จาก stored data ทุก booking, กลุ่ม (ข) ใช้ `GlobalRate` ปัจจุบันแล้วจดใน `cline.md` ว่า best-effort — และเช็คจำนวนแถวกลุ่ม (ข) ก่อนเสมอ (ถ้าเป็น 0 ใน prod ก็ไม่มีปัญหา)

## Resolution

**ไม่ต้อง backfill — ใช้ `migrate:fresh --seed` ได้ เพราะยังไม่มีข้อมูลจริง** (ผู้ใช้ยืนยัน 2026-09-03)

- ผู้ใช้ระบุ: ตอนนี้ระบบยังไม่ใช้ข้อมูลจริง ทั้ง local (ตรวจแล้ว: **DB ว่างเปล่า 0 bookings / 0 booking_rooms**) และ prod → คำถาม rate เก่า (GlobalRate vs ทิ้ง 0) และขอบเขต (active vs ที่จบแล้ว) **ละลายไปเอง** — ไม่มีแถวให้ backfill
- **Migration mechanics (ตามความหมายที่ผู้ใช้เสริม "migrate แบบ migrate ปกติได้ก็ดี"):** migration add column `amount` (integer, default 0) เขียนให้รันได้ปกติด้วย `php artisan migrate` ทั้ง SQLite/PostgreSQL (ไม่พึ่ง fresh เพียงอย่างเดียว, idempotent-friendly, ไม่ใช้ SQLite-only SQL) — แต่ workflow ตอน deploy อนุญาตให้ `migrate:fresh --seed` ได้สบาย
- ** Defensive สำหรับอนาคต:** ถ้าก่อน deploy มีข้อมูลสะสมเข้า prod จริง ให้เติม backfill จาก stored data (`room_amount − discount_amount + addon 4 รายการ`) ก่อน — จดกันไว้ใน cline.md ตอน implement (T5)
- seeder ไม่ต้องแกะ (ไม่สร้าง booking เป็น default) — fresh แล้ว table ว่าง ไม่มี invariant หัก
