---
label: wayfinder:task
type: HITL
title: "Split BookingController — 2,393 บรรทัด / 14 public methods + 7 helpers / /bookings กระจาย 4 middleware blocks"
summary: ตัดสินแบ่ง BookingController เป็นหลาย controller ตาม audience จริง — move-only ไม่แตะ URL/response
status: open
assignee: kevii (orchestrator session 2026-11-11 — dispatch antigravity/gemini-3.8-flash @ .worktree/split-booking-controller)
blocked-by: []
---

# 01: Split BookingController

## Question

จะแบ่ง `app/Http/Controllers/Api/V1/BookingController.php` (2,393 บรรทัด, **14 public methods** — นับใหม่จากไฟล์จริง 2026-11-11, map เดิมนับ 16 ตกเกิน) เป็นกี่ controller ด้วยเส้นแบ่งอะไร ให้สะท้อน audience ของ route จริง (owner / admin / admin+staff) โดยไม่แตะ URL และไม่เปลี่ยนพฤติกรรม

## Verified inventory (2026-11-11 — นับจากไฟล์จริง)

### Public methods (14)

| Method | บรรทัด | Audience / route block |
|---|---|---|
| `getBookings` | :35 | owner (GET /bookings) |
| `setDiscountCode` | :179 | owner (PUT /bookings/{id}/discount-code) |
| `destroyDiscountCode` | :244 | owner (DELETE /bookings/{id}/discount-code) |
| `addRooms` | :311 | owner (POST /bookings/{bookingId}/rooms) |
| `destroyBooking` | :518 | owner (DELETE /bookings/{bookingId}) |
| `updateRoom` | :659 | owner (PUT /bookings/{bId}/rooms/{brId}) |
| `updateRooms` | :1037 | owner (PUT /bookings/{bookingId}/rooms — batch) |
| `destroyRoom` | :1318 | owner (DELETE .../rooms/{bookingRoomId}) |
| `createBooking` | :1422 | owner (POST /bookings) |
| `updateBookingPayment` | :1749 | **admin** (PUT /bookings/{id}) |
| `updateStatus` | :2052 | **admin** (PUT /bookings/update/{id} — ticket 03) |
| `statusLogs` | :2117 | **admin** (GET /bookings/{id}/status-logs) |
| `showById` | :2156 | owner (GET /bookings/{id}) |
| `autoAssignRooms` | :2208 | **admin+staff** (PUT /bookings/{bookingId}/assign-rooms) |

### Private helpers (7) + ที่มา

| Helper | บรรทัด | ถูกใช้โดย |
|---|---|---|
| `assertUpdatePaymentGroupGuards` | :1952 | `updateBookingPayment` เท่านั้น (admin) |
| `applyUserFilter` / `applyDateFilter` / `applyRoomTypeFilter` | :1963/:2023/:2041 | `getBookings` เท่านั้น (owner read) |
| `sellableCapacity` | :2356 | **4 write paths** (:383 addRooms · :821 updateRoom · :1178 updateRooms · :1554 createBooking) — chokepoint `holdingSlot()` ตาม AGENTS.md |
| `stripGuestFields` | :2328 | 4 write paths ทั้งหมด + showById — **duplicate อยู่ใน FrontDeskController :1320 แล้ว** |
| `reconcileDiscount` | :2371 | addRooms :453 · updateRoom :909 · updateRooms :1261 (แก้ draft ที่มีโค้ดส่วนลด) |

### Route blocks (routes/api.php — BookingController โผล่ 3 blocks + BookingConfirmation แยกคลาสแล้ว)

- Owner group (auth:sanctum เปล่า): :112–166 (10 เส้น) · confirm :152 เป็น `BookingConfirmationController` — ไม่เกี่ยวตั๋วนี้
- Admin group: :192–208 (`updateStatus` :196 · `updateBookingPayment` :202 · `statusLogs` :207)
- Admin+staff group: :262 (`autoAssignRooms`)
- นอกจากนี้ `assign-rooms` :262 **ไม่มี where-uuid** (nit rider — จดใน ticket 03)

## ⚠️ Coupling ที่ตัดสินเส้นแบ่ง

`sellableCapacity` + `stripGuestFields` + `reconcileDiscount` ถูกใช้ร่วมกันแน่นใน **cluster 4 write paths** (create/add/update-room/batch) — ถ้าแบ่ง cluster นี้ข้ามคลาส ต้องสร้าง trait/support ใหม่มาแชร์ = เพิ่ม surface ระหว่าง move-only (ผิด spirit ของ constraint) · การแบ่งที่ต่ำ risk สุดคือ**คง cluster นี้ไว้คลาสเดียว**

## ทางเลือก (รอ lock ตอน grilling)

### Option A — 3-way ตาม audience (แนะนำ)

