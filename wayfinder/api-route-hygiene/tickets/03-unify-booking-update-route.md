---
label: wayfinder:task
type: HITL
title: "Unify PUT /bookings/update/{id} → REST shape (breaking — ต้อง api_patches)"
summary: รวมเส้น verb-in-path PUT /bookings/update/{id} เป็น PUT /bookings/{id}/status — breaking 1 call site + patch note
status: open
assignee:
blocked-by: []
---

# 03: Unify booking update route

## Question

จะรวม `PUT /bookings/update/{id}` (verb-in-path) กับ `PUT /bookings/{id}` ให้อยู่ convention เดียวได้อย่างไร — เส้นเดิมไม่มี where-uuid, ไม่มี throttle และคอมเมนต์ลำดับเดิมใน route file อธิบายกลไกผิด (แก้คอมเมนต์แล้ว 2026-11-11)

## Verified inventory (2026-11-11 — จากไฟล์จริง)

### Route ปัจจุบัน

- `PUT /bookings/update/{id}` → `updateStatus` — **routes/api.php:196** (กลุ่ม `role:admin`) — ไม่มี where-uuid, ไม่มี throttle
- `PUT /bookings/{id}` → `updateBookingPayment` — :202 (where-uuid + `throttle:5,1`) — หน้าที่ต่างกัน (payment fields ของ draft) ถูกต้องอยู่แล้ว แค่รูปเส้นไม่เป็นระเบียบ
- `GET /bookings/{id}/status-logs` → `statusLogs` — :207 (มี where-uuid แล้ว — ตัวอย่าง shape ที่ถูก convention ของกลุ่มเดียวกัน)
- literal segment `update` กับ `{id}` + where-uuid **ไม่มีทางจับคู่ชนกัน** — ลำดับ route ไม่มีผล (แก้คอมเมนต์เท็จแล้ว)

### Method ปัจจุบัน

- `updateStatus` (`BookingController.php:2052–2116`) — รับ `status` ∈ `draft,paid,confirmed,complete` มือเปล่า (`Request` ไม่ใช่ FormRequest) · relay domain error 4xx เท่านั้น (แก้ 500-leak แล้ว 2026-11-11)
- `updateBookingPayment` (:1749–1951) + guard helper `assertUpdatePaymentGroupGuards` (:1952) — แยกอยู่แล้ว ไม่กระทบตั๋วนี้

### Caller ฝั่ง frontend (จุดตายของต้นทุนประสาน)

- **จุดเดียว** = หน้า `admin-confirm-bookings` (reverse index ฝั่ง frontend — api_pages README, snapshot 2026-11-06 — **ต้อง re-check อีกครั้งตอนถึงคิว เพราะ doc อายุ**)
- `PUT /bookings/{id}` ถูกเรียงจากคนละหน้า (`admin-booking-wizard`) — สองหน้าไม่แชร์ helper → 1 call site + 1 patch note = ต้นทุนต่ำสุด

### Tests ที่ยิงเส้นนี้ (ต้องแก้เมื่อ URL เปลี่ยน — อนุญาตเพราะ URL-level change)

- `BookingTest` :355/:370 · `BookingPaymentTypeTest` :494–496 (flow "อนุมัติก่อนแบบไม่แนบสลิป") · `AssignRoomsIncludeReservedTest` :165/:341
- ⚠️ **กับดัก:** `RouteProtectionTest` :119 ยิง `PUT /bookings/update/nonexistent` (**non-uuid probe**) — พึ่งพาว่าเส้นไม่มี where-uuid · เมื่อเส้นใหม่ใส่ where-uuid id ที่ไม่ใช่ uuid จะโดน router 404 **ก่อนถึง controller** — assertion/response body เปลี่ยน ต้องอัปเดต probe นี้พร้อมกัน

## ทางเลือก (รอ lock ตอน grilling)

### (a) ย้ายเป็น `PUT /bookings/{id}/status` + ถอดเส้นเก่า 1 รอบ breaking (แนะนำ)

