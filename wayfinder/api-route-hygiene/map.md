---
label: wayfinder:map
title: "api-route-hygiene — ระเบียบเส้น API จาก scrutinize 2026-11-11"
status: open
---

# Wayfinder Map — API Route Hygiene (scrutinize 2026-11-11)

## Destination

แก้ "ความเละ + หน้าที่ทับซ้อน" ของ `routes/api.php` ที่ scrutinize รอบ 2026-11-11 พิสูจน์แล้ว — แบ่งเป็นสองชุด:

1. **Zero-risk package — ✅ ทำเสร็จแล้วในวันเดียวกัน** (รายละเอียดใน "Decisions so far")
2. **Debt tickets ที่เหลือ** — งาน breaking/ refactor ใหญ่ ต้อง lock decision + api_patches + ประสาน frontend ก่อนลงมือ (tickets 01–04)

## Notes

- **ที่มา:** scrutinize สั่งโดย owner หัวข้อ "ระเบียบเส้น api ค่อนข้างเละ และหน้าที่ทับซ้อนกันเยอะ" — trace ครบทุก overlap ที่การเงิน/สถานะพึ่งพา · route matching ถูกต้องทุกเส้น ไม่มี privilege hole (ปิดข้อสงสัย `GET /discounts/{code}` = dual behavior ตั้งใจ)
- **บริบท doc drift ที่แก้แล้ว:** AGENTS.md Room states 7→5 + discount placement + map ลิสต์ — ข้อความเก่าทำให้เข้าใจผิดว่า `PUT /rooms/{id}/status` ปิดขายห้องได้ (จริงคือ periods อย่างเดียว)
- **ธรรมเนียม breaking change:** ทุก ticket URL-level ต้องเขียน patch note ใน `docs/api_patches` + ประสาน frontend ตาม precedent tier-endpoints-v2

## Decisions so far

- [2026-11-11] **Webhook คง 410 ไว้** — `POST /payment/webhook` (public) เป็น contract รอ payment gateway (blocker #4 HMAC ยังค้าง) ลบไม่ได้เพราะ 404 สื่อสารแย่กว่า 410
- [2026-11-11] **ลบ `POST /payments` + `PaymentController::requestPayment()`** — 410 tombstone (FROZEN 2026-11-03) ที่คอมเมนต์ "สร้างรายการชำระ" หลอกผู้อ่าน · tests ไม่มีใครอ้าง (ยิงแต่ webhook)
- [2026-11-11] **ยุบ front-desk 3 กลุ่ม → 1 กลุ่ม `role:admin,staff`** — zero-URL-change · walk-in/mark-no-show (admin-only) ใช้ `role:admin` รายเส้น (CheckRole ตรวจต่อ layer พฤติกรรมเดิมเป๊ะ) · evidence ย้ายเข้ากลุ่มเดียวกัน
- [2026-11-11] **ปิด 500 leak 2 จุด** — `BookingController::updateStatus` + `RoomController::updateRoomStatus` relay message เฉพาะ 4xx domain error, 5xx → generic + Log::error (ตามคอนเวนชัน AGENTS.md)
- [2026-11-11] **แก้คอมเมนต์เท็จ** — ลำดับ route `PUT /bookings/{id}` กับ `/bookings/update/{id}` ไม่มีผลต่อการจับคู่ (literal vs where-uuid ไม่ชนกันอยู่แล้ว)
- [2026-11-11] **F2 (unify update route) + F3-detail (evidence addressing) เลื่อน** — breaking กับ frontend → กลายเป็น tickets 03/04 ของ map นี้
- [2026-11-11] **Cross-check กับ reverse index ฝั่ง frontend (api_pages README, snapshot 2026-11-06)** — ยืนยันมติทั้ง 6 ข้อ ไม่มีอะไรต้องกลับคำ: `POST /payments` **ไม่มี caller เลย** (ลบแล้วปลอดภัย 100%) · `PUT /bookings/update/{id}` caller = `admin-confirm-bookings` **จุดเดียว** (ticket 03 ถูกลง) · **evidence endpoint ไม่ปรากฏใน reverse index** → ticket 04 อาจไม่ต้องประสาน frontend (รอ grep ยืนยัน) · `POST /front-desk/{id}/payment` frontend เลิกเรียกตั้งแต่ 06/11 (Collect ย้ายไป `POST /bookings/{id}/confirm` deferred) — คงเส้นไว้เป็น contract เงินสด/charges · ⚠️ cross-repo trap: frontend `housekeeping.ts` เตรียม `GET /dashboard/cleaning-tasks` ซึ่ง backend **ไม่มี** (backend = `/dashboard/tasks`) — จะ 404 เมื่อหน้า Housekeeping เลิกใช้ mock · 🚧 frontend 5 หน้ายังยิง `/mock/availability-ranges` (prod-gated OFF — เส้นจริง `/availability-ranges` รออยู่แล้ว ตาม DELETION PLAN ของ MockController)

## Tickets

- [01-split-booking-controller](tickets/01-split-booking-controller.md) — BookingController 2,393 บรรทัด / 14 public methods + 7 helpers (นับใหม่ 2026-11-11) · `/bookings` กระจาย 4 middleware blocks · ⚠️ coupling: `sellableCapacity`/`stripGuestFields`/`reconcileDiscount` ร่วมกันใน 4 write paths
- [02-split-front-desk-controller](tickets/02-split-front-desk-controller.md) — FrontDeskController 1,500 บรรทัด / 9 methods ครอบ 3 domain · ⚠️ `handleCatch` แชร์ครบ 3 domain (splitUpgrade ~423 บรรทัด method ใหญ่สุด)
- [03-unify-booking-update-route](tickets/03-unify-booking-update-route.md) — `PUT /bookings/update/{id}` verb-in-path ไม่มี where-uuid/throttle (breaking) · caller จุดเดียว + ⚠️ RouteProtectionTest:119 ยิง non-uuid probe (พังถ้าใส่ where-uuid)
- [04-unify-evidence-addressing](tickets/04-unify-evidence-addressing.md) — `/front-desk/booking-rooms/{id}/evidence` ต่างแบบพี่น้อง nested (breaking) · ต้อง grep frontend ยืนยัน caller ก่อนเสมอ

## Out of scope

- จัดเรียง route file ทั้งไฟล์เพื่อ "ความสวย" ล้วน — scrutinize ยืนยันแล้วว่า cosmetic เปล่าประโยชน์
- เพิ่ม throttle บนเส้น admin (เปลี่ยนพฤติกรรม — ต้องมติแยก)
- เก็บ field ตายใน `UpdateRoomRequest` (room_type_id/room_number/builtin_extra_beds ไม่ถูกใช้) — nit ทิ้งไว้ จับคู่ตอนทำ ticket 03 ถ้าแตะ controller เดียวกัน
