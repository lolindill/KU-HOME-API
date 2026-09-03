---
label: wayfinder:grilling
type: grilling
status: closed
assignee: kevii
blocked-by: []
---

# T6 — บังคับ invariant Σ(amount) == total_amount ด้วยกลไกใด?

## Question

graduate จาก fog ของ map หลัง T1 ปิด (เลือก (A) net ต่อห้อง) — invariant `Σ booking_rooms.amount == bookings.total_amount` จะบังคับด้วยกลไกไหน:

1. **Test-only (Recommendation):** test matrix ใน T5 assert Σ หลังทุก mutation — ไม่มี runtime cost; path ใหม่ที่ลืมอัปเดต `amount` โดนจับที่ CI ไม่ใช่ prod
2. **Runtime guard:** throw 422/500 ถ้า Σ เพี้ยน ณ จุด commit (เช่นท้าย `reprice()` หรือใน `reconcileDiscount()`) — จับทันทีทุก write แต่มี cost และต้องระวังไม่ทำ walk-in flow พัง
3. **Assertion เฉพาะ non-production:** guard ทำงานเมื่อ `app.env != production` — กึ่งกลางระหว่างสองแบบ

## Evidence / Context

- T1 ยืนยัน (A): `amount` = net ต่อห้อง → Σ == `total_amount`
- write paths ที่ invariant ต้องครบ: create / addRooms / updateRoom / batch update / destroyRoom / discount set+remove / walkIn (walk-in เป็น decision ของ T2)
- repo ไม่มี events/listeners โดยตั้งใจ (synchronous state changes) — ถ้าเลือก runtime guard ให้เป็นการเรียกตรงใน chokepoint ไม่ใช่ model observer
- test suite ใช้ SQLite in-memory — invariant เป็นคุณสมบัติของข้อมูล ตรวจด้วย assertion ธรรมดาได้ทุก driver

## Recommendation

**Test-only** — ทุก write path ไหลผ่าน chokepoint เดียว (`reprice()`) อยู่แล้ว (เมื่อ T2 ซ่อม walk-in แล้ว) test ครอบได้หมดโดยไม่เสีย runtime cost; runtime guard ซ้ำซ้อนกับ chokepoint และเพิ่มจุด fail ให้ flow การเงิน

## Resolution

**เลือก (1) Test-only** — ผู้ใช้ยืนยัน 2026-09-03

- invariant เป็นคุณสมบัติของข้อมูล ไม่ใช่ input validation — ทุก write path ไหลผ่าน chokepoint เดียว (`reprice()`, เมื่อ T2 ซ่อม walk-in แล้ว) จึง assert ที่ test ได้ครบโดยไม่มี runtime cost
- ไม่มี runtime guard ใน `reprice()`/`reconcileDiscount()` และไม่ทำ model observer (repo ไม่มี events/listeners โดยตั้งใจ — คงเช่นนั้น)
- **ตามไปที่ spec (T5):** test matrix assert Σ(amount) == total_amount หลังทุก mutation (create/add/update/batch/delete/discount set+remove/walk-in) — แนะนำ helper `assertAmountInvariant(Booking)` ใช้ร่วมทุกชุดทดสอบ