- REST subresource มาตรฐาน — เข้าชุดเดียวกับ `GET /bookings/{id}/status-logs` (อ่าน route file แล้วเดา address ถูก) · ใส่ where-uuid ครบ
- ไม่ชน `PUT /bookings/{id}` (payment) — subpath `status` กับ resource root คนละรูป
- ต้นทุน breaking ต่ำสุด: 1 call site + 1 patch note — ผ่านครั้งเดียวจบ ไม่ทิ้งหนี้

### (b) เพิ่มเส้นใหม่คู่ขนาน + deprecate ทีหลัง

- เส้นเก่าค้างอีกเส้น = สวนทางเป้า hygiene ของ map นี้โดยตรง — แนะนำ**เฉพาะกรณี** frontend ประสานล่าช้าจนต้องปล่อย backend ก่อน (แล้วตั้ง deprecation date ชัดเจนใน patch note)

## Decision points (ต้อง lock ก่อนลงมือ)

1. **(a) หรือ (b)** — ถ้า (a): ใส่ where-uuid ให้เส้นใหม่ (แนะนำใส่) · **throttle คงไม่ใส่** ให้ตรงพฤติกรรมเดิม (เพิ่ม throttle = มติแยก ตาม constraint + map Out-of-scope)
2. **Error matrix ของเส้นใหม่** ลง patch note: non-uuid id → router 404 (เปลี่ยนจากเดิม controller 404 — body ต่าง) · id ไม่พบ → 404 · status ไม่ valid / transition ไม่อนุญาต → 422 · ไม่ admin → 403
3. `updateStatus` รับ `Request` มือเปล่า — **คงมือเปล่า** (move-pure) หรือ formalize เป็น FormRequest (แนะนำคง — ถ้าอยาก formalize ให้แยกงาน + error matrix ใหม่ ไม่คลุก URL change)
4. **จับคู่ ticket 01** — landing spot คือ `BookingManagementController` ถ้า split ไปด้วยกัน = branch เดียว merge รอบเดียว (แนะนำ)
5. 🪶 **Rider (ถ้าแตะ route file อยู่แล้ว):** `PUT /bookings/{bookingId}/assign-rooms` (:262) ก็ไม่มี where-uuid — ใส่ควบได้ zero-URL-change แต่ non-uuid → router 404 body เปลี่ยน ต้องตรวจ response-parity + จดใน patch note จุดเดียวกัน (ไม่ใช่ decision ใหญ่ — อนุมัติรวมในรอบ grilling ได้)

## Constraints

- ตามธรรมเนียม tier-endpoints-v2: patch note ใน `docs/api_patches` + error matrix + ประสาน frontend ก่อน merge (สลับเส้นใน `admin-confirm-bookings` ให้พร้อมกัน)
- ถ้าเพิ่ม throttle ใหม่ต้องเป็นมติแยก (เดิมเส้นนี้ไม่มี)
- จับคู่ ticket 01 ถ้า split controller ไปด้วยกันได้ (merge รอบเดียว)

## Acceptance criteria

- [ ] patch note ใน `docs/api_patches` — breaking (URL เปลี่ยน) + error matrix + migration guide สำหรับ 1 call site
- [ ] เส้นเก่า `PUT /bookings/update/{id}` ถอดออก (ถ้าเลือก a) — ไม่ทิ้ง tombstone
- [ ] tests อัปเดต 4 ไฟล์ (BookingTest / BookingPaymentTypeTest / AssignRoomsIncludeReservedTest / RouteProtectionTest probe)
- [ ] frontend `admin-confirm-bookings` สลับเส้นแล้ว (ยืนยัน reverse index ใหม่ ณ วันลงมือ)
- [ ] suite 917 ผ่านครบ · `vendor/bin/pint --dirty` ผ่าน · จด `cline.md` + AGENTS.md

## Resolution

(open — ยังไม่ได้ทำ)
