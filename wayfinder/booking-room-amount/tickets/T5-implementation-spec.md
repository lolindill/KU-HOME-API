---
label: wayfinder:task
type: task
status: closed
assignee: kevii
blocked-by: ["T1-amount-semantics", "T2-walkin-invariant", "T3-backfill-existing-data", "T4-api-docs-contract", "T6-invariant-enforcement"]
---

# T5 — รวบทุก decision เป็น implementation spec + test matrix (hand-off ให้ /implement)

## Question / Work

งานประกอบ (assembly) ปิดท้ายของ map — รวม decision จาก T1–T4 เป็น spec ที่หยิบไป implement ได้ทันที โดยไม่ตัดสินใจใหม่อีก:

1. **รายการแฟ้มที่ต้องแตะ** (จากการสำรวจ session charting):
   - `app/Services/Discount/DiscountService.php::reprice()` — เขียน `amount` ต่อจาก `room_amount`/`discount_amount` ใน loop เดียวกัน (`:171-185`)
   - `app/Http/Controllers/Api/V1/BookingController.php` — จุดคำนวณซ้ำ 3 จุด (`addRooms :322-343`, `updateRoom :640-684`, `updateRooms :896-949`, และ `createBooking :1207-1223`) พิจารณาดึงเป็น helper เดียว แล้วพาไปจบที่ `reconcileDiscount()` (`:1684-1702`)
   - `app/Http/Controllers/Api/V1/FrontDeskController.php::walkIn` (`:61-73`) — ตาม T2
   - `app/Models/BookingRoom.php` — fillable + integer cast สำหรับคอลัมน์ใหม่
   - migration + backfill — ตาม T3
2. **Test matrix:** extend `BookingTest` (invariant Σ หลังทุก mutation: create/add/update/batch/delete/early-late), `DiscountTest` (Σ ยังครบหลัง set/remove โค้ด, ทั้ง eligible บางห้อง), `FrontDeskTest` (walk-in มี amounts ครบ), `PaymentTest` (ไม่กระทบ flow เดิม)
3. **ลำดับงาน:** migration → model → reprice/helper → walkIn → tests → docs (api_guide/database-er/cline.md ตาม T4)
4. **ที่เก็บ spec:** เขียนลง `cline.md` (ธรรมเนียม repo: document design ก่อน coding; `docs/` ถูก whitelist เฉพาะบางไฟล์) — แล้วตอบใน Resolution ว่า spec อยู่ section ไหน

Answer when done: spec อยู่ที่ไหน + map นี้ปิดได้ (ทุก decision ลงตัว ไม่มี fog ค้างที่ขวางทาง)

## Resolution

**Spec อยู่ที่ `cline.md`** — หัวข้อท้ายไฟล์: **"📐 Implementation Spec: `booking_rooms.amount` (net ต่อห้อง) — wayfinder "Booking per-room amount" (2026-09-03, รอ implement)"** (ประกาศ 2026-09-03)

สเปกรวมครบตามขอบเขตตั๋วนี้:

1. แฟ้ม+ลำดับงาน 7 ขั้น: migration add column (ไม่มี backfill — T3) → BookingRoom model (fillable+cast) → `reprice()` (สูตรอยู่จุดเดียว) → `walkIn()` (reprice ตอน draft — T2) → BookingController (ไม่ต้องเขียน amount เองที่อื่น) → tests → docs
2. Test matrix 4 ชุด (Booking/Discount/FrontDesk/Payment) + helper `assertAmountInvariant(Booking)` — บังคับ invariant แบบ test-only (T6)
3. Docs: `api_guide.md` / `database-er.md` / design decision = หัวข้อใน `cline.md` นี้ (T4)
4. Fog ค้างที่จดไว้ท้ายสเปก: frontend `ku-home` (repo แยก — document เท่านั้น)

**Map ปิดได้** — ทุก decision (T1–T4, T6) ลงตัว, ไม่มี fog ขวางทาง, สเปกหยิบไป `/implement` ได้ทันทีโดยไม่ตัดสินใจใหม่