1. **`BookingController`** (คงชื่อ ลด churn — owner lifecycle + read + discount-code): 10 methods + 6 helpers ≈ 2,080 บรรทัด — ยังใหญ่ แต่ cohesion สูง (audience เดียว + helpers ครบในคลาส ไม่ต้องแชร์ข้ามไฟล์)
2. **`BookingManagementController`** (admin): `updateStatus` · `updateBookingPayment` · `statusLogs` + `assertUpdatePaymentGroupGuards` ≈ 220 บรรทัด
3. `autoAssignRooms` — ปลายทาง 2 ทาง (decision point 2): รวมใน `BookingManagementController` (คลาสไม่ผูก middleware — route คง `role:admin,staff` ของตัวเอง) **หรือ** แยก `BookingAssignmentController` (จับคู่ RoomAllocator + AssignRooms tests)

### Option B — 4/5-way ตาม sub-domain

แยก `BookingRoomController` (updateRoom · updateRooms · destroyRoom ≈ 660 บรรทัด) ออกจาก BookingController — อ่านง่ายขึ้นตาชื่อ แต่ `sellableCapacity`/`stripGuestFields`/`reconcileDiscount` ต้องแชร์ผ่าน trait ใหม่ (เช่น `Concerns/InteractsWithBookingDraft`) — ผ่านได้ **เฉพาะเมื่อ owner ยอมรับ surface ใหม่นี้ระหว่าง move-only**

## Decision points (ต้อง lock ก่อนลงมือ)

1. **Option A หรือ B** — จำนวน + ชื่อ controller สุดท้าย (ชื่อใหม่ต้องไม่ชนความหมายกับ `BookingRoom` model / `BookingConfirmationController` ที่มีอยู่)
2. `autoAssignRooms` → management หรือ assignment แยก
3. ถ้าเลือก B: ชื่อ trait + scope ของ trait (ห้าม service-ize — move-only)
4. **แตะ `updateStatus` คู่ ticket 03 ไหม** — ถ้า split พร้อม unify route = branch เดียว merge รอบเดียว (แนะนำ — landing spot ของ ticket 03 คือ `BookingManagementController`)
5. Nit คู่จาก map Out-of-scope: field ตายใน `UpdateRoomRequest` — แก้ควบตอนแตะ controller เดียวกันได้ ถ้า owner อนุมัติในรอบ grilling

## Safety net (tests)

- `BookingTest` · `BookingTypeTest` · `BookingAdvanceNoticeTest` · `BookingAttachCustomerTest` · `OrgBookingTest` — lifecycle owner
- `BookingPaymentTypeTest` (:494–496 ยิงเส้น update) · `AssignRoomsIncludeReservedTest` (:165/:341) · `RouteProtectionTest` (:119 — probe `/bookings/update/nonexistent`, คง valid ถ้า ticket 01 ย้ายคลาสอย่างเดียว)
- `StatusChangeLogTest` (status-logs) · `BookingAutoAssignRoomsTest` + `AssignRoomsIncludeReservedTest` (assign-rooms)
- เป้าหมาย: **0 test edits** — เปลี่ยนแค่ FQCN + `use` imports ใน `routes/api.php`

## Acceptance criteria

- [ ] URL + HTTP verb + response shape + status code เดิมทุกเส้น — diff `routes/api.php` จำกัดที่ FQCN/import เท่านั้น
- [ ] suite 917 ผ่านครบโดยไม่แก้ไฟล์ test ใด (ถ้าจำเป็นต้องแก้ = ทบทวนว่าพังพฤติกรรมตรงไหนก่อน)
- [ ] `vendor/bin/pint --dirty` ผ่าน
- [ ] จด controller inventory ใหม่ใน `cline.md` + AGENTS.md (Architecture — Controllers bullet)
- [ ] ถ้าแตะ `updateStatus`: patch note ร่วมกับ ticket 03 ใน `docs/api_patches`

## Decision locked (owner — 2026-11-11, grilling-compressed ผ่าน orchestrator)

1. **Option A (3-way ตาม audience)** — `BookingController` (คงชื่อ — owner lifecycle + read + discount-code) + **`BookingManagementController`** (admin: `updateStatus` · `updateBookingPayment` · `statusLogs` + `assertUpdatePaymentGroupGuards`) + **`BookingAssignmentController`** (admin+staff: `autoAssignRooms` — จับคู่ RoomAllocator + AssignRooms tests)
2. `autoAssignRooms` → **แยก `BookingAssignmentController`** (ไม่รวมใน Management — สะท้อน audience admin+staff แยกจาก admin)
3. (ไม่เกี่ยว — ไม่ได้เลือก Option B จึงไม่มี trait)
4. **`updateStatus` move-only ย้ายคลาสเท่านั้น** — URL/verb/response คงเดิม · unify `PUT /bookings/update/{id}` = ticket 03 แยก branch + ประสาน frontend ตามเดิม (ไม่รวม branch เดียวกัน)
5. Nit field ตาย `UpdateRoomRequest` — คงเลื่อนไป ticket 03 ตาม map Out-of-scope

## Resolution

(open — ยังไม่ได้ทำ)
