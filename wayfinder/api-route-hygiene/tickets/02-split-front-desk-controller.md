---
label: wayfinder:task
type: HITL
title: "Split FrontDeskController — 1,500 บรรทัด / 9 methods + 2 helpers / ครอบ 3 domain"
summary: แบ่ง FrontDeskController 3 domain เป็น FrontDesk/RoomMove/CheckinEvidence — ตัดสินการแชร์ handleCatch ข้ามคลาส
status: open
assignee: kevii (orchestrator session 2026-11-11 — dispatch antigravity/gemini-3.8-flash @ .worktree/split-front-desk-controller)
blocked-by: []
---

# 02: Split FrontDeskController

## Question

จะแบ่ง `app/Http/Controllers/Api/V1/FrontDeskController.php` (1,500 บรรทัด, **9 public methods + 2 private helpers** — ยืนยันจากไฟล์จริง 2026-11-11) อย่างไร — ปัจจุบันครอบ 3 domain ที่หน้าที่ต่างกันโดยพฤตินัย

## Verified inventory (2026-11-11 — นับจากไฟล์จริง)

### Public methods (9) — จัดกลุ่มตาม domain

| Method | บรรทัด | Domain | Route (routes/api.php) |
|---|---|---|---|
| `walkIn` | :39–159 | 🛎️ arrival | POST /front-desk/walk-in (`role:admin` รายเส้น) |
| `checkInRoom` | :160–339 | 🛎️ arrival | POST /front-desk/{bId}/rooms/{brId}/check-in |
| `checkOutRoom` | :1031–1151 | 🛎️ departure | POST .../check-out |
| `markNoShow` | :1152–1244 | 🛎️ departure | POST /front-desk/{bookingId}/mark-no-show (`role:admin` รายเส้น) |
| `recordPayment` | :1245–~1319 | 💰 money | POST /front-desk/{bookingId}/payment — ledger/`syncIsPaid` chokepoint |
| `changeRoom` | :340–607 | 🚪 room-move | POST .../rooms/{brId}/change-room (tier-change family v2) |
| `splitUpgrade` | :608–1030 (**~423 บรรทัด — method ใหญ่สุด**) | 🚪 room-move | POST .../rooms/{brId}/split-upgrade (tier-endpoints-v2) |
| `uploadEvidence` | :1339–1429 | 🪪 evidence | POST /front-desk/booking-rooms/{id}/evidence (ticket 04) |
| `getEvidences` | :1430–1477 | 🪪 evidence | GET /front-desk/booking-rooms/{id}/evidence (ticket 04) |

### Private helpers (2) + coupling ข้าม domain

| Helper | บรรทัด | ถูกใช้โดย |
|---|---|---|
| `handleCatch` | :1478 | **ครบ 3 domain** — walkIn :152 · checkInRoom :329 · changeRoom :598 · checkOutRoom :1147 · markNoShow :1240 · recordPayment :1313 — **`splitUpgrade`/`uploadEvidence`/`getEvidences` ไม่ใช้** (จัด error เอง) |
| `stripGuestFields` | :1320 | `walkIn` เท่านั้น — duplicate ของ `BookingController` :2328 อยู่แล้ว (precedent การ duplicate) |

## ⚠️ Coupling ที่ตัดสินเส้นแบ่ง

`handleCatch` แชร์ข้ามทุก domain ที่จะแบ่ง — การ split ต้องตัดสินก่อนว่า (i) **trait ใหม่** (`Concerns/HandlesFrontDeskErrors`?) หรือ (ii) **duplicate ตาม precedent `stripGuestFields`** (มี duplicate ค้างอยู่แล้วระหว่าง Booking/FrontDesk — owner ยอมรับมาก่อน) · (i) เพิ่ม surface ใหม่ระหว่าง move-only, (ii) คงผิด DRY ต่อ — อันนี้เป็น decision บริสุทธิ์ของ ticket

## ทางเลือก (รอ lock ตอน grilling)

### Option A — 3-way ตาม domain (แนะนำ)

1. **`FrontDeskController`** (คงชื่อ — 🛎️ arrival/departure + 💰 counter money): `walkIn` · `checkInRoom` · `checkOutRoom` · `markNoShow` · `recordPayment` ≈ 970 บรรทัด + `stripGuestFields` + `handleCatch`
2. **`RoomMoveController`** (🚪 — ชื่อ map กับ docs "🔀 Tier-change family v2"): `changeRoom` · `splitUpgrade` ≈ 500 บรรทัด — tests คู่กันอยู่แล้ว (ChangeRoomTest / FrontDeskSplitUpgradeTest / TierChangeFamilyTest)
3. **`CheckinEvidenceController`** (🪪): `uploadEvidence` · `getEvidences` ≈ 140 บรรทัด — จับคู่ ticket 04 (ถ้าย้าย address ใน branch เดียวกัน frontend เห็นเปลี่ยนครั้งเดียว)

### Option B — 2-way

`FrontDeskController` (ทุกอย่างยกเว้น room-move ≈ 1,360 บรรทัด) + `RoomMoveController` — evidence ค้างใน FrontDesk รอ ticket 04 ตัดสินแล้วค่อยแยกออกใน branch ถัดไป (แตกงานง่ายกว่า แต่ controller หลักยังอ้วน)

### จุดยึดทั้งสอง option

- `recordPayment` **คง main controller** — ห้ามไปรวมกับ `PaymentController` (audience ต่าง: webhook นั่น public 410, อันนี้ admin+staff) และเป็นจุดเปรียบเทียบ `is_paid` 3 จุดตาม AGENTS.md — อยู่ที่เดิมอ่านง่ายสุด
- `splitUpgrade` ~423 บรรทัด — ย้าย**ทั้ง method ตรง ๆ** (move-only) · ห้ามถือโอกาส break ลง private helpers ใน PR เดียวกัน (ถ้าอยาก refactor ให้เปิดงานแยก)

## Decision points (ต้อง lock ก่อนลงมือ)

1. **Option A หรือ B** + ชื่อ controller ใหม่ (`RoomMoveController`? ต้องไม่สับสนกับ Room board status ซึ่งเป็นอีกเรื่อง)
2. `handleCatch` → trait หรือ duplicate (ถ้า trait: ชื่อ + scope — ต้องครอบ BookingController ด้วยไหม เพราะ duplicate นั้นไม่ใช้ handleCatch)
3. evidence แยก controller คู่ ticket 04 ใน branch เดียว หรือรอ ticket 04 ก่อน
4. ลำดับ merge: ticket 02 ก่อน ticket 04 ไหม (ถ้า evidence ย้าย address + ย้ายคลาสพร้อมกัน = patch note ฉบับเดียว)

## Safety net (tests)

- `FrontDeskTest` (check-in/out/walk-in/no-show/payment) · `ChangeRoomTest` · `FrontDeskSplitUpgradeTest` · `TierChangeFamilyTest` (room-move ทั้ง family)
- `BookingSurchargeAfterPaymentTest` + `AdditionalChargeBookingTotalTest` (recordPayment — ledger ครบ 3 จุด is_paid)
- `CheckinEvidenceTest` + `ImageTest` (evidence + 365-day retention/ownership)
- `RouteProtectionTest` (เส้น walk-in/mark-no-show admin-only หลังยุบกลุ่ม 2026-11-11)
- เป้าหมาย: **0 test edits** — move-only เปลี่ยนแค่ FQCN + imports ใน `routes/api.php`

## Constraints

- Move-only refactor — ห้ามแก้ตรรกะระหว่าง split (เงิน/state machine ทั้งคู่)
- ชื่อ controller ใหม่ต้อง map กับ route prefix ที่มีอยู่ (front-desk) หรือแยกชัดเจน (เช่น RoomMove/Arrival)
- จับคู่ suite tier-endpoints-v2 (917 tests) เป็น safety net — ต้องผ่านครบ

## Acceptance criteria

- [ ] URL + verb + response shape + status code เดิมทุกเส้น — diff route file จำกัดที่ FQCN/import
- [ ] suite 917 ผ่านครบโดยไม่แก้ test · `vendor/bin/pint --dirty` ผ่าน
- [ ] จด controller inventory ใหม่ใน `cline.md` + AGENTS.md
- [ ] ถ้า evidence ย้ายคลาสพร้อม ticket 04: patch note ร่วมฉบับเดียว

## Decision locked (owner — 2026-11-11, grilling-compressed ผ่าน orchestrator)

1. **Option A (3-way ตาม domain)** — `FrontDeskController` (คงชื่อ: `walkIn` · `checkInRoom` · `checkOutRoom` · `markNoShow` · `recordPayment` + `stripGuestFields` + `handleCatch`) + **`RoomMoveController`** (`changeRoom` · `splitUpgrade` ย้ายทั้ง method ตรง ๆ) + **`CheckinEvidenceController`** (`uploadEvidence` · `getEvidences`)
2. `handleCatch` → **duplicate ตาม precedent `stripGuestFields`** (move-only ล้วน ไม่สร้าง trait) — เฉพาะคลาสที่มี method ที่เคยเรียก `handleCatch` (RoomMove/CheckinEvidence ไม่ต้องมี — `splitUpgrade`/`uploadEvidence`/`getEvidences` จัด error เองอยู่แล้ว)
3. evidence แยก `CheckinEvidenceController` **ใน branch นี้เลย** (move-only ไม่แตะ URL) — ticket 04 ค่อยแตะ address ภายหลัง
4. ลำดับ merge: ticket 02 (ย้ายคลาส) ก่อน ticket 04 (เปลี่ยน address) — patch note เกิดตอน ticket 04 ฉบับเดียว

## Resolution

(open — ยังไม่ได้ทำ)
